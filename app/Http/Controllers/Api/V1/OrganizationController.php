<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Billing\Services\EntitlementService;
use App\Domain\Catalogue\Models\RegulatoryIdentifier;
use App\Domain\Catalogue\Services\RegulatoryIdentifierService;
use App\Domain\Catalogue\Support\Gtin;
use App\Domain\Catalogue\Support\RegulatoryIdentifierRules;
use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Organizations\Services\OnboardingRequirements;
use App\Domain\Organizations\Services\OrganizationOnboardingService;
use App\Domain\Organizations\Services\OrganizationVerificationService;
use App\Domain\Platform\Models\Attachment;
use App\Domain\Platform\Models\Country;
use App\Domain\Platform\Models\RegulatoryAuthority;
use App\Domain\Platform\Services\AttachmentService;
use App\Domain\Platform\Services\AuditLogger;
use App\Domain\Platform\Support\CountryProfile;
use App\Domain\Pos\Payments\PaymentProviderRegistry;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrganizationResource;
use App\Http\Resources\RegulatoryIdentifierResource;
use App\Support\Exceptions\BusinessRuleViolation;
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
        $base = [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(OrganizationType::class)],
            'country_code' => ['required', 'string', Rule::exists('countries', 'code')->where('is_active', true)],
        ];
        $country = Country::query()->where('is_active', true)->find($request->input('country_code'));
        $data = $request->validate(array_merge_recursive($this->profileRules(), $base, $country ? CountryProfile::of($country)->partyRules() : []));

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
        $data = $request->validate(array_merge_recursive($this->profileRules() + ['name' => ['sometimes', 'string', 'max:255'],
            // Where the organization operates differs from its country's defaults (e.g. a country with several time zones).
            'timezone' => ['sometimes', 'nullable', 'timezone:all'],
            'locale' => ['sometimes', 'nullable', 'string', 'max:10', fn ($a, $v, $fail) => $v === null || in_array(\Locale::canonicalize($v), array_map([\Locale::class, 'canonicalize'], \ResourceBundle::getLocales('') ?: []), true) || $fail('Unknown locale.')],
        ], CountryProfile::of($organization->country)->partyRules()));
        foreach (['timezone', 'locale'] as $key) {
            if (array_key_exists($key, $data)) {
                $settings = $organization->settings ?? [];
                data_set($settings, "locale.{$key}", $data[$key]);
                $organization->settings = $settings;
                unset($data[$key]);
            }
        }
        $organization->fill($data)->save();

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

    /** What the organization's country requires for verification, and what is still missing. */
    public function onboardingRequirements(OnboardingRequirements $requirements): JsonResponse
    {
        Gate::authorize('organization.view');

        return response()->json(['data' => $requirements->for($this->tenant->organization())]);
    }

    /** How customers can pay this organization: cash, a standalone terminal, and the electronic providers enabled in its country and currency. */
    public function paymentMethods(PaymentProviderRegistry $providers): JsonResponse
    {
        Gate::authorize('organization.view');
        $org = $this->tenant->organization();
        $electronic = collect($providers->availableFor($org->country_code, $org->country->currency_code))
            ->map(fn ($p, $code) => ['channel' => $code, 'name' => $p['name'], 'kind' => $p['kind'], 'confirmation' => 'provider_callback'])->values();

        return response()->json(['data' => [
            ['channel' => 'cash', 'name' => 'Cash', 'kind' => 'cash', 'confirmation' => 'immediate'],
            ['channel' => 'manual_terminal', 'name' => 'Standalone card or transfer terminal', 'kind' => 'card', 'confirmation' => 'settlement_check'],
            ...$electronic,
        ], 'meta' => ['country_code' => $org->country_code, 'currency_code' => $org->country->currency_code]]);
    }

    /** The organization's own regulatory identifiers (licences, registrations). */
    public function regulatoryIdentifiers(): JsonResponse
    {
        Gate::authorize('organization.view');
        $rows = RegulatoryIdentifier::query()->with('authority')->where('subject_type', 'organization')->where('subject_id', $this->tenant->id())->orderBy('created_at')->get();

        return response()->json(['data' => RegulatoryIdentifierResource::collection($rows)]);
    }

    public function storeRegulatoryIdentifier(Request $request, RegulatoryIdentifierService $identifiers): JsonResponse
    {
        Gate::authorize('organization.manage');
        $organization = $this->tenant->organization();
        $data = $request->validate(RegulatoryIdentifierRules::rules());
        $authority = RegulatoryAuthority::query()->where('code', $data['authority_code'])->firstOrFail();
        if ($authority->country_code !== $organization->country_code) {
            throw new BusinessRuleViolation('authority_other_country', "{$authority->code} is not a regulator in this organization's country.", 422);
        }
        $identifier = $identifiers->attach($organization, $data, $request->user(), $organization->id);

        return RegulatoryIdentifierResource::make($identifier->load('authority'))->response()->setStatusCode(201);
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
