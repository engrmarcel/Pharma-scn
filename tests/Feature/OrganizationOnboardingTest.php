<?php

namespace Tests\Feature;

use App\Domain\Access\Models\Role;
use App\Domain\Platform\Models\Attachment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\FeatureTestCase;

class OrganizationOnboardingTest extends FeatureTestCase
{
    public function test_registering_an_organization_makes_the_user_owner_with_default_modules(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAsApi($user)->api('POST', 'organizations', [
            'name' => 'Lekki Community Pharmacy',
            'type' => 'pharmacy',
            'country_code' => 'NG',
            'city' => 'Lagos',
        ])->assertCreated()
            ->assertJsonPath('data.platform_verification.status', 'unverified')
            ->assertJsonPath('data.status', 'active');

        $this->assertEqualsCanonicalizing(['catalogue', 'inventory', 'procurement', 'sales', 'pos'], $response->json('data.modules'));

        $orgId = $response->json('data.id');
        $this->actingAsApi($user)->api('GET', 'auth/me')
            ->assertJsonPath('data.memberships.0.organization.id', $orgId)
            ->assertJsonPath('data.memberships.0.roles.0.code', 'org_owner');
        $this->assertDatabaseHas('audit_logs', ['action' => 'organization.registered', 'organization_id' => $orgId]);
    }

    public function test_registration_only_allows_active_operating_countries(): void
    {
        $this->actingAsApi(User::factory()->create())->api('POST', 'organizations', [
            'name' => 'Accra Pharmacy', 'type' => 'pharmacy', 'country_code' => 'GH',
        ])->assertUnprocessable()->assertJsonValidationErrors('country_code');
    }

    public function test_client_cannot_self_assign_verified_status(): void
    {
        $response = $this->actingAsApi(User::factory()->create())->api('POST', 'organizations', [
            'name' => 'Sneaky', 'type' => 'pharmacy', 'country_code' => 'NG', 'verification_status' => 'verified',
        ]);

        $response->assertCreated()->assertJsonPath('data.platform_verification.status', 'unverified');
    }

    public function test_full_verification_workflow(): void
    {
        Storage::fake('local');
        [$owner, $org] = $this->organizationMember();
        $officer = $this->platformUser('compliance_officer');

        // Submission requires the documents the country requires.
        $this->actingAsApi($owner, $org)->api('POST', 'organization/verification/submit')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'verification_documents_missing');

        $doc = $this->api('POST', 'organization/documents', [
            'category' => 'business_registration',
            'file' => UploadedFile::fake()->create('cac-certificate.pdf', 120, 'application/pdf'),
        ])->assertCreated()->json('data');

        $stored = Attachment::query()->findOrFail($doc['id']);
        $this->assertStringStartsWith("tenants/{$org->id}/", $stored->path);
        Storage::disk('local')->assertExists($stored->path);

        $this->api('POST', 'organization/verification/submit')->assertOk()
            ->assertJsonPath('data.platform_verification.status', 'pending_review');

        // Cannot submit twice.
        $this->api('POST', 'organization/verification/submit')->assertStatus(409);

        // Rejection needs notes.
        $this->actingAsApi($officer)->api('POST', "admin/organizations/{$org->id}/verification-decisions", ['decision' => 'request_changes'])
            ->assertUnprocessable()->assertJsonPath('error.code', 'verification_notes_required');

        $this->api('POST', "admin/organizations/{$org->id}/verification-decisions", ['decision' => 'request_changes', 'notes' => 'Certificate is illegible'])
            ->assertOk()->assertJsonPath('data.platform_verification.status', 'changes_requested');

        $this->actingAsApi($owner, $org)->api('POST', 'organization/verification/submit')->assertOk();

        $this->actingAsApi($officer)->api('POST', "admin/organizations/{$org->id}/verification-decisions", ['decision' => 'approve'])
            ->assertOk()
            ->assertJsonPath('data.platform_verification.status', 'verified');

        $history = $this->api('GET', "admin/organizations/{$org->id}")->assertOk()->json('verification_history');
        $this->assertSame(['approved', 'submitted', 'changes_requested', 'submitted'], array_column($history, 'action'));
        $this->assertNotNull($org->fresh()->verified_at);
    }

    public function test_reviewer_cannot_decide_on_their_own_organization(): void
    {
        [$owner, $org] = $this->organizationMember();
        $owner->platformRoles()->attach(Role::query()->platform()->where('code', 'compliance_officer')->first());
        $org->forceFill(['verification_status' => 'pending_review'])->save();

        $this->actingAsApi($owner)->api('POST', "admin/organizations/{$org->id}/verification-decisions", ['decision' => 'approve'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'review_conflict_of_interest');
    }

    public function test_cannot_approve_an_organization_that_is_not_pending(): void
    {
        [, $org] = $this->organizationMember();
        $officer = $this->platformUser('compliance_officer');

        $this->actingAsApi($officer)->api('POST', "admin/organizations/{$org->id}/verification-decisions", ['decision' => 'approve'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'verification_transition_invalid');
    }

    public function test_document_uploads_are_validated(): void
    {
        Storage::fake('local');
        [$owner, $org] = $this->organizationMember();

        $this->actingAsApi($owner, $org)->api('POST', 'organization/documents', [
            'category' => 'business_registration',
            'file' => UploadedFile::fake()->create('payload.exe', 10, 'application/x-msdownload'),
        ])->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_documents_of_another_organization_cannot_be_downloaded(): void
    {
        Storage::fake('local');
        [$alice, $orgA] = $this->organizationMember();
        [$bob, $orgB] = $this->organizationMember();

        $docId = $this->actingAsApi($bob, $orgB)->api('POST', 'organization/documents', [
            'category' => 'business_registration',
            'file' => UploadedFile::fake()->create('cert.pdf', 10, 'application/pdf'),
        ])->json('data.id');

        $this->actingAsApi($alice, $orgA)->api('GET', "organization/documents/{$docId}")->assertNotFound();
        $this->actingAsApi($bob, $orgB)->get("/api/v1/organization/documents/{$docId}", ['X-Organization-Id' => $orgB->id])->assertOk();
    }

    public function test_platform_can_suspend_and_reactivate_without_deleting_data(): void
    {
        [$owner, $org] = $this->organizationMember();
        $ops = $this->platformUser('platform_operations_admin');

        $this->actingAsApi($ops)->api('POST', "admin/organizations/{$org->id}/suspend", ['reason' => 'Licence lapsed'])
            ->assertOk()->assertJsonPath('data.status', 'suspended');
        $this->actingAsApi($owner, $org)->api('GET', 'organization')->assertForbidden();

        $this->actingAsApi($ops)->api('POST', "admin/organizations/{$org->id}/reactivate")->assertOk();
        $this->actingAsApi($owner, $org)->api('GET', 'organization')->assertOk();
    }

    public function test_platform_can_toggle_modules_and_disabled_modules_are_enforced(): void
    {
        [$owner, $org] = $this->organizationMember();
        $ops = $this->platformUser('platform_operations_admin');

        $this->actingAsApi($ops)->api('PUT', "admin/organizations/{$org->id}/modules", ['modules' => ['catalogue' => false]])->assertOk();

        $this->actingAsApi($owner, $org)->api('GET', 'catalogue/items')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'module_not_enabled');
    }
}
