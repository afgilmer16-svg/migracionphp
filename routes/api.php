<?php

declare(strict_types=1);

use App\Http\Controllers\LocationController;
use Illuminate\Support\Facades\Route;

/*
| Rutas de la API (prefijo /api, middleware "api" con límite de peticiones).
| Los parámetros se restringen por formato de ubigeo: un valor inválido
| responde 404 sin llegar a consultar la base de datos.
*/
Route::get('/regiones', [LocationController::class, 'getRegions']);
Route::get('/provincias/{idRegion}', [LocationController::class, 'getProvinces'])->where('idRegion', '[0-9]{2}');
Route::get('/distritos/{idProvince}', [LocationController::class, 'getDistricts'])->where('idProvince', '[0-9]{4}');
Route::get('/colegios/{ubigeo}', [LocationController::class, 'getSchools'])->where('ubigeo', '[0-9]{6}');
