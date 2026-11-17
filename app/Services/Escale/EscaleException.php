<?php

declare(strict_types=1);

namespace App\Services\Escale;

use RuntimeException;

/** La API de ESCALE no respondió correctamente tras agotar los reintentos. */
class EscaleException extends RuntimeException
{
    //
}
