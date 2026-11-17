# Análisis de arquitectura — brigesnote

Fecha: 16/11/2026 · Rama: `mejoras/evaluacion` · Framework: Laravel 13 · PHP 8.3

## 1. Qué hace el sistema

Sincroniza el padrón de MINEDU/ESCALE (regiones → provincias → distritos → colegios) a una base de datos local y lo sirve por una API REST de solo lectura. Dos flujos independientes:

```
 ESCRITURA (consola, esporádica)             LECTURA (HTTP, frecuente)
 php artisan sync:locations                  GET /api/regiones, /provincias/{2}, /distritos/{4}, /colegios/{6}
        │                                            │
 SyncLocations (comando)                      routes/api.php (valida formato del ubigeo)
        │                                            │
 EscaleClient (servicio HTTP)                 LocationController
   · reintentos · paginación · normaliza        │
        │                                     Modelos Eloquent
 API MINEDU/ESCALE                                  │
        └───────────────► Base de datos ◄───────────┘
```

## 2. Cambios de arquitectura respecto al original (Laravel 7)

| Aspecto | Antes (Laravel 7/10) | Ahora (Laravel 13) | Motivo |
|---|---|---|---|
| Arranque | `bootstrap/app.php` con `Kernel` HTTP/consola, `Handler`, 5 proveedores | `Application::configure()` fluido (`withRouting`, `withMiddleware`, `withExceptions`) y un único `AppServiceProvider` | Menos código de andamiaje (−31 archivos), estructura actual del framework |
| Middleware | 8 clases copiadas en `app/Http/Middleware` | Middleware del núcleo, configurado en `bootstrap/app.php` | No hay que mantener copias de código del framework |
| Acceso a la API externa | Dentro del comando, 4 métodos con `Http::get` repetidos | `App\Services\Escale\EscaleClient` (inyectado por el contenedor) | Separación de responsabilidades: el comando solo orquesta y guarda |
| Configuración de la API | URL fija en el código | `config/services.php` → `ESCALE_*` en `.env` | Configurable sin tocar código |
| Respuestas de error | HTML o JSON según cabecera | La API siempre responde JSON | Contrato estable para los clientes |
| Rate limiting | Definido en `RouteServiceProvider` | `throttleApi()` + limitador `api` en `AppServiceProvider` (60/min por IP) | Protege la API pública |
| Punto de entrada | Mezcla de `index.php` en raíz y `public/` | Solo `public/index.php`; la raíz tiene un `.htaccess` de contención | Ver §4 |

## 3. Decisiones de diseño

- **Servicio en lugar de repositorio:** no hay lógica de dominio que justifique capas de repositorio/casos de uso; Eloquent basta. El único punto con complejidad real es la API externa, y es el que se aisló (`EscaleClient`).
- **`EscaleClient` oculta tres particularidades de la API** (verificadas contra el servicio real): (1) un único resultado llega como objeto y no como lista; (2) `/instituciones` pagina de 50 en 50 con el parámetro `start` y devuelve el cuerpo sin `items` al terminar; (3) puede fallar de forma transitoria → 3 reintentos con espera.
- **`upsert` por lotes** en vez de `updateOrCreate` fila a fila: una consulta por página de 50 colegios, no 50.
- **El comando es tolerante a fallos:** si una provincia o un distrito falla tras los reintentos, lo registra, continúa con el resto y termina con código de salida 1 para que se note. Es idempotente: se puede relanzar.
- **Sin `LEFT()`:** se reemplazó por `LIKE 'prefijo%'` (usa el índice de la clave primaria, funciona igual en MySQL y SQLite). Los parámetros de ruta se validan por formato (`[0-9]{2}`, `{4}`, `{6}`), así que el `LIKE` nunca recibe comodines.
- **Sin autenticación:** la API es pública y de solo lectura, por eso se quitó la ruta `/api/user` y el guard `api` del esqueleto original.

## 4. Seguridad

| Riesgo | Mitigación | Cómo se comprueba |
|---|---|---|
| Laravel sin soporte con avisos de seguridad (`composer audit`: 5, uno alto) | Laravel 13.42 (soportado, PHP ≥ 8.3) | `composer audit` → sin avisos |
| Exposición del `.env` con Apache en la raíz | `.htaccess` de la raíz: 403 a archivos ocultos y a `app/`, `config/`, `vendor/`, `storage/`…; el resto se reenvía a `public/` | `tests/Feature/SeguridadTest` (comprueba las reglas) |
| API no funcionaba con Apache | Se creó `public/.htaccess` (front controller) | ídem |
| `APP_DEBUG` activo por defecto | `.env.example` con `APP_DEBUG=false` | ídem |
| Inyección / comodines en parámetros | Restricción por regex en las rutas + consultas parametrizadas | `LocationApiTest` |
| Abuso de la API | Límite de 60 peticiones/min por IP | `LocationApiTest::test_la_api_limita_las_peticiones` |

La recomendación de producción sigue siendo apuntar el *document root* a `public/`; el `.htaccess` de la raíz es solo una red de seguridad.

## 5. Pruebas

37 pruebas (PHPUnit 12, SQLite en memoria, API de ESCALE simulada con `Http::fake`, sin red ni MySQL):

- `LocationApiTest` — endpoints, orden, tipos, 404 y formatos inválidos.
- `EscaleClientTest` — normalización objeto/lista, reintentos, agotamiento de reintentos, paginación (107 colegios en 3 páginas, página exacta de 50).
- `SyncLocationsTest` — jerarquía completa, idempotencia, `--region`, más de 50 colegios, fallo total y fallo parcial.
- `SeguridadTest` — rutas retiradas, JSON en errores, `.htaccess`, `.env`, restos del esqueleto.

## 6. Limitaciones conocidas

- Las reglas de `.htaccess` están verificadas por prueba de contenido, no contra un Apache real (no hay Apache en el entorno de desarrollo).
- La sincronización completa contra el servicio real de MINEDU no se ejecutó (tarda varios minutos y carga un servicio público); se validó el formato y la paginación con peticiones puntuales (distrito 150101) y con pruebas simuladas.
- Probado con PHP 8.4 (el único instalado); `composer.json` fija `config.platform.php = 8.3.0` para resolver dependencias como en 8.3.
