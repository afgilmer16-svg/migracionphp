<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\District;
use App\Models\Province;
use App\Models\Region;
use App\Models\School;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;

class LocationController extends Controller
{
    /** Lista todas las regiones. */
    public function getRegions(): JsonResponse
    {
        return $this->success(Region::query()->orderBy('idRegion')->get());
    }

    /** Provincias de una región: el código de provincia empieza por el de la región (2 dígitos). */
    public function getProvinces(string $idRegion): JsonResponse
    {
        $provinces = Province::query()
            ->where('idProvincia', 'like', $idRegion.'%')
            ->orderBy('idProvincia')
            ->get();

        return $this->respond($provinces, 'No se encontraron provincias para esta región.');
    }

    /** Distritos de una provincia: el código de distrito empieza por el de la provincia (4 dígitos). */
    public function getDistricts(string $idProvince): JsonResponse
    {
        $districts = District::query()
            ->where('idDistrito', 'like', $idProvince.'%')
            ->orderBy('idDistrito')
            ->get();

        return $this->respond($districts, 'No se encontraron distritos para esta provincia.');
    }

    /** Colegios de un distrito (ubigeo de 6 dígitos). */
    public function getSchools(string $ubigeo): JsonResponse
    {
        $schools = School::query()
            ->where('idDistrito', $ubigeo)
            ->orderBy('codMod')
            ->get();

        return $this->respond($schools, 'No se encontraron escuelas para este distrito.');
    }

    private function respond(Collection $items, string $notFoundMessage): JsonResponse
    {
        if ($items->isEmpty()) {
            return response()->json(['status' => 'error', 'message' => $notFoundMessage], 404);
        }

        return $this->success($items);
    }

    private function success(Collection $items): JsonResponse
    {
        return response()->json(['status' => 'success', 'data' => $items]);
    }
}
