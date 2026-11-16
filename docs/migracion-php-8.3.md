# Plan de migración a PHP 8.3 — brigesnote

Rama de trabajo: `migracion/php-8.3` · Etiqueta de respaldo: `pre-php-8.3` (commit `e947779`)
Fecha: 16/11/2026 · Estado: **PLAN — pendiente de aprobación (nada de esto se ha ejecutado)**

## 1. Estado actual y objetivo

| Aspecto | Actual | Objetivo |
|---|---|---|
| PHP (`composer.json`) | `^7.2.5\|^8.0`, sin `config.platform` | `^8.3` + `config.platform.php = 8.3.0` |
| Framework | Laravel 7.30.7 | **Laravel 10.x** (decisión del usuario; soporta PHP 8.1–8.3) |
| PHPUnit | 9.6.22 (`^8.5.8\|^9.3.3`) | `^10.1` (requerido por Laravel 10) |
| Paquetes del framework | `fideloper/proxy`, `fruitcake/laravel-cors`, `facade/ignition`, `nunomaduro/collision ^4` | Integrados en el núcleo (proxy, CORS) o reemplazados (`spatie/laravel-ignition`, `collision ^7`) |
| PHP documentado (README) | 7.4 / 8.0 | 8.3 |
| Docker / CI | No existen | No existen (no se crean; ver preguntas) |

**Por qué hay que subir Laravel:** Laravel 7 no soporta PHP ≥ 8.1. Línea base real (PHP 8.4.0, único PHP instalado, con las dependencias del `composer.lock`): `composer install` descarga bien pero `php artisan package:discover` falla con `ErrorException: Return type of Illuminate\Support\Collection::offsetExists($key) should either be compatible with ArrayAccess::offsetExists(mixed $offset): bool`, y `phpunit` también aborta con ese error. Es decir, **Laravel 7 ni arranca en PHP moderno**; subir PHP exige subir el framework.

## 2. Línea base (antes de migrar)

- `php -v` → PHP 8.4.0 (no hay PHP 8.3 instalado; se verificará con `config.platform.php=8.3.0`, `phpcs` con `PHPCompatibility` `testVersion 8.3` y, si es posible, un PHP 8.3 portable).
- `composer validate` → válido (sin `--strict` aún).
- `php -l` sobre los 25+ archivos PHP versionados → **sin errores de sintaxis**.
- Pruebas: solo 2 (`tests/Unit/ExampleTest`, `tests/Feature/ExampleTest` que hace `GET /` esperando 200). **No se pudo obtener una línea base verde:** con PHP 8.4 + Laravel 7 fallan por el error de `ArrayAccess`. Con PHP 7.4 (no disponible aquí) se asume que pasaban. Criterio resultante: tras migrar, las 2 pruebas deben pasar y se añaden pruebas de humo para los endpoints.
- Herramientas: PHPUnit (sí), PHPStan / Rector / phpcs (no instalados). Se usarán Rector y phpcs solo como herramientas temporales (`composer global` o `require-dev` si se aprueba).
- Extensiones disponibles en la máquina: bcmath, curl, dom, fileinfo, gd, iconv, mbstring, mysqli, openssl, pdo_mysql, pdo_sqlite, sqlite3, tokenizer, xml, zip, etc. (suficientes para Laravel 10).

## 3. Tabla de hallazgos

Búsqueda sobre `app`, `routes`, `config`, `database`, `tests`, `bootstrap` de: `utf8_encode/decode`, `get_class()` sin argumentos, interpolación `"${var}"`, `each()`, `create_function`, `strftime`, `FILTER_SANITIZE_STRING`, etc. → **0 coincidencias**. Las incompatibilidades reales vienen del salto de framework, no del código propio.

