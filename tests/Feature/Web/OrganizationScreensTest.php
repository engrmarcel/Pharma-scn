<?php

namespace Tests\Feature\Web;

use App\Domain\Organizations\Models\Warehouse;
use App\Livewire\Organization\AuditTrail;
use App\Livewire\Organization\Locations;
use App\Livewire\Organization\Members;
use App\Livewire\Organization\Profile;
use App\Livewire\Organization\Subscription;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\FeatureTestCase;

class OrganizationScreensTest extends FeatureTestCase
{
    private function asOwner(): array
    {
        [$owner, $org, $membership] = $this->organizationMember(orgAttributes: ['type' => 'pharmacy']);
        app(TenantContext::class)->set($org, $membership);

        return [$owner, $org];
    }

    public function test_every_organization_page_renders_for_the_owner(): void
    {
        [$owner, $org] = $this->asOwner();
        $this->actingAs($owner)->withSession([config('platform.tenancy.session_key') => $org->id]);

        foreach (['organization.profile' => 'Verification checklist', 'organization.locations' => 'Warehouses and stores', 'organization.members' => 'Invite member',
            'organization.subscription' => 'Limits and usage', 'organization.audit' => 'Audit log'] as $route => $text) {
            $this->get(route($route))->assertOk()->assertSee($text);
        }
        // The menu lists the organization pages; section tabs link the siblings.
        $this->get(route('organization.profile'))->assertSee(route('organization.members'), false);
    }

    public function test_profile_edit_identifier_and_document_upload_go_through_the_api(): void
    {
        Storage::fake('local');
        [$owner, $org] = $this->asOwner();

        Livewire::actingAs($owner)->test(Profile::class)
            ->call('edit')->set('form.legal_name', 'Web Pharmacy Limited')->set('form.website_url', 'not a url')->call('save')
            ->assertHasErrors('form.website_url')
            ->set('form.website_url', 'https://example.test')->call('save')->assertHasNoErrors()->assertSet('form', null)
            ->set('document', UploadedFile::fake()->create('cac.pdf', 20, 'application/pdf'))->set('documentCategory', 'business_registration')
            ->call('upload')->assertHasNoErrors()->assertSee('cac.pdf');

        $this->assertSame('Web Pharmacy Limited', $org->fresh()->legal_name);
        $this->assertSame(1, $org->documents()->count());
    }

    public function test_locations_and_members(): void
    {
        [$owner, $org] = $this->asOwner();

        Livewire::actingAs($owner)->test(Locations::class)
            ->call('editWarehouse')->set('warehouse.code', 'MAIN')->set('warehouse.name', 'Main store')->call('saveWarehouse')
            ->assertHasNoErrors()->assertSee('Main store')
            ->call('editWarehouse')->set('warehouse.code', 'MAIN')->set('warehouse.name', 'Duplicate')->call('saveWarehouse')
            ->assertHasErrors('warehouse.code');
        $this->assertSame(1, app(TenantContext::class)->runAs($org, fn () => Warehouse::query()->count()));

        Livewire::actingAs($owner)->test(Members::class)
            ->call('startInvite')->set('invite.email', 'new.member@example.test')->set('invite.roles', ['pharmacist'])->call('sendInvite')
            ->assertHasNoErrors()->assertSee('Invitation created for new.member@example.test');
    }

    public function test_members_without_permission_are_refused(): void
    {
        [, $org] = $this->organizationMember();
        [$cashier, , $membership] = $this->organizationMember(['pos_cashier'], organization: $org);
        app(TenantContext::class)->set($org, $membership);

        Livewire::actingAs($cashier)->test(AuditTrail::class)->assertForbidden();
        Livewire::actingAs($cashier)->test(Subscription::class)->assertForbidden();
    }
}
