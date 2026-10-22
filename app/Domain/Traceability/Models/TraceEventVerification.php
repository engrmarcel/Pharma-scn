<?php

namespace App\Domain\Traceability\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Evidence about an event added after it was recorded. Append-only. */
class TraceEventVerification extends Model
{
    public const UPDATED_AT = null;

    public const LEVELS = ['corroborated', 'independently_verified', 'disputed'];

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Verification records are append-only.'));
        static::deleting(fn () => throw new LogicException('Verification records are append-only.'));
    }
}
