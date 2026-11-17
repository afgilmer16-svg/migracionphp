<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Escale\EscaleClient;
use App\Services\Escale\EscaleException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EscaleClientTest extends TestCase
{
    private function client(int $retries = 3): EscaleClient
    {
        return new EscaleClient('https://escale.test/rest', $retries, 0, 5);
    }

    /** @return list<array<string, string>> */
    private function schools(int $count, int $from = 1): array
    {
        return array_map(
            fn (int $n): array => ['codMod' => sprintf('%07d', $n), 'cenEdu' => "Colegio {$n}"],
            range($from, $from + $count - 1)
        );
    }

    public function test_un_solo_resultado_se_normaliza_a_lista(): void
    {
        Http::fake(['escale.test/rest/provincias*' => Http::response(['provincia' => ['idProvincia' => '0101']])]);

        $this->assertSame([['idProvincia' => '0101']], $this->client()->provinces('01'));
    }

    public function test_una_lista_se_devuelve_tal_cual(): void
    {
        Http::fake(['escale.test/rest/distritos*' => Http::response(['distrito' => [
            ['idDistrito' => '010101'], ['idDistrito' => '010102'],
        ]])]);

        $this->assertCount(2, $this->client()->districts('0101'));
    }

    public function test_una_respuesta_sin_clave_devuelve_lista_vacia(): void
    {
        Http::fake(['escale.test/rest/regiones*' => Http::response([])]);

        $this->assertSame([], $this->client()->regions());
    }

    public function test_reintenta_ante_errores_transitorios(): void
    {
        Http::fake(['escale.test/rest/regiones*' => Http::sequence()
            ->pushStatus(503)
            ->pushStatus(500)
            ->push(['region' => [['idRegion' => '15']]]),
        ]);

        $this->assertSame([['idRegion' => '15']], $this->client()->regions());
        Http::assertSentCount(3);
    }

    public function test_lanza_excepcion_cuando_se_agotan_los_reintentos(): void
    {
        Http::fake(['escale.test/rest/regiones*' => Http::response('error', 500)]);

        $this->expectException(EscaleException::class);

        $this->client(2)->regions();
    }

    public function test_pagina_los_colegios_de_50_en_50_sin_tope(): void
    {
        Http::fake(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return match ((int) $query['start']) {
                0 => Http::response(['items' => $this->schools(50, 1)]),
                50 => Http::response(['items' => $this->schools(50, 51)]),
                100 => Http::response(['items' => $this->schools(7, 101)]),
                default => Http::response([]),
            };
        });

        $total = 0;
        foreach ($this->client()->schoolPages('150101') as $page) {
            $total += count($page);
        }

        $this->assertSame(107, $total, 'Se superó el antiguo tope de 50 colegios.');
        Http::assertSentCount(3);
    }

    public function test_pagina_exacta_de_50_pide_la_siguiente_y_termina_al_estar_vacia(): void
    {
        Http::fake(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return (int) $query['start'] === 0
                ? Http::response(['items' => $this->schools(50)])
                : Http::response([]);
        });

        $pages = iterator_to_array($this->client()->schoolPages('150101'), false);

        $this->assertCount(1, $pages);
        Http::assertSentCount(2);
    }

    public function test_un_distrito_con_un_solo_colegio_llega_como_objeto(): void
    {
        Http::fake(['escale.test/rest/instituciones*' => Http::response(['items' => ['codMod' => '0000001']])]);

        $pages = iterator_to_array($this->client()->schoolPages('150101'), false);

        $this->assertSame([[['codMod' => '0000001']]], $pages);
    }
}
