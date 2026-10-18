<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Branches and warehouses. */
class LocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return collect($this->resource->attributesToArray())->except(['organization_id'])->all();
    }
}
