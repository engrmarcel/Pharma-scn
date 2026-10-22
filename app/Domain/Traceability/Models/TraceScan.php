<?php

namespace App\Domain\Traceability\Models;

use Illuminate\Database\Eloquent\Model;

/** One scan of a code (public or authenticated). Holds no personal data. */
class TraceScan extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['scanned_at' => 'datetime'];
    }
}
