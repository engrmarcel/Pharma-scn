<?php

namespace App\Domain\Platform\Models;

use Illuminate\Database\Eloquent\Model;

class AdministrationRoute extends Model
{
    protected $table = 'administration_routes';

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];
}
