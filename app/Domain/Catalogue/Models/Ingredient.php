<?php

namespace App\Domain\Catalogue\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class Ingredient extends Model
{
    use HasUlids;

    protected $guarded = [];
}
