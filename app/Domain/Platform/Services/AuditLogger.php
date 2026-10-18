<?php

namespace App\Domain\Platform\Services;

use App\Domain\Platform\Models\AuditLog;
use App\Support\Tenancy\TenantContext;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

class AuditLogger
{
    /** Keys whose values are never written to the audit trail. */
    private const REDACTED = ['password', 'token', 'token_hash', 'secret', 'mfa_secret', 'remember_token', 'credentials', 'api_key', 'private_key'];

    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @param  string|null|false  $organizationId  null = current tenant; false = explicitly platform-level
     */
    public function record(
        string $action,
        ?Model $subject = null,
        array $old = [],
        array $new = [],
        array $metadata = [],
        string|null|false $organizationId = null,
    ): AuditLog {
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();
        $actor = Auth::user();

        return AuditLog::query()->create([
            'organization_id' => $organizationId === false ? null : ($organizationId ?? $this->tenant->id()),
            'actor_user_id' => $actor?->getAuthIdentifier(),
            'actor_type' => $actor ? 'user' : 'system',
            'action' => $action,
            'auditable_type' => $subject?->getMorphClass(),
            'auditable_id' => $subject?->getKey(),
            'old_values' => $this->clean($old) ?: null,
            'new_values' => $this->clean($new) ?: null,
            'metadata' => $this->clean($metadata) ?: null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? Str::limit((string) $request->userAgent(), 250, '') : null,
            'correlation_id' => Context::get('correlation_id'),
        ]);
    }

    /**
     * Old/new values for the attributes that actually changed on a saved model.
     *
     * @return array{0: array, 1: array}
     */
    public function diff(Model $model, array $original): array
    {
        $old = [];
        $new = [];
        foreach ($model->getChanges() as $key => $value) {
            if (in_array($key, ['updated_at', 'created_at'], true)) {
                continue;
            }
            $old[$key] = $original[$key] ?? null;
            $new[$key] = $value;
        }

        return [$old, $new];
    }

    private function clean(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::REDACTED, true)) {
                $values[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $values[$key] = $this->clean($value);
            } elseif ($value instanceof BackedEnum) {
                $values[$key] = $value->value;
            }
        }

        return $values;
    }
}
