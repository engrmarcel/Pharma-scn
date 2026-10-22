<?php

namespace Tests\Feature\Web;

use App\Domain\Catalogue\Models\Manufacturer;
use App\Domain\Traceability\Models\Recall;
use App\Domain\Traceability\Models\RecallNotice;
use App\Domain\Traceability\Models\RegulatoryIncident;
use App\Domain\Traceability\Models\TraceIdentifier;
use App\Livewire\Compliance\Incidents;
use App\Livewire\Compliance\Notices;
use App\Livewire\Compliance\Recalls;
use App\Livewire\Traceability\Events;
use App\Livewire\Traceability\Identifiers;
use App\Livewire\Traceability\Integrity;
use App\Livewire\Traceability\Lots;
use App\Livewire\Traceability\Verify;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithInventory;
use Tests\FeatureTestCase;

class TraceabilityScreensTest extends FeatureTestCase
{
    use InteractsWithInventory;

    public function test_identifiers_scan_events_lot_history_and_integrity(): void
    {
        [$owner, $org, $product, $main, , $membership] = $this->stockedOrganization();
        $this->receive($org, $main, $product, 'TRC-1', '50', '+1 year');
        app(TenantContext::class)->set($org, $membership);

        Livewire::actingAs($owner)->test(Identifiers::class)
            ->call('create', 'issue')->set('form.kind', 'batch')->call('openPicker', 'ident')->set('productSearch', 'AMX')->call('pick', $product->id)
            ->set('form.batch_number', 'TRC-1')->call('save')->assertHasNoErrors()->assertSee('TRC-1');
        $identifier = TraceIdentifier::acrossTenants()->where('organization_id', $org->id)->sole();

        Livewire::actingAs($owner)->test(Identifiers::class)->set('openId', $identifier->id)->assertSee('data:image/svg+xml;base64,', false);

        Livewire::actingAs($owner)->test(Verify::class)
            ->set('code', $identifier->token)->call('check')->assertHasNoErrors()->assertSee('TRC-1')->assertSee('MAIN');

        Livewire::actingAs($owner)->test(Events::class)->assertSee('stock receipt')->assertSee('TRC-1');
        Livewire::withQueryParams(['product' => $product->id, 'batch' => 'TRC-1'])->actingAs($owner)->test(Lots::class)->assertSee('Stock receipt');
        Livewire::actingAs($owner)->test(Integrity::class)->call('check')->assertSee('Intact')
            ->call('export')->assertFileDownloaded();

        Livewire::actingAs($owner)->test(Incidents::class)
            ->call('create')->set('form.description', 'Two packs with the same serial')->call('save')->assertHasNoErrors()->assertSee('suspected falsified');
        $incident = RegulatoryIncident::acrossTenants()->where('organization_id', $org->id)->sole();
        Livewire::actingAs($owner)->test(Incidents::class)->call('start', $incident->id, 'report')->set('acting.authority_reference', 'NAFDAC-123')->call('apply')->assertHasNoErrors();
        $this->assertSame('reported', $incident->fresh()->status);

        $this->actingAs($owner)->withSession([config('platform.tenancy.session_key') => $org->id]);
        foreach (['trace.verify' => 'Scan or type a code', 'trace.identifiers' => 'Issue identifier', 'trace.events' => 'stock receipt', 'trace.lots' => 'Batch number',
            'trace.integrity' => 'EPCIS export', 'recalls.index' => 'No recalls', 'recalls.notices' => 'No recall notices', 'recalls.incidents' => 'Record incident'] as $route => $text) {
            $this->get(route($route))->assertOk()->assertSee($text);
        }
    }

    public function test_brand_owner_recall_reaches_the_holder_as_a_notice(): void
    {
        Notification::fake();
        [$pharmacyOwner, $pharmacy, $product, $main, , $pharmacyMembership] = $this->stockedOrganization();
        $this->receive($pharmacy, $main, $product, 'RCL-9', '40', '+1 year');
        [$mfrOwner, $mfr, $mfrMembership] = $this->organizationMember(orgAttributes: ['type' => 'manufacturer'], verified: true);
        [$mfrAdmin, , $adminMembership] = $this->organizationMember(['org_admin'], organization: $mfr);
        $product->forceFill(['manufacturer_id' => Manufacturer::query()->create(['name' => 'Brand Owner', 'country_code' => 'NG', 'organization_id' => $mfr->id])->id])->saveQuietly();

        app(TenantContext::class)->set($mfr, $mfrMembership);
        Livewire::actingAs($mfrOwner)->test(Recalls::class)
            ->call('create')->call('openPicker', 'recall')->set('productSearch', $product->brand?->name ?? 'Amoxicillin')->call('pick', $product->id)
            ->set('form.batch_numbers', 'rcl-9')->set('form.title', 'Dissolution failure')->set('form.reason', 'Out of specification at stability')
            ->call('save')->assertHasNoErrors()->assertSee('Activate and notify')
            ->call('activate')->assertDispatched('notify', type: 'error');
        $recall = Recall::query()->sole();
        $this->assertSame('draft', $recall->status, 'a second person activates');

        app(TenantContext::class)->set($mfr, $adminMembership);
        Livewire::withQueryParams(['open' => $recall->id])->actingAs($mfrAdmin)->test(Recalls::class)->call('activate')->assertHasNoErrors()->assertSee('Exposure');
        $this->assertSame('active', $recall->fresh()->status);

        app(TenantContext::class)->set($pharmacy, $pharmacyMembership);
        $notice = RecallNotice::acrossTenants()->where('organization_id', $pharmacy->id)->sole();
        Livewire::actingAs($pharmacyOwner)->test(Notices::class)->assertSee('Dissolution failure')
            ->set('openId', $notice->id)->assertSee('Instructions')->set('notes', 'Quarantined 40 units')->call('act', 'acknowledge')->assertHasNoErrors();
        $this->assertSame('acknowledged', $notice->fresh()->status);
    }
}
