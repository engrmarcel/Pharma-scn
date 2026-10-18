<?php

namespace Tests\Feature;

use App\Domain\Access\Services\SecurityPolicy;
use App\Livewire\Account\SecuritySettings;
use App\Livewire\Admin\VerificationQueue;
use App\Livewire\Catalogue\ProductBrowser;
use App\Models\User;
use Livewire\Livewire;
use Tests\FeatureTestCase;

class WebInterfaceTest extends FeatureTestCase
{
    public function test_login_page_and_successful_login(): void
    {
        User::factory()->create(['email' => 'web@example.test', 'password' => 'Secret12345']);

        $this->get('/login')->assertOk()->assertSee('Sign in');
        $this->post('/login', ['email' => 'web@example.test', 'password' => 'Secret12345'])->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertOk()->assertSee('Your organizations');
    }

    public function test_suspended_users_cannot_log_in_on_the_web(): void
    {
        User::factory()->create(['email' => 'sus@example.test', 'password' => 'Secret12345'])->forceFill(['status' => 'suspended'])->save();

        $this->post('/login', ['email' => 'sus@example.test', 'password' => 'Secret12345'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_dashboard_shows_memberships_and_switcher_rejects_foreign_organizations(): void
    {
        [$owner, $org] = $this->organizationMember();
        [, $foreign] = $this->organizationMember();

        $this->actingAs($owner)->get('/dashboard')->assertOk()->assertSee($org->name)->assertDontSee($foreign->name);

        $this->post('/organizations/switch', ['organization_id' => $foreign->id])->assertSessionHasErrors('organization_id');
        $this->post('/organizations/switch', ['organization_id' => $org->id])->assertRedirect('/dashboard')
            ->assertSessionHas(config('platform.tenancy.session_key'), $org->id);
    }

    public function test_catalogue_browser_searches_and_hides_products_under_review(): void
    {
        [$owner] = $this->organizationMember();
        $active = $this->makeProduct(['brand_name' => 'Visiblemox']);
        $this->makeProduct(['brand_name' => 'Hiddenmox', 'status' => 'under_review', 'identifiers' => []]);

        Livewire::actingAs($owner)->test(ProductBrowser::class)
            ->assertSee('Visiblemox')
            ->assertDontSee('Hiddenmox')
            ->set('q', 'visible')
            ->assertSee('Visiblemox')
            ->call('select', $active->id)
            ->assertSee('Active ingredients');
    }

    public function test_account_security_page_reflects_policy(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)->test(SecuritySettings::class)
            ->assertSee('not enabled on this platform');

        app(SecurityPolicy::class)->update(['mfa_mode' => 'optional'], $user);

        Livewire::actingAs($user)->test(SecuritySettings::class)
            ->set('password', 'wrong')->call('startSetup')->assertHasErrors('password')
            ->set('password', 'password')->call('startSetup')
            ->assertSee('Scan this code');
    }

    public function test_verification_queue_is_restricted_to_platform_staff(): void
    {
        [$owner] = $this->organizationMember();

        $this->actingAs($owner)->get('/admin/verifications')->assertForbidden();
    }

    public function test_compliance_officer_can_approve_from_the_queue_but_operations_cannot(): void
    {
        [, $org] = $this->organizationMember();
        $org->forceFill(['verification_status' => 'pending_review'])->save();
        $officer = $this->platformUser('compliance_officer');
        $ops = $this->platformUser('platform_operations_admin');

        // Ops can view the queue but not decide.
        Livewire::actingAs($ops)->test(VerificationQueue::class)
            ->assertSee($org->name)
            ->call('review', $org->id)
            ->call('decide', 'approve')
            ->assertForbidden();

        Livewire::actingAs($officer)->test(VerificationQueue::class)
            ->call('review', $org->id)
            ->call('decide', 'reject')
            ->assertHasErrors('notes')
            ->call('decide', 'approve')
            ->assertHasNoErrors();

        $this->assertSame('verified', $org->fresh()->verification_status->value);
    }
}
