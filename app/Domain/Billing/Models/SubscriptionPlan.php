<?php

namespace App\Domain\Billing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class SubscriptionPlan extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:4',
            'trial_days' => 'integer',
            'is_public' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return array<string, array{enabled: bool, limit: int|null}> */
    public function entitlementMap(): array
    {
        return DB::table('plan_entitlements')->where('plan_id', $this->id)->get()
            ->mapWithKeys(fn ($row) => [$row->key => ['enabled' => (bool) $row->enabled, 'limit' => $row->limit_value === null ? null : (int) $row->limit_value]])
            ->all();
    }

    public function setEntitlement(string $key, bool $enabled, ?int $limit = null): void
    {
        DB::table('plan_entitlements')->updateOrInsert(
            ['plan_id' => $this->id, 'key' => $key],
            ['enabled' => $enabled, 'limit_value' => $limit, 'updated_at' => now(), 'created_at' => now()],
        );
    }
}
