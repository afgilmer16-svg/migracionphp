<?php

namespace Tests\Feature;

use App\Models\District;
use App\Models\Province;
use App\Models\Region;
use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pruebas de humo de los endpoints de ubicación.
 *
 * Los controladores usan LEFT() (MySQL); SQLite no lo trae, así que se
 * registra aquí con la misma semántica sin tocar el código de la aplicación.
 */
class LocationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::connection()->getPdo()->sqliteCreateFunction(
            'LEFT',
            fn ($value, $length) => mb_substr((string) $value, 0, (int) $length),
            2
        );

        Region::create(['idRegion' => '15', 'nombreRegion' => 'Lima', 'pointX' => -12.04, 'pointY' => -77.03, 'zoom' => 8]);
        Province::create(['idProvincia' => '1501', 'nombreProvincia' => 'Lima']);
        District::create(['idDistrito' => '150101', 'nombreDistrito' => 'Lima']);
        School::create(['codMod' => '0000001', 'idDistrito' => '150101', 'nombreCenEdu' => 'Colegio de prueba']);
    }

    public function test_regiones_devuelve_el_listado(): void
    {
        $this->getJson('/api/regiones')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.0.idRegion', '15');
    }

    public function test_provincias_de_una_region(): void
    {
        $this->getJson('/api/provincias/15')
            ->assertOk()
            ->assertJsonPath('data.0.idProvincia', '1501');
    }

    public function test_provincias_de_una_region_inexistente_devuelve_404(): void
    {
        $this->getJson('/api/provincias/99')
            ->assertNotFound()
            ->assertJsonPath('status', 'error');
    }

    public function test_distritos_de_una_provincia(): void
    {
        $this->getJson('/api/distritos/1501')
            ->assertOk()
            ->assertJsonPath('data.0.idDistrito', '150101');
    }

    public function test_distritos_de_una_provincia_inexistente_devuelve_404(): void
    {
        $this->getJson('/api/distritos/9999')->assertNotFound();
    }

    public function test_colegios_de_un_distrito(): void
    {
        $this->getJson('/api/colegios/150101')
            ->assertOk()
            ->assertJsonPath('data.0.codMod', '0000001');
    }

    public function test_colegios_de_un_distrito_inexistente_devuelve_404(): void
    {
        $this->getJson('/api/colegios/999999')->assertNotFound();
    }
}
