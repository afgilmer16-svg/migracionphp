<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\District;
use App\Models\Province;
use App\Models\Region;
use App\Models\School;
use App\Services\Escale\EscaleClient;
use App\Services\Escale\EscaleException;
use Illuminate\Console\Command;

class SyncLocations extends Command
{
    protected $signature = 'sync:locations {--region= : Sincroniza solo esta región (código de 2 dígitos)}';

    protected $description = 'Sincroniza regiones, provincias, distritos y colegios desde la API de ESCALE';

    private int $failures = 0;

    public function handle(EscaleClient $escale): int
    {
        $this->info('Iniciando sincronización de ubicaciones...');

        try {
            $regions = $escale->regions();
        } catch (EscaleException $e) {
            $this->error('Error al obtener regiones: '.$e->getMessage());

            return self::FAILURE;
        }

        $only = $this->option('region');

        foreach ($regions as $region) {
            if (is_string($only) && $only !== '' && (string) $region['idRegion'] !== $only) {
                continue;
            }

            $this->syncRegion($escale, $region);
        }

        if ($this->failures > 0) {
            $this->warn("Sincronización terminada con {$this->failures} error(es); vuelve a ejecutarla para completar.");

            return self::FAILURE;
        }

        $this->info('Sincronización completa.');

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $region */
    private function syncRegion(EscaleClient $escale, array $region): void
    {
        $id = (string) $region['idRegion'];
        $this->info("Región {$id}: ".($region['nombreRegion'] ?? ''));

        Region::upsert([[
            'idRegion' => $id,
            'nombreRegion' => $region['nombreRegion'] ?? null,
            'pointX' => $region['pointX'] ?? null,
            'pointY' => $region['pointY'] ?? null,
            'zoom' => $region['zoom'] ?? null,
        ]], ['idRegion'], ['nombreRegion', 'pointX', 'pointY', 'zoom']);

        $this->guard("provincias de la región {$id}", function () use ($escale, $id): void {
            foreach ($escale->provinces($id) as $province) {
                $this->syncProvince($escale, $province);
            }
        });
    }

    /** @param array<string, mixed> $province */
    private function syncProvince(EscaleClient $escale, array $province): void
    {
        $id = (string) $province['idProvincia'];

        Province::upsert([[
            'idProvincia' => $id,
            'nombreProvincia' => $province['nombreProvincia'] ?? null,
            'pointX' => $province['pointX'] ?? null,
            'pointY' => $province['pointY'] ?? null,
        ]], ['idProvincia'], ['nombreProvincia', 'pointX', 'pointY']);

        $this->guard("distritos de la provincia {$id}", function () use ($escale, $id): void {
            foreach ($escale->districts($id) as $district) {
                $this->syncDistrict($escale, $district);
            }
        });
    }

    /** @param array<string, mixed> $district */
    private function syncDistrict(EscaleClient $escale, array $district): void
    {
        $id = (string) $district['idDistrito'];

        District::upsert([[
            'idDistrito' => $id,
            'nombreDistrito' => $district['nombreDistrito'] ?? null,
        ]], ['idDistrito'], ['nombreDistrito']);

        $total = 0;

        $this->guard("colegios del distrito {$id}", function () use ($escale, $id, &$total): void {
            foreach ($escale->schoolPages($id) as $page) {
                $rows = array_map(fn (array $school): array => [
                    'codMod' => (string) $school['codMod'],
                    'idDistrito' => $id,
                    'nombreCenEdu' => $school['cenEdu'] ?? null,
                    'cenPob' => $school['cenPob'] ?? null,
                    'dirCen' => $school['dirCen'] ?? null,
                    'director' => $school['director'] ?? null,
                    'dis_crecer' => $school['dis_crecer'] ?? null,
                    'dis_juntos' => $school['dis_juntos'] ?? null,
                ], $page);

                School::upsert(
                    $rows,
                    ['codMod'],
                    ['idDistrito', 'nombreCenEdu', 'cenPob', 'dirCen', 'director', 'dis_crecer', 'dis_juntos']
                );

                $total += count($rows);
            }
        });

        $this->line("  Distrito {$id}: {$total} colegios.");
    }

    /** Ejecuta un paso; si la API falla lo registra y continúa con el siguiente. */
    private function guard(string $what, callable $step): void
    {
        try {
            $step();
        } catch (EscaleException $e) {
            $this->failures++;
            $this->error("Error en {$what}: ".$e->getMessage());
        }
    }
}
