<?php

namespace App\Domain\Traceability\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A QR token, serial number or logistic unit (SSCC) issued by an organization.
 * The QR content is only a URL ending in the opaque token: it carries no
 * supplier, price or patient data.
 */
class TraceIdentifier extends Model
{
    use BelongsToOrganization, HasUlids;

    public const KINDS = ['batch', 'product', 'serial', 'sscc'];

    protected $guarded = ['id', 'organization_id'];

    protected $attributes = ['status' => 'active'];

    protected function casts(): array
    {
        return ['expiry_date' => 'date:Y-m-d'];
    }

    public function url(): string
    {
        return rtrim(config('traceability.scan_base_url'), '/').'/'.$this->token;
    }
}
