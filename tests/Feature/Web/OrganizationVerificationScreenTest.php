<?php

namespace Tests\Feature\Web;

use App\Domain\Organizations\Models\OrganizationMembership;
use App\Livewire\Admin\VerificationQueue;
use App\Livewire\Organization\Profile;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\FeatureTestCase;

/** Platform verification from the screens: upload, submit, review with the documents, changes requested, approval. */
class OrganizationVerificationScreenTest extends FeatureTestCase
{
    public function test_the_upload_action_is_not_named_after_livewires_own_upload_helper(): void
    {
        // $wire.upload() is Livewire's file-upload helper in the browser: wire:submit="upload" never reaches the server.
        $view = file_get_contents(resource_path('views/livewire/organization/profile.blade.php'));
        $this->assertStringNotContainsString('wire:submit="upload"', $view);
        $this->assertStringContainsString('wire:submit="uploadDocument"', $view);
    }

    public function test_upload_submit_review_and_approve_from_the_screens(): void
    {
        Storage::fake('local');
        [$owner, $org] = $this->organizationMember();
        $officer = $this->platformUser('compliance_officer');
        app(TenantContext::class)->set($org, OrganizationMembership::query()->where('organization_id', $org->id)->where('user_id', $owner->id)->sole());

        // Nothing uploaded yet: submitting names what is missing.
        $profile = Livewire::actingAs($owner)->test(Profile::class)
            ->call('submitForVerification')
            ->assertDispatched('notify', fn ($name, $p) => $p['type'] === 'error' && str_contains($p['message'], 'document: business registration') && str_contains($p['message'], 'address line 1'));

        foreach (['business_registration' => 'cac.pdf', 'premises_licence' => 'premises.pdf'] as $category => $file) {
            $profile->set('documentCategory', $category)->set('document', UploadedFile::fake()->create($file, 40, 'application/pdf'))
                ->call('uploadDocument')->assertHasNoErrors()->assertSee($file);
        }
        $this->assertSame(2, $org->documents()->count());

        $profile->call('addIdentifier', 'PCN', 'licence_number')->set('identifier.value', 'DEMO-PCN-0001')->call('saveIdentifier')->assertHasNoErrors()
            ->call('edit')->set('form.address_line1', '1 Demo Street')->set('form.state_region', 'Lagos')->call('save')->assertHasNoErrors()
            ->call('submitForVerification')->assertDispatched('notify', fn ($name, $p) => $p['type'] === 'success')
            ->assertSee('compliance team is reviewing');
        $this->assertSame('pending_review', $org->fresh()->verification_status->value);

        // The reviewer sees the details, licences and documents, opens a document, and asks for changes.
        app(TenantContext::class)->clear();
        $queue = Livewire::actingAs($officer)->test(VerificationQueue::class)->assertSee($org->name)
            ->call('review', $org->id)->assertSee('cac.pdf')->assertSee('DEMO-PCN-0001')->assertSee('1 Demo Street');
        $queue->call('downloadDocument', $org->documents()->where('category', 'business_registration')->value('id'))->assertFileDownloaded('cac.pdf');
        $queue->set('notes', 'The certificate is illegible')->call('decide', 'request_changes')->assertHasNoErrors()->assertDispatched('notify');

        // The organization sees the reviewer's note, resubmits, and is approved.
        app(TenantContext::class)->set($org->fresh(), OrganizationMembership::query()->where('organization_id', $org->id)->where('user_id', $owner->id)->sole());
        Livewire::actingAs($owner)->test(Profile::class)->assertSee('The reviewer asked for changes')->assertSee('The certificate is illegible')
            ->call('submitForVerification')->assertSee('compliance team is reviewing');

        app(TenantContext::class)->clear();
        Livewire::actingAs($officer)->test(VerificationQueue::class)->call('review', $org->id)->call('decide', 'approve')->assertHasNoErrors();
        $this->assertSame('verified', $org->fresh()->verification_status->value);
    }
}
