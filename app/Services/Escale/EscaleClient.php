<?php

declare(strict_types=1);

namespace App\Services\Escale;

use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

/**
 * Cliente de la API pública de MINEDU / ESCALE.
 *
 * Encapsula tres particularidades de la API: puede fallar de forma transitoria
 * (reintentos con espera), devuelve un objeto en lugar de una lista cuando hay
 * un solo elemento, y pagina las instituciones de 50 en 50 mediante `start`.
 */
class EscaleClient
{
    /** Tamaño de página fijo del endpoint /instituciones. */
    public const PAGE_SIZE = 50;

    /** Tope de seguridad de páginas por distrito, para evitar bucles infinitos. */
    private const MAX_PAGES = 1000;

    public function __construct(
        private readonly string $baseUrl,
        private readonly int $retries = 3,
        private readonly int $retryDelayMs = 500,
        private readonly int $timeout = 30,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function regions(): array
    {
        return $this->items('regiones', [], 'region');
    }

    /** @return list<array<string, mixed>> */
    public function provinces(string $regionId): array
    {
        return $this->items('provincias', ['idRegion' => $regionId], 'provincia');
    }

    /** @return list<array<string, mixed>> */
    public function districts(string $provinceId): array
    {
        return $this->items('distritos', ['idProvincia' => $provinceId], 'distrito');
    }

    /**
     * Devuelve los colegios activos de un distrito, página a página.
     *
     * @return Generator<int, list<array<string, mixed>>>
     */
    public function schoolPages(string $districtId): Generator
    {
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $items = $this->items('instituciones', [
                'estados' => 1,
                'ubigeo' => $districtId,
                'start' => $page * self::PAGE_SIZE,
            ], 'items');

            if ($items === []) {
                return;
            }

            yield $items;

            if (count($items) < self::PAGE_SIZE) {
                return;
            }
        }
    }

    /**
     * @param  array<string, scalar>  $query
     * @return list<array<string, mixed>>
     */
    private function items(string $endpoint, array $query, string $key): array
    {
        $data = $this->request($endpoint, $query)[$key] ?? [];

        if (! is_array($data) || $data === []) {
            return [];
        }

        // Un solo resultado llega como objeto, no como lista.
        return Arr::isList($data) ? $data : [$data];
    }

    /**
     * @param  array<string, scalar>  $query
     * @return array<string, mixed>
     */
    private function request(string $endpoint, array $query): array
    {
        $url = rtrim($this->baseUrl, '/').'/'.$endpoint;

        try {
            $response = Http::acceptJson()
                ->timeout($this->timeout)
                ->retry($this->retries, $this->retryDelayMs, throw: false)
                ->get($url, $query);
        } catch (ConnectionException $e) {
            throw new EscaleException("No se pudo conectar con ESCALE ({$endpoint}): {$e->getMessage()}", 0, $e);
        }

        if ($response->failed()) {
            throw new EscaleException("ESCALE respondió {$response->status()} en {$endpoint}.");
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }
}
