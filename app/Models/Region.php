<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Region extends Model
{
    protected $table = 'regions';

    protected $primaryKey = 'idRegion';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['idRegion', 'nombreRegion', 'pointX', 'pointY', 'zoom'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['pointX' => 'float', 'pointY' => 'float', 'zoom' => 'integer'];
    }
}
