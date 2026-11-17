<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\District;
use App\Models\Province;
use App\Models\Region;
use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Prueba del comando sync:locations contra una API simulada. */
class SyncLocationsTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<array<string, string>> */
    private function schools(int $count): array
    {
        return array_map(
            fn (int $n): array => ['codMod' => sprintf('%07d', $n), 'cenEdu' => "Colegio {$n}", 'cenPob' => 'LIMA', 'dirCen' => 'Jr. Lampa'],
            range(1, $count)
        );
    }

    private function fakeApi(int $schoolCount = 3): void
    {
        Http::fake(function (Request $request) use ($schoolCount) {
            $url = $request->url();
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            return match (true) {
                str_contains($url, '/regiones') => Http::response(['region' => [
                    ['idRegion' => '15', 'nombreRegion' => 'LIMA', 'pointX' => -12.04, 'pointY' => -77.03, 'zoom' => 8],
                    ['idRegion' => '01', 'nombreRegion' => 'AMAZONAS', 'pointX' => -6.2, 'pointY' => -77.8, 'zoom' => 8],
                ]]),
                // Un solo resultado llega como objeto, no como lista.
                str_contains($url, '/provincias') => Http::response(['provincia' => [
                    'idProvincia' => $query['idRegion'].'01', 'nombreProvincia' => 'CAPITAL', 'pointX' => -1.0, 'pointY' => -2.0,
                ]]),
                str_contains($url, '/distritos') => Http::response(['distrito' => [
                    ['idDistrito' => $query['idProvincia'].'01', 'nombreDistrito' => 'CENTRO'],
                    ['idDistrito' => $query['idProvincia'].'02', 'nombreDistrito' => 'NORTE'],
                ]]),
                str_contains($url, '/instituciones') => Http::response(
                    ['items' => (int) $query['start'] === 0 ? $this->schools($schoolCount) : []]
                ),
                default => Http::response([], 404),
            };
        });
    }

    public function test_sincroniza_toda_la_jerarquia(): void
    {
        $this->fakeApi();

        $this->artisan('sync:locations')
            ->expectsOutputToContain('Sincronización completa.')
            ->assertExitCode(0);

        $this->assertSame(2, Region::count());
        $this->assertSame(2, Province::count());
        $this->assertSame(4, District::count());
        // Los mismos 3 códigos de colegio se repiten en cada distrito: upsert por codMod.
        $this->assertSame(3, School::count());
        $this->assertSame('LIMA', Region::find('15')->nombreRegion);
        $this->assertSame('Colegio 1', School::find('0000001')->nombreCenEdu);
    }

    public function test_es_idempotente(): void
    {
        $this->fakeApi();

        $this->artisan('sync:locations')->assertExitCode(0);
        $this->artisan('sync:locations')->assertExitCode(0);

        $this->assertSame(2, Region::count());
        $this->assertSame(3, School::count());
    }

    public function test_la_opcion_region_limita_la_sincronizacion(): void
    {
        $this->fakeApi();

        $this->artisan('sync:locations --region=15')->assertExitCode(0);

        $this->assertSame(['15'], Region::pluck('idRegion')->all());
    }

    public function test_guarda_mas_de_50_colegios_por_distrito(): void
    {
        $this->fakeApi(schoolCount: 50);

        // Con 50 devueltos en la primera página se pide la segunda (vacía) y se termina.
        $this->artisan('sync:locations --region=15')->assertExitCode(0);

        $this->assertSame(50, School::count());
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'start=50'));
    }

    public function test_si_falla_la_api_de_regiones_devuelve_error(): void
    {
        Http::fake(['escale.test/rest/regiones*' => Http::response('caído', 503)]);

        $this->artisan('sync:locations')
            ->expectsOutputToContain('Error al obtener regiones')
            ->assertExitCode(1);

        $this->assertSame(0, Region::count());
    }

    public function test_un_fallo_parcial_no_detiene_el_resto_y_devuelve_error(): void
    {
        Http::fake([
            'escale.test/rest/regiones*' => Http::response(['region' => [
                ['idRegion' => '15', 'nombreRegion' => 'LIMA'],
                ['idRegion' => '01', 'nombreRegion' => 'AMAZONAS'],
            ]]),
            'escale.test/rest/provincias*' => fn (Request $r) => str_contains($r->url(), 'idRegion=15')
                ? Http::response('error', 500)
                : Http::response(['provincia' => ['idProvincia' => '0101', 'nombreProvincia' => 'CHACHAPOYAS']]),
            'escale.test/rest/distritos*' => Http::response(['distrito' => ['idDistrito' => '010101', 'nombreDistrito' => 'X']]),
            'escale.test/rest/instituciones*' => Http::response([]),
        ]);

        $this->artisan('sync:locations')
            ->expectsOutputToContain('Error en provincias de la región 15')
            ->assertExitCode(1);

        $this->assertSame(2, Region::count());
        $this->assertSame(1, Province::count(), 'La región 01 se sincronizó pese al fallo de la 15.');
    }
}
