<?php

namespace App\Domain\Platform\Models;

use Illuminate\Database\Eloquent\Model;

class DosageForm extends Model
{
    protected $table = 'dosage_forms';

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];
}
