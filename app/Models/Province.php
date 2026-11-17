<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Province extends Model
{
    protected $table = 'provinces';

    protected $primaryKey = 'idProvincia';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['idProvincia', 'nombreProvincia', 'pointX', 'pointY'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['pointX' => 'float', 'pointY' => 'float'];
    }
}
