<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Billing\Services\EntitlementService;
use App\Domain\Catalogue\Support\Gtin;
use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Organizations\Services\OrganizationOnboardingService;
use App\Domain\Organizations\Services\OrganizationVerificationService;
use App\Domain\Platform\Models\Attachment;
use App\Domain\Platform\Services\AttachmentService;
use App\Domain\Platform\Services\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrganizationResource;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrganizationController extends Controller
{
    public function __construct(private readonly TenantContext $tenant) {}

    /** Onboarding step 1-3: any authenticated user may register an organization they will own. */
    public function register(Request $request, OrganizationOnboardingService $onboarding): JsonResponse
    {
        $data = $request->validate($this->profileRules() + [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(OrganizationType::class)],
            'country_code' => ['required', 'string', Rule::exists('countries', 'code')->where('is_active', true)],
        ]);

        $organization = $onboarding->register($request->user(), $data);

        return OrganizationResource::make($organization->load('modules'))->response()->setStatusCode(201);
    }

    public function show(): OrganizationResource
    {
        Gate::authorize('organization.view');

        return OrganizationResource::make($this->tenant->organization()->load('modules'));
    }

    public function update(Request $request, AuditLogger $audit): OrganizationResource
    {
        Gate::authorize('organization.manage');

        $organization = $this->tenant->organization();
        $original = $organization->getAttributes();
        $organization->fill($request->validate($this->profileRules() + ['name' => ['sometimes', 'string', 'max:255']]))->save();

        [$old, $new] = $audit->diff($organization, $original);
        if ($new !== []) {
            $audit->record('organization.profile_updated', $organization, $old, $new);
        }

        return OrganizationResource::make($organization->load('modules'));
    }

    public function documents(): JsonResponse
    {
        Gate::authorize('organization.view');

        return response()->json(['data' => $this->tenant->organization()->documents()->latest()->get()
            ->map(fn (Attachment $a) => $a->only(['id', 'category', 'original_name', 'mime_type', 'size_bytes', 'sha256', 'scan_status', 'created_at']))]);
    }

    public function uploadDocument(Request $request, AttachmentService $attachments, EntitlementService $entitlements): JsonResponse
    {
        Gate::authorize('organization.manage');

        $data = $request->validate([
            'category' => ['required', Rule::in(['business_registration', 'premises_licence', 'pharmacist_licence', 'regulatory_licence', 'tax_certificate', 'other'])],
            'file' => ['required', 'file', 'max:'.config('platform.attachments.max_kb'), 'mimes:'.implode(',', config('platform.attachments.allowed_mimes'))],
        ]);

        $organization = $this->tenant->organization();
        $attachment = $entitlements->guard($organization, 'storage_mb',
            fn () => $attachments->store($data['file'], $organization, $data['category'], $organization->id, $request->user()),
            adding: (int) ceil($data['file']->getSize() / 1048576));

        return response()->json(['data' => $attachment->only(['id', 'category', 'original_name', 'mime_type', 'size_bytes', 'sha256', 'scan_status'])], 201);
    }

    public function downloadDocument(string $attachmentId): StreamedResponse
    {
        Gate::authorize('organization.view');

        // Scoped to the current organization's own documents: foreign ids are simply not found.
        $attachment = $this->tenant->organization()->documents()->findOrFail($attachmentId);

        return Storage::disk($attachment->disk)->download($attachment->path, $attachment->original_name);
    }

    public function submitVerification(Request $request, OrganizationVerificationService $verification): OrganizationResource
    {
        Gate::authorize('organization.manage');

        return OrganizationResource::make($verification->submit($this->tenant->organization(), $request->user()));
    }

    private function profileRules(): array
    {
        return [
            'legal_name' => ['nullable', 'string', 'max:255'],
            'business_registration_number' => ['nullable', 'string', 'max:64'],
            'tax_identification_number' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'website_url' => ['nullable', 'url', 'max:255'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state_region' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'gln' => [...['nullable', 'digits:13', fn ($attribute, $value, $fail) => Gtin::isValid((string) $value) || $fail('The GLN check digit is invalid.')], Rule::unique('organizations', 'gln')->ignore($this->tenant->id())],
        ];
    }
}
