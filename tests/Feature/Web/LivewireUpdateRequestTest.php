<?php

namespace Tests\Feature\Web;

use App\Domain\Parties\Models\Customer;
use Illuminate\Testing\TestResponse;
use Tests\FeatureTestCase;

/**
 * Real Livewire update requests over HTTP, as the browser sends them (Livewire::test() skips this
 * path). Regression for the 403 on every action of a tenant screen: the tenant middleware that
 * Livewire re-applies to an update cleared the organization before the component ran.
 */
class LivewireUpdateRequestTest extends FeatureTestCase
{
    public function test_actions_on_tenant_screens_run_inside_the_organization(): void
    {
        [$owner, $org] = $this->organizationMember(orgAttributes: ['type' => 'pharmacy']);
        $this->actingAs($owner)->withSession([config('platform.tenancy.session_key') => $org->id]);

        // Opening a form (a component method, no API call).
        foreach (['/customers' => 'parties.customers', '/suppliers' => 'parties.suppliers'] as $path => $component) {
            $this->update($path, $component, [], [['path' => '', 'method' => 'create', 'params' => []]])->assertOk();
        }

        // Then saving it through the in-process API, which writes into the organization.
        $opened = $this->update('/customers', 'parties.customers', [], [['path' => '', 'method' => 'create', 'params' => []]])->assertOk();
        $this->send($opened->json('components.0.snapshot'), ['party.code' => 'LW-1', 'party.name' => 'Livewire Request Clinic'],
            [['path' => '', 'method' => 'save', 'params' => []]])->assertOk();
        $this->assertSame($org->id, Customer::acrossTenants()->where('code', 'LW-1')->value('organization_id'));
    }

    public function test_a_member_without_the_permission_is_still_refused_on_update(): void
    {
        [$owner, $org] = $this->organizationMember(orgAttributes: ['type' => 'pharmacy']);
        [$cashier] = $this->organizationMember(['pos_cashier'], organization: $org);

        // The page itself is refused to the cashier, so take a snapshot as the owner and replay it as the cashier.
        $this->actingAs($owner)->withSession([config('platform.tenancy.session_key') => $org->id]);
        $snapshot = $this->snapshot('/suppliers', 'parties.suppliers');
        $this->actingAs($cashier)->withSession([config('platform.tenancy.session_key') => $org->id]);
        $this->send($snapshot, [], [['path' => '', 'method' => 'create', 'params' => []]])->assertForbidden();
    }

    /** GET the page, then POST an update to its component. */
    private function update(string $path, string $component, array $updates, array $calls): TestResponse
    {
        return $this->send($this->snapshot($path, $component), $updates, $calls);
    }

    private function send(string $snapshot, array $updates, array $calls): TestResponse
    {
        // Livewire remembers per process which routes' persistent middleware it already applied; a real
        // request starts afresh, a test process does not, so reset it as each new request would.
        app('livewire')->flushState();

        return $this->postJson($this->updateUrl(), ['components' => [['snapshot' => $snapshot, 'updates' => $updates, 'calls' => $calls]]], ['X-Livewire' => '1']);
    }

    private function snapshot(string $path, string $component): string
    {
        $html = $this->get($path)->assertOk()->getContent();
        preg_match_all('/wire:snapshot="([^"]+)"/', $html, $m);
        foreach ($m[1] as $encoded) {
            $snapshot = html_entity_decode($encoded, ENT_QUOTES);
            if ((json_decode($snapshot, true)['memo']['name'] ?? null) === $component) {
                return $snapshot;
            }
        }
        $this->fail("No snapshot for {$component} on {$path}.");
    }

    private function updateUrl(): string
    {
        return '/'.ltrim(app('router')->getRoutes()->getByName('default-livewire.update')->uri(), '/');
    }
}
