# 🏫 Bridges Note - MINEDU ESCALE Bridge API

Microservicio y API REST en **Laravel 13 (PHP 8.3)** diseñado para sincronizar, estructurar y servir el catálogo oficial de instituciones educativas (colegios) y la división política/geográfica (regiones, provincias y distritos) del Perú, consumiendo directamente la API pública oficial de **MINEDU / ESCALE** (*Estadística de la Calidad Educativa*).

---

## 📋 Tabla de Contenidos

1. [Propósito del Proyecto](#-propósito-del-proyecto)
2. [Arquitectura y Flujo de Datos](#-arquitectura-y-flujo-de-datos)
3. [Requisitos del Sistema](#-requisitos-del-sistema)
4. [Instalación y Puesta en Marcha](#-instalación-y-puesta-en-marcha)
5. [Configuración de Entorno (.env)](#-configuración-de-entorno-env)
6. [Sincronización de Datos (Consola Artisan)](#-sincronización-de-datos-consola-artisan)
7. [Documentación de la API REST](#-documentación-de-la-api-rest)
8. [Estructura del Proyecto](#-estructura-del-proyecto)
9. [Solución de Problemas Frecuentes](#-solución-de-problemas-frecuentes)

---

## 🎯 Propósito del Proyecto

Servir como un **puente de datos optimizado** para aplicaciones cliente (sistemas de admisión universitaria, plataformas de matrícula escolar, intranets, etc.), permitiéndoles autocompletar ubigeos y consultar colegios mediante consultas locales ultrarrápidas, sin depender de la latencia o disponibilidad de los servidores estatales.

---

## 🔄 Arquitectura y Flujo de Datos

```
[ API MINEDU / ESCALE ] 
 (escale.minedu.gob.pe)
          │
          ▼  (Comando: php artisan sync:locations)
[ Base de Datos Local MySQL (escalebridge) ]
  ├── regions
  ├── provinces
  ├── districts
  └── schools (con ubigeo / idDistrito)
          │
          ▼  (LocationController)
[ Endpoints API REST Local / Producción ]
  ├── GET /api/regiones
  ├── GET /api/provincias/{idRegion}
  ├── GET /api/distritos/{idProvince}
  └── GET /api/colegios/{ubigeo}
          │
          ▼
[ Clientes / Aplicaciones Externas ] (ej. admisionweb)
```

---

## 💻 Requisitos del Sistema

- **PHP:** `8.3.x` (mínimo `8.3`; `composer.json` exige `^8.3`).
  > Extensiones necesarias: `bcmath`, `ctype`, `curl`, `dom`, `fileinfo`, `mbstring`, `openssl`, `pdo_mysql`, `tokenizer`, `xml`.
- **Laravel Framework:** `13.x` (`^13.40`, con soporte de seguridad vigente)
- **Gestor de Paquetes:** Composer 2.x
- **Base de Datos:** MySQL 5.7+ / 8.0+ o MariaDB 10.3+
- **Servidor Web Local:** Laragon (con PHP 8.3), XAMPP o `php artisan serve`.
- **Pruebas:** PHPUnit 12 (`vendor/bin/phpunit`; 37 pruebas con SQLite en memoria y la API de MINEDU simulada, no necesitan MySQL ni red).

---

## 🚀 Instalación y Puesta en Marcha

### 1. Clonar el repositorio
```bash
git clone https://github.com/hubelsolis/brigesnote.git
cd brigesnote
```

### 2. Instalar dependencias de Composer
```bash
composer install
```

### 3. Configurar variables de entorno
Copia el archivo de ejemplo y genera la clave de cifrado:
```bash
cp .env.example .env
php artisan key:generate
```

### 4. Crear la Base de Datos
Crea una base de datos vacía en tu gestor MySQL (ej. HeidiSQL o phpMyAdmin):
- **Nombre:** `escalebridge`
- **Cotejamiento:** `utf8mb4_unicode_ci`

### 5. Ejecutar las Migraciones
Crea las tablas en la base de datos:
```bash
php artisan migrate
```

### 6. Sincronizar el catálogo escolar
Descarga la información oficial desde MINEDU:
```bash
php artisan sync:locations
```

### 7. Iniciar el servidor
```bash
php artisan serve
```
La aplicación quedará disponible en `http://127.0.0.1:8000`.

> ℹ️ El punto de entrada es `public/index.php`. Con Laragon/XAMPP lo ideal es apuntar el *document root* a `public/`. Si Apache apunta a la raíz del proyecto, el `.htaccess` de la raíz reenvía todo a `public/` y responde 403 a `.env`, `vendor/`, `storage/`, etc. (requiere `mod_rewrite` y `AllowOverride All`).

---

## ⚙️ Configuración de Entorno (.env)

Asegúrate de tener estos valores configurados en tu archivo `.env`:

```env
APP_NAME="Bridges Note"
APP_ENV=local
APP_KEY=base64:...
APP_DEBUG=true
APP_URL=http://127.0.0.1:8000

# Conexión MySQL
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=escalebridge
DB_USERNAME=root
DB_PASSWORD=

# Controlador de sesión en archivo (no requiere tabla 'sessions' en BD)
SESSION_DRIVER=file
CACHE_DRIVER=file
QUEUE_CONNECTION=sync
```

---

## 📥 Sincronización de Datos (Consola Artisan)

El comando central del sistema es:

```bash
php artisan sync:locations
```

### ¿Cómo opera la sincronización?
1. **Regiones (`/padron/rest/regiones`):** Descarga los 25 departamentos del país con sus nombres, coordenadas (`pointX`, `pointY`) y nivel de `zoom`.
2. **Provincias (`/padron/rest/provincias?idRegion={id}`):** Por cada región, descarga sus provincias.
3. **Distritos (`/padron/rest/distritos?idProvincia={id}`):** Por cada provincia, descarga los distritos correspondientes.
4. **Colegios (`/padron/rest/instituciones?estados=1&ubigeo={idDistrito}`):** Por cada distrito, descarga las instituciones educativas activas y las almacena en la tabla `schools` vinculando el código modular (`codMod`) como llave primaria y asociando el `idDistrito`.

Opciones y comportamiento:

- `php artisan sync:locations --region=15` sincroniza solo una región (código de 2 dígitos).
- Pagina los colegios de 50 en 50 (`start`), por lo que **se guardan todos** los de cada distrito, sin tope.
- Reintenta 3 veces ante fallos de red o errores del servidor. Si un paso sigue fallando, continúa con el resto y termina con código 1; es idempotente, se puede relanzar.
- La URL, los reintentos y el tiempo de espera se configuran con `ESCALE_URL`, `ESCALE_RETRIES`, `ESCALE_RETRY_DELAY_MS` y `ESCALE_TIMEOUT`.

> ⏱️ **Nota:** La sincronización total recorre más de 1,800 distritos y 80,000 colegios, por lo que puede tomar varios minutos según la velocidad de conexión y respuesta del servidor del MINEDU.

---

## 📡 Documentación de la API REST

Todos los endpoints retornan respuestas en formato JSON bajo el prefijo `/api/` (también los errores). Los parámetros se validan por formato: región 2 dígitos, provincia 4, distrito 6; otro formato responde 404. Límite: 60 peticiones por minuto por IP.

### 1. Listar Regiones / Departamentos
Obtiene todas las regiones del país.

- **URL:** `/api/regiones`
- **Método:** `GET`
- **Respuesta Exitosa (200 OK):**
```json
{
  "status": "success",
  "data": [
    {
      "idRegion": "15",
      "nombreRegion": "LIMA",
      "pointX": -12.046374,
      "pointY": -77.042793,
      "zoom": 10
    }
  ]
}
```

---

### 2. Listar Provincias por Región
Obtiene las provincias pertenecientes a una región (mediante los 2 primeros dígitos del código de departamento).

- **URL:** `/api/provincias/{idRegion}`
- **Método:** `GET`
- **Ejemplo:** `/api/provincias/15` (Provincias de Lima)
- **Respuesta Exitosa (200 OK):**
```json
{
  "status": "success",
  "data": [
    {
      "idProvincia": "1501",
      "nombreProvincia": "LIMA",
      "pointX": -12.046374,
      "pointY": -77.042793
    }
  ]
}
```

---

### 3. Listar Distritos por Provincia
Obtiene los distritos de una provincia (mediante los 4 dígitos de ubigeo provincial).

- **URL:** `/api/distritos/{idProvince}`
- **Método:** `GET`
- **Ejemplo:** `/api/distritos/1501` (Distritos de la provincia de Lima)
- **Respuesta Exitosa (200 OK):**
```json
{
  "status": "success",
  "data": [
    {
      "idDistrito": "150101",
      "nombreDistrito": "LIMA"
    },
    {
      "idDistrito": "150102",
      "nombreDistrito": "ANCON"
    }
  ]
}
```

---

### 4. Listar Colegios por Distrito (Ubigeo)
Obtiene todas las instituciones educativas registradas en un distrito según su ubigeo de 6 dígitos.

- **URL:** `/api/colegios/{ubigeo}`
- **Método:** `GET`
- **Ejemplo:** `/api/colegios/150101`
- **Respuesta Exitosa (200 OK):**
```json
{
  "status": "success",
  "data": [
    {
      "codMod": "0273572",
      "idDistrito": "150101",
      "nombreCenEdu": "JUAN JIMENEZ PIMENTEL",
      "cenPob": "LIMA",
      "dirCen": "JR. LAMPA 240",
      "director": "PEDRO PEREZ",
      "dis_crecer": null,
      "dis_juntos": null
    }
  ]
}
```

---

## 📂 Estructura del Proyecto

```
brigesnote/
├── app/
│   ├── Console/Commands/SyncLocations.php   # Comando 'sync:locations' (orquesta y guarda)
│   ├── Http/Controllers/LocationController.php  # Endpoints /api/
│   ├── Models/                              # Region, Province, District, School, User
│   ├── Providers/AppServiceProvider.php     # Cliente ESCALE y límite de peticiones
│   └── Services/Escale/                     # EscaleClient (reintentos, paginación) y EscaleException
├── bootstrap/app.php                        # Rutas, middleware y excepciones (Laravel 13)
├── database/migrations/                     # Migraciones (clases anónimas)
├── docs/                                    # arquitectura.md y migracion-php-8.3.md
├── public/                                  # ÚNICO punto de entrada (index.php + .htaccess)
├── routes/api.php                           # Rutas REST con validación de formato
├── tests/                                   # Unit (EscaleClient) y Feature (API, sync, seguridad)
├── .htaccess                                # Contención para Apache apuntando a la raíz
└── .env.example                             # Plantilla de variables de entorno
```

Más detalle en [docs/arquitectura.md](docs/arquitectura.md).

---

## 🛠️ Solución de Problemas Frecuentes

### 1. Error: `Your Composer dependencies require a PHP version ">= 8.3.0"`
- **Causa:** Estás ejecutando el proyecto con una versión de PHP anterior a 8.3.
- **Solución:** Cambia la versión de PHP en Laragon (o en tu servidor) a **PHP 8.3** y ejecuta `composer install`.

### 2. Error: `Service Apache can not start ... fcgid.conf` en Laragon
- **Causa:** Descargaste la versión `nts` (Non-Thread Safe) de PHP en Windows.
- **Solución:** Descarga e instala en Laragon la versión **Thread Safe (TS)** de PHP 8.3 desde [windows.php.net](https://windows.php.net/downloads/releases/).

### 3. `.env` o `vendor/` accesibles desde el navegador
- **Causa:** el servidor Apache apunta a la raíz y no se aplica el `.htaccess`.
- **Solución:** apunta el *document root* a `public/`, o activa `mod_rewrite` y `AllowOverride All`.

### 4. Error: `Base table or view not found: 1146 Table '...sessions' doesn't exist`
- **Causa:** En el archivo `.env` está configurado `SESSION_DRIVER=database` sin haber migrado la tabla de sesiones.
- **Solución:** Cambia en `.env` a `SESSION_DRIVER=file`.

---

## 📄 Licencia

Este proyecto es de código abierto bajo la licencia [MIT](LICENSE).
