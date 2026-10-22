<?php

namespace App\Domain\Traceability\Models;

use Illuminate\Database\Eloquent\Model;

class RecallBatch extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];
}
