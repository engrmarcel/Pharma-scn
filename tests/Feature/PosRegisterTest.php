<?php

namespace Tests\Feature;

use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Pos\Models\PosSale;
use App\Domain\Pos\Services\PosSessionService;
use App\Livewire\Pos\Register;
use App\Support\Tenancy\TenantContext;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithInventory;
use Tests\FeatureTestCase;

class PosRegisterTest extends FeatureTestCase
{
    use InteractsWithInventory;

    private function setUpTill(): array
    {
        [$owner, $org, $product, $main] = $this->stockedOrganization();
        [$cashier, , $membership] = $this->organizationMember(['pos_cashier'], organization: $org);
        $this->receive($org, $main, $product, 'R1', '100', '+1 year', ['unit_cost' => '50']);
        $this->actingAsApi($owner, $org)->api('POST', 'price-lists', ['code' => 'STD', 'name' => 'Standard', 'is_default' => true,
            'items' => [['product_id' => $product->id, 'package_level_code' => 'capsule', 'unit_price' => '150']]])->assertCreated();
        $terminal = $this->inTenant($org, function () use ($main) {
            app(TenantContext::class)->set(app(TenantContext::class)->organization(), OrganizationMembership::query()->first());

            return app(PosSessionService::class)->registerTerminal($main, 'T1', 'Counter');
        });

        // Livewire::test bypasses route middleware: establish the tenant as ResolveTenant would.
        app(TenantContext::class)->set($org, $membership);

        return compact('org', 'product', 'cashier', 'terminal', 'membership');
    }

    public function test_cashier_opens_shift_scans_sells_for_cash_and_gets_a_receipt(): void
    {
        $t = $this->setUpTill();

        $component = Livewire::actingAs($t['cashier'])->test(Register::class)
            ->assertSee('Open a shift')
            ->set('terminalId', $t['terminal']->id)->set('openingFloat', '1000')->call('openShift')
            ->assertHasNoErrors()->assertSee('drawer');

        // A scanner types the SKU and presses Enter: the exact match is added straight to the cart.
        $component->set('search', 'AMX')->call('scan')
            ->assertSet('search', '')->assertCount('cart', 1)
            ->call('setQuantity', 0, '4')
            ->assertSee('600.0000')
            ->call('checkout')
            ->set('tendered', '1000')->call('payCash')
            ->assertSee('completed')->assertSee('400.0000');

        $sale = PosSale::acrossTenants()->where('organization_id', $t['org']->id)->sole();
        $this->assertSame('completed', $sale->status);
        $this->assertSame('600.0000', (string) $sale->total);

        $this->actingAs($t['cashier'])->withSession([config('platform.tenancy.session_key') => $t['org']->id])
            ->get(route('pos.receipt', $sale->id))->assertOk()->assertSee($sale->number)->assertSee('Batch R1');
    }

    public function test_register_reports_business_rule_errors_instead_of_failing(): void
    {
        $t = $this->setUpTill();
        Livewire::actingAs($t['cashier'])->test(Register::class)
            ->set('terminalId', $t['terminal']->id)->call('openShift')
            ->call('add', $t['product']->id)
            ->call('setQuantity', 0, '500') // more than in stock
            ->call('checkout')
            ->assertHasErrors('register')
            ->assertSee('Not enough sellable stock');
    }

    public function test_register_page_requires_a_selected_organization_with_the_pos_module(): void
    {
        $t = $this->setUpTill();
        app(TenantContext::class)->clear();

        $this->actingAs($t['cashier'])->get('/pos')->assertStatus(400);
        $this->withSession([config('platform.tenancy.session_key') => $t['org']->id])->get('/pos')->assertOk()->assertSee('Open a shift');

        [, $otherOrg] = $this->organizationMember();
        $this->withSession([config('platform.tenancy.session_key') => $otherOrg->id])->get('/pos')->assertForbidden();
    }

    public function test_register_requires_pos_permission(): void
    {
        $t = $this->setUpTill();
        [$auditor, , $membership] = $this->organizationMember(['auditor'], organization: $t['org']);
        app(TenantContext::class)->set($t['org'], $membership);

        Livewire::actingAs($auditor)->test(Register::class)->assertForbidden();
    }
}
