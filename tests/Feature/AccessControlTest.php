<?php

namespace Tests\Feature;

use App\Domain\Organizations\Models\Branch;
use App\Domain\Organizations\Services\MembershipService;
use App\Models\User;
use App\Notifications\OrganizationInvitationNotification;
use Illuminate\Support\Facades\Notification;
use Tests\FeatureTestCase;

class AccessControlTest extends FeatureTestCase
{
    public function test_permissions_are_enforced_server_side_per_role(): void
    {
        [$cashier, $org] = $this->organizationMember(['pos_cashier']);

        $this->actingAsApi($cashier, $org)->api('GET', 'organization')->assertOk();
        $this->api('POST', 'branches', ['code' => 'B1', 'name' => 'Branch'])->assertForbidden();
        $this->api('GET', 'branches')->assertForbidden();
        $this->api('GET', 'audit-logs')->assertForbidden();
    }

    public function test_auditor_is_read_only(): void
    {
        [$auditor, $org] = $this->organizationMember(['auditor']);

        $this->actingAsApi($auditor, $org)->api('GET', 'branches')->assertOk();
        $this->api('GET', 'audit-logs')->assertOk();
        $this->api('PATCH', 'organization', ['name' => 'Changed'])->assertForbidden();
    }

    public function test_platform_super_admin_has_no_implicit_access_to_tenant_data(): void
    {
        $admin = $this->platformUser('platform_super_admin');
        [, $org] = $this->organizationMember();
        $this->inTenant($org, fn () => Branch::query()->create(['code' => 'HQ', 'name' => 'HQ']));

        $this->actingAsApi($admin, $org)->api('GET', 'branches')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'organization_access_denied');
    }

    public function test_tenant_owner_cannot_use_platform_admin_endpoints(): void
    {
        [$owner, $org] = $this->organizationMember();

        $this->actingAsApi($owner)->api('GET', 'admin/organizations')->assertForbidden();
        $this->api('POST', "admin/organizations/{$org->id}/verification-decisions", ['decision' => 'approve'])->assertForbidden();
        $this->api('PUT', 'admin/branding', ['display_name' => 'Mine'])->assertForbidden();
    }

    public function test_suspended_membership_loses_access(): void
    {
        [$owner, $org] = $this->organizationMember();
        [$member, , $membership] = $this->organizationMember(['inventory_manager'], organization: $org);

        $this->actingAsApi($owner, $org)->api('PUT', "members/{$membership->id}/status", ['status' => 'suspended'])->assertOk();

        $this->actingAsApi($member, $org)->api('GET', 'organization')->assertForbidden();
    }

    public function test_role_changes_take_effect_on_the_next_request(): void
    {
        [$owner, $org] = $this->organizationMember();
        [$member, , $membership] = $this->organizationMember(['pos_cashier'], organization: $org);

        $this->actingAsApi($member, $org)->api('GET', 'branches')->assertForbidden();

        $this->actingAsApi($owner, $org)->api('PUT', "members/{$membership->id}/roles", ['roles' => ['inventory_manager']])->assertOk();

        $this->actingAsApi($member, $org)->api('GET', 'branches')->assertOk();
    }

    public function test_the_last_owner_cannot_be_removed(): void
    {
        [$owner, $org, $ownerMembership] = $this->organizationMember();
        [$admin, , $adminMembership] = $this->organizationMember(['org_admin'], organization: $org);

        $this->actingAsApi($owner, $org)->api('PUT', "members/{$ownerMembership->id}/roles", ['roles' => ['org_admin']])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'last_owner');

        // An org admin cannot touch owners or the owner role at all.
        $this->actingAsApi($admin, $org)->api('PUT', "members/{$ownerMembership->id}/status", ['status' => 'suspended'])
            ->assertForbidden();
        $this->api('PUT', "members/{$adminMembership->id}/roles", ['roles' => ['org_owner']])->assertForbidden();
    }

    public function test_invitation_flow_grants_membership_with_requested_roles(): void
    {
        Notification::fake();
        [$owner, $org] = $this->organizationMember();
        $invitee = User::factory()->create(['email' => 'new.pharmacist@example.test']);

        $this->actingAsApi($owner, $org)->api('POST', 'invitations', ['email' => 'New.Pharmacist@example.test', 'roles' => ['pharmacist']])
            ->assertCreated()
            ->assertJsonMissingPath('data.token');

        $sent = null;
        Notification::assertSentOnDemand(OrganizationInvitationNotification::class, function ($notification) use (&$sent) {
            $sent = $notification->token;

            return true;
        });
        // Only a hash of the token is stored.
        $this->assertDatabaseMissing('organization_invitations', ['token_hash' => $sent]);
        $this->assertDatabaseHas('organization_invitations', ['token_hash' => hash('sha256', $sent)]);

        $this->actingAsApi($invitee)->api('POST', 'invitations/accept', ['token' => $sent])
            ->assertCreated()
            ->assertJsonPath('data.roles.0.code', 'pharmacist');

        $this->actingAsApi($invitee, $org)->api('GET', 'organization')->assertOk();
        // Single use.
        $this->actingAsApi($invitee)->api('POST', 'invitations/accept', ['token' => $sent])->assertNotFound();
    }

    public function test_invitation_cannot_be_accepted_by_a_different_email(): void
    {
        Notification::fake();
        [$owner, $org] = $this->organizationMember();
        [, $token] = app(MembershipService::class)->invite($org, 'intended@example.test', ['pharmacist'], $owner);

        $this->actingAsApi(User::factory()->create())->api('POST', 'invitations/accept', ['token' => $token])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'invitation_email_mismatch');
    }

    public function test_cannot_invite_with_roles_that_do_not_exist_or_belong_to_platform(): void
    {
        [$owner, $org] = $this->organizationMember();

        $this->actingAsApi($owner, $org)->api('POST', 'invitations', ['email' => 'x@example.test', 'roles' => ['platform_super_admin']])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'roles_invalid');
    }
}
