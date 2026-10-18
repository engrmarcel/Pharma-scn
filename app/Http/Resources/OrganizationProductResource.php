<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrganizationProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'local_name' => $this->local_name,
            'product' => $this->whenLoaded('product', fn () => [
                'id' => $this->product->id,
                'platform_sku' => $this->product->platform_sku,
                'display_name' => $this->product->display_name,
                'status' => $this->product->status->value,
            ]),
            // Decimal strings, never floats.
            'currency_code' => $this->currency_code,
            'default_purchase_cost' => $this->default_purchase_cost,
            'default_selling_price' => $this->default_selling_price,
            'tax_category_code' => $this->tax_category_code,
            'preferred_supplier_id' => $this->preferred_supplier_id,
            'reorder_level' => $this->reorder_level,
            'reorder_quantity' => $this->reorder_quantity,
            'status' => $this->status,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
