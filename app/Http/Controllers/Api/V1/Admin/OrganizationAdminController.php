<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Organizations\Enums\VerificationStatus;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationModule;
use App\Domain\Organizations\Services\OrganizationStatusService;
use App\Domain\Organizations\Services\OrganizationVerificationService;
use App\Domain\Platform\Services\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrganizationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Platform operations over organizations. Exposes organization profiles,
 * verification evidence and status — not the organization's business data.
 */
class OrganizationAdminController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('platform.organizations.view');

        $filters = $request->validate([
            'verification_status' => ['nullable', Rule::enum(VerificationStatus::class)],
            'status' => ['nullable', Rule::in(['active', 'suspended'])],
            'type' => ['nullable', 'string'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        return OrganizationResource::collection(Organization::query()
            ->when($filters['verification_status'] ?? null, fn ($q, $v) => $q->where('verification_status', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($filters['country_code'] ?? null, fn ($q, $v) => $q->where('country_code', strtoupper($v)))
            ->when($filters['q'] ?? null, fn ($q, $v) => $q->where('name', 'like', "%{$v}%"))
            ->with('modules')
            ->orderBy('created_at')
            ->paginate(25));
    }

    public function show(Organization $organization): JsonResponse
    {
        Gate::authorize('platform.organizations.view');

        $organization->load(['modules', 'verifications.actor']);

        return response()->json([
            'data' => OrganizationResource::make($organization),
            'verification_history' => $organization->verifications->map(fn ($v) => [
                'action' => $v->action,
                'from_status' => $v->from_status,
                'to_status' => $v->to_status,
                'notes' => $v->notes,
                'actor' => $v->actor?->name,
                'created_at' => $v->created_at->toIso8601String(),
            ]),
            'documents' => $organization->documents()->get()->map(fn ($a) => $a->only(['id', 'category', 'original_name', 'mime_type', 'size_bytes', 'sha256', 'scan_status', 'created_at'])),
        ]);
    }

    public function downloadDocument(Organization $organization, string $attachmentId): StreamedResponse
    {
        Gate::authorize('platform.organizations.verify');

        $attachment = $organization->documents()->findOrFail($attachmentId);
        app(AuditLogger::class)->record('organization.document_viewed_by_platform', $attachment, organizationId: $organization->id);

        return Storage::disk($attachment->disk)->download($attachment->path, $attachment->original_name);
    }

    public function decide(Request $request, Organization $organization, OrganizationVerificationService $verification): OrganizationResource
    {
        Gate::authorize('platform.organizations.verify');

        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject', 'request_changes', 'revoke'])],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $result = $data['decision'] === 'revoke'
            ? $verification->revoke($organization, $request->user(), (string) ($data['notes'] ?? ''))
            : $verification->decide($organization, $data['decision'], $request->user(), $data['notes'] ?? null);

        return OrganizationResource::make($result);
    }

    public function suspend(Request $request, Organization $organization, OrganizationStatusService $status): OrganizationResource
    {
        Gate::authorize('platform.organizations.suspend');
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        return OrganizationResource::make($status->suspend($organization, $request->user(), $data['reason']));
    }

    public function reactivate(Request $request, Organization $organization, OrganizationStatusService $status): OrganizationResource
    {
        Gate::authorize('platform.organizations.suspend');
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);

        return OrganizationResource::make($status->reactivate($organization, $request->user(), $data['reason'] ?? null));
    }

    public function updateModules(Request $request, Organization $organization, AuditLogger $audit): OrganizationResource
    {
        Gate::authorize('platform.organizations.modules');

        $data = $request->validate([
            'modules' => ['required', 'array'],
            'modules.*' => ['boolean'],
        ]);
        $unknown = array_diff(array_keys($data['modules']), array_keys(config('platform.modules')));
        abort_if($unknown !== [], 422, 'Unknown modules: '.implode(', ', $unknown));

        $before = $organization->modules()->pluck('enabled', 'module')->all();
        foreach ($data['modules'] as $module => $enabled) {
            OrganizationModule::query()->updateOrInsert(
                ['organization_id' => $organization->id, 'module' => $module],
                ['enabled' => $enabled, 'changed_by' => $request->user()->id, 'updated_at' => now(), 'created_at' => now()],
            );
        }
        $audit->record('organization.modules_changed', $organization, $before, $data['modules'], organizationId: $organization->id);

        return OrganizationResource::make($organization->load('modules'));
    }
}
