<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\District;
use App\Models\Province;
use App\Models\Region;
use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Pruebas de los endpoints de ubicación (SQLite en memoria; no requieren MySQL). */
class LocationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Region::create(['idRegion' => '15', 'nombreRegion' => 'Lima', 'pointX' => -12.04, 'pointY' => -77.03, 'zoom' => 8]);
        Region::create(['idRegion' => '01', 'nombreRegion' => 'Amazonas']);
        Province::create(['idProvincia' => '1502', 'nombreProvincia' => 'Barranca']);
        Province::create(['idProvincia' => '1501', 'nombreProvincia' => 'Lima']);
        Province::create(['idProvincia' => '0101', 'nombreProvincia' => 'Chachapoyas']);
        District::create(['idDistrito' => '150102', 'nombreDistrito' => 'Ancón']);
        District::create(['idDistrito' => '150101', 'nombreDistrito' => 'Lima']);
        District::create(['idDistrito' => '150201', 'nombreDistrito' => 'Barranca']);
        School::create(['codMod' => '0000002', 'idDistrito' => '150101', 'nombreCenEdu' => 'Colegio B']);
        School::create(['codMod' => '0000001', 'idDistrito' => '150101', 'nombreCenEdu' => 'Colegio A']);
        School::create(['codMod' => '0000003', 'idDistrito' => '150102', 'nombreCenEdu' => 'Colegio C']);
    }

    public function test_regiones_devuelve_el_listado_ordenado_y_con_tipos(): void
    {
        $response = $this->getJson('/api/regiones')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.idRegion', '01')
            ->assertJsonPath('data.1.idRegion', '15');

        $this->assertSame(8, $response->json('data.1.zoom'));
        $this->assertIsFloat($response->json('data.1.pointX'));
    }

    public function test_provincias_solo_incluye_las_de_la_region_y_ordenadas(): void
    {
        $this->getJson('/api/provincias/15')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.idProvincia', '1501')
            ->assertJsonPath('data.1.idProvincia', '1502');
    }

    public function test_provincias_de_una_region_sin_datos_devuelve_404_json(): void
    {
        $this->getJson('/api/provincias/99')
            ->assertNotFound()
            ->assertJsonPath('status', 'error');
    }

    public function test_distritos_solo_incluye_los_de_la_provincia(): void
    {
        $this->getJson('/api/distritos/1501')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.idDistrito', '150101');
    }

    public function test_distritos_de_una_provincia_sin_datos_devuelve_404(): void
    {
        $this->getJson('/api/distritos/9999')->assertNotFound()->assertJsonPath('status', 'error');
    }

    public function test_colegios_de_un_distrito_ordenados_por_codigo(): void
    {
        $this->getJson('/api/colegios/150101')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.codMod', '0000001');
    }

    public function test_colegios_de_un_distrito_sin_datos_devuelve_404(): void
    {
        $this->getJson('/api/colegios/999999')->assertNotFound()->assertJsonPath('status', 'error');
    }

    #[DataProvider('rutasConParametroInvalido')]
    public function test_parametros_con_formato_invalido_devuelven_404_json(string $url): void
    {
        $this->getJson($url)->assertNotFound();
    }

    /** @return array<string, array{string}> */
    public static function rutasConParametroInvalido(): array
    {
        return [
            'región con letras' => ['/api/provincias/ab'],
            'región de 3 dígitos' => ['/api/provincias/150'],
            'provincia con comodín' => ['/api/distritos/15%25'],
            'distrito corto' => ['/api/colegios/1501'],
            'intento de inyección' => ['/api/colegios/1%27%20OR%201=1'],
        ];
    }

    public function test_la_api_limita_las_peticiones(): void
    {
        $response = $this->getJson('/api/regiones');

        $response->assertOk()->assertHeader('X-RateLimit-Limit', '60');
    }
}
