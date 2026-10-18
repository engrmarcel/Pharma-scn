<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Platform\Models\AuditLog;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class AuditLogController extends Controller
{
    /** The current organization's audit trail only. */
    public function index(Request $request, TenantContext $tenant): AnonymousResourceCollection
    {
        Gate::authorize('audit.view');

        return $this->listing($request, AuditLog::query()->where('organization_id', $tenant->id()));
    }

    /** Platform-level events (no organization), e.g. catalogue and branding changes. */
    public function platform(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('platform.audit.view');

        return $this->listing($request, AuditLog::query()->whereNull('organization_id'));
    }

    private function listing(Request $request, $query): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'action' => ['nullable', 'string', 'max:100'],
            'subject_type' => ['nullable', 'string', 'max:100'],
            'subject_id' => ['nullable', 'string', 'max:64'],
        ]);

        return AuditLogResource::collection($query
            ->when($filters['action'] ?? null, fn ($q, $v) => $q->where('action', $v))
            ->when($filters['subject_type'] ?? null, fn ($q, $v) => $q->where('auditable_type', $v))
            ->when($filters['subject_id'] ?? null, fn ($q, $v) => $q->where('auditable_id', $v))
            ->with('actor')
            ->orderByDesc('id')
            ->paginate(50));
    }
}
