<?php

namespace App\Domain\Catalogue\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Manufacturer extends Model
{
    use HasUlids;

    protected $guarded = [];

    public function regulatoryIdentifiers(): MorphMany
    {
        return $this->morphMany(RegulatoryIdentifier::class, 'subject');
    }
}