| Archivo | Problema | Versión que lo causa | Corrección | Riesgo |
|---|---|---|---|---|
| `composer.json` | `php: ^7.2.5\|^8.0` permite versiones no soportadas | — | `^8.3` + `config.platform.php 8.3.0` | Bajo |
| `composer.json` | `laravel/framework ^7.29` sin soporte para 8.1+ (`ArrayAccess` con tipos de retorno) | 8.1 | `^10.0` | **Alto** (salto de 3 mayores) |
| `composer.json` | `fideloper/proxy`, `fruitcake/laravel-cors`, `facade/ignition`, `collision ^4`, `phpunit ^9` incompatibles/obsoletos con L10 | 8.1+ / L9–10 | Quitar los dos primeros, `spatie/laravel-ignition ^2`, `nunomaduro/collision ^7`, `phpunit/phpunit ^10.1`, `guzzlehttp/guzzle ^7.2`, `laravel/tinker ^2.8`, `laravel/sanctum` solo si se usa auth API | Medio |
| `app/Http/Middleware/TrustProxies.php` | Extiende `Fideloper\Proxy\TrustProxies` (eliminado) | L9 | Extender `Illuminate\Http\Middleware\TrustProxies` y usar `$headers` con `Request::HEADER_X_FORWARDED_*` | Bajo |
| `app/Http/Kernel.php` | `\Fruitcake\Cors\HandleCors`, `CheckForMaintenanceMode` | L9 | `\Illuminate\Http\Middleware\HandleCors`, `\App\Http\Middleware\PreventRequestsDuringMaintenance`; `$routeMiddleware` → `$middlewareAliases`; añadir `ValidateSignature`, `Authenticate`, etc. según el esqueleto L10 | Medio |
| `app/Http/Middleware/CheckForMaintenanceMode.php` | Clase renombrada | L8 | Reemplazar por `PreventRequestsDuringMaintenance` | Bajo |
| `app/Providers/RouteServiceProvider.php` | `$namespace` + `->namespace()` (rutas con strings) | L8+ | Quitar el namespace (las rutas ya usan `[Controller::class, ...]`, verificado en `routes/api.php`); añadir `configureRateLimiting` | Bajo |
| `app/Providers/*`, `config/*.php` | Config de L7 desfasada (`cors.php`, `session`, `auth`, `database`, `logging`, `app.php`) | L8–10 | Comparar con el esqueleto de L10 y fusionar claves nuevas conservando valores propios (`.env`) | Medio |
| `app/User.php` y `app/Region.php` | Modelos en la raíz (convención vieja); `app/Region.php` **duplica** `app/Models/Region.php` | — | `User` → `app/Models/User.php` (convención L8+), actualizar `config/auth.php`; `app/Region.php` se **marca** pero no se borra sin confirmación (posible lógica de negocio) | Medio |
| `database/seeds/`, `database/factories/UserFactory.php` | Seeders/factories en formato L7 (`$factory->define`, classmap) | L8 | `database/seeders/` con namespace y `UserFactory` como clase; quitar classmap de `composer.json` | Medio |
| `app/Console/Commands/SyncLocations.php` | Usa `Illuminate\Support\Facades\Http` (existe en L10). Constructor vacío `parent::__construct()`; sin tipos de retorno | 8.x | Sin cambios obligatorios; verificar que corre. No tocar lógica | Bajo |
| `app/Http/Controllers/LocationController.php` | 4 endpoints sin tipos ni pruebas | — | Sin cambios; añadir pruebas de humo que no dependan de MySQL (SQLite en memoria) | Bajo |
| `phpunit.xml` | Esquema de PHPUnit 9 (aviso de “deprecated schema”) | PHPUnit 10 | `--migrate-configuration` | Bajo |
| `tests/*` | Métodos `testBasicTest` OK en PHPUnit 10; `CreatesApplication` | — | Mantener; ajustar solo si falla | Bajo |
| `app/Exceptions/Handler.php`, `bootstrap/app.php` | Firmas de L7 (`report(Exception)`, `render`) | L8–10 | `Throwable`, `register()` con `reportable` | Medio |
| `README.md` | Indica PHP 7.4/8.0 y “no compatible con 8.1+” | — | Actualizar a PHP 8.3 / Laravel 10 | Bajo |
| `webpack.mix.js`, `package.json` | Mix 5 (Node). No afecta a PHP | — | **Fuera de alcance** (no se cambia) | — |

Posibles avisos de PHP 8.1–8.3 a revisar con `E_ALL` tras migrar: propiedades dinámicas (obsoleto en 8.2; solo advertencia, sin hallazgos en el código propio), parámetros `null` a funciones internas no anulables (0 hallazgos por inspección; se confirmará con `E_ALL` en el humo), `${var}` (8.2, 0 hallazgos).

## 4. Pasos ordenados (un commit atómico por paso)

Antes de cada commit: `git status`, `git diff --staged`, `php -l` de los archivos tocados, `composer validate --strict` y pruebas. Si algo falla, no se commitea.

| # | Paso | Verificación | Mensaje de commit |
|---|---|---|---|
| 1 | `composer.json`: `php ^8.3`, `config.platform.php = 8.3.0`, subir Laravel a `^10.0` y paquetes de soporte (ver tabla), quitar paquetes eliminados | `composer validate --strict` | `build: exigir PHP ^8.3 y actualizar a Laravel 10` |
| 2 | `composer update` acotado (`laravel/* , phpunit/*, nunomaduro/*, spatie/*, guzzlehttp/*`) y regenerar `composer.lock` | `composer install` limpio; `composer why-not php 8.3` sin bloqueos | `build: actualizar composer.lock para PHP 8.3` |
| 3 | Rector **solo si aporta** (`LaravelSetList::LARAVEL_100`, `LevelSetList::UP_TO_PHP_83`) en modo `--dry-run` primero; se aplican únicamente cambios revisados | `php -l`, pruebas | `refactor: aplicar reglas de Rector para PHP 8.3` (se omite si no hay cambios) |
| 4 | Esqueleto L10: Kernel HTTP/consola, middleware (TrustProxies, HandleCors, PreventRequestsDuringMaintenance), `RouteServiceProvider`, `Handler`, `config/*` | `php -l`; `php artisan about` y `php artisan route:list` | `refactor: adaptar la estructura de la aplicación a Laravel 10` |
| 5 | Modelos, seeders y factories (`App\Models\User`, `database/seeders`, `UserFactory` como clase) sin cambiar tablas ni lógica | `php artisan migrate --pretend`; pruebas | `refactor: migrar modelos, seeders y factories al formato de Laravel 10` |
| 6 | PHPUnit 10: `phpunit.xml` migrado; pruebas de humo de los 4 endpoints con SQLite en memoria | `vendor/bin/phpunit` en verde | `test: migrar a PHPUnit 10 y añadir pruebas de humo de la API` |
| 7 | Correcciones manuales que aparezcan con `E_ALL` / `phpcs PHPCompatibility 8.3` | phpcs sin errores | `fix: corregir incompatibilidades detectadas con PHP 8.3` |
| 8 | README (y Docker/CI solo si existieran) a PHP 8.3 | revisión manual | `docs: actualizar README a PHP 8.3 y Laravel 10` |
| 9 | Marcar pasos hechos en este documento | — | `docs: marcar pasos completados del plan` |

Se hace `git push` tras los pasos 2, 5 y 8 como mínimo.

## 5. Criterios de aceptación

1. `composer.json` exige `^8.3`, `config.platform.php = 8.3.0` y `composer validate --strict` queda limpio.
2. `composer install` limpio desde cero con la plataforma 8.3.0.
3. `php -l` sin errores en todo el código fuera de `vendor`.
4. `phpcs --standard=PHPCompatibility --runtime-set testVersion 8.3` sin errores (o excepciones justificadas).
5. Pruebas: pasan las 2 existentes + las de humo (la línea base original no es medible, ver §2).
6. Sin deprecaciones nuevas con `error_reporting(E_ALL)`.
7. Prueba de humo: `php artisan serve` y los endpoints `/api/regiones`, `/api/provincias/{id}`, `/api/distritos/{id}`, `/api/colegios/{ubigeo}` responden como antes (con base de datos de prueba) y `php artisan sync:locations` arranca.
8. README actualizado; sin Docker/CI porque no existen.
9. Sin cambios de lógica de negocio ni de esquema de base de datos.

Limitación declarada: la máquina solo tiene PHP 8.4.0. La verificación “real en 8.3” se hará con `platform` 8.3.0, `phpcs` `testVersion 8.3` y, si se aprueba, un PHP 8.3 portable; si no, se dirá explícitamente que se ejecutó sobre 8.4.

## 6. Plan de reversión

- Antes de empezar existe la etiqueta `pre-php-8.3` (commit `e947779`) subida al remoto.
- Reversión total: `git switch --detach pre-php-8.3` (o `git switch main`; `main` no se toca).
- Reversión parcial: `git revert <commit>` del paso afectado (no se usa `reset --hard` ni `push --force`).
- `vendor/` no está versionado; basta `composer install` con el `composer.lock` de la etiqueta.

## 7. Riesgos y preguntas abiertas

- **Riesgo alto:** el salto 7 → 10 cambia muchas firmas internas; sin pruebas reales previas la red de seguridad es débil (por eso el paso 6 añade pruebas de humo).
- **Pregunta:** `app/Region.php` duplica `app/Models/Region.php`; ¿se conserva o se elimina? (el plan solo lo señala).
- **Pregunta:** ¿se desea crear Dockerfile / CI con PHP 8.3? (fuera de alcance por defecto, no existen hoy).
- **Pregunta:** la API pública del Ministerio de Educación (ESCALE) usada por `sync:locations` puede no responder; no se prueba contra producción.
- **Fuera de alcance (mejoras sugeridas):** Laravel Mix → Vite, tipado estricto de controladores, pasar a Laravel 11/12, eliminar `app/Region.php`.
- El remoto del profesor (`upstream`) no se modifica; el trabajo se sube a `origin` (`afgilmer16-svg/migracionphp`).
