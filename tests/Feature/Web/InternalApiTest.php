<?php

namespace Tests\Feature\Web;

use App\Support\Tenancy\TenantContext;
use App\Support\Web\ApiError;
use App\Support\Web\InternalApi;
use Illuminate\Validation\ValidationException;
use Tests\FeatureTestCase;

/** Web screens call the API in-process with the signed-in user and the selected organization (ADR-0024). */
class InternalApiTest extends FeatureTestCase
{
    public function test_calls_run_as_the_user_and_organization_and_leave_the_page_state_intact(): void
    {
        [$owner, $org, $membership] = $this->organizationMember();
        $this->actingAs($owner);
        app(TenantContext::class)->set($org, $membership);
        $api = app(InternalApi::class);

        $this->assertSame($org->id, $api->get('organization')['data']['id']);

        $created = $api->post('warehouses', ['code' => 'W1', 'name' => 'Main store']);
        $this->assertSame('W1', $created['data']['code']);

        // Validation errors come back as field errors, optionally prefixed by the form name.
        try {
            $api->post('warehouses', ['code' => ''], 'form');
            $this->fail('Expected validation errors');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('form.code', $e->errors());
        }

        // The page's own context survives the call.
        $this->assertSame($org->id, app(TenantContext::class)->id());
        $this->assertSame($owner->id, auth()->id());
        $this->assertSame('web', auth()->getDefaultDriver());
    }

    public function test_api_refusals_are_raised_with_their_message_and_status(): void
    {
        [, $org] = $this->organizationMember();
        [$cashier, , $membership] = $this->organizationMember(['pos_cashier'], organization: $org);
        $this->actingAs($cashier);
        app(TenantContext::class)->set($org, $membership);

        try {
            app(InternalApi::class)->post('warehouses', ['code' => 'W2', 'name' => 'Nope']);
            $this->fail('Expected a refusal');
        } catch (ApiError $e) {
            $this->assertSame(403, $e->status);
        }

        // Without an organization the tenant routes refuse.
        app(TenantContext::class)->clear();
        $this->expectException(ApiError::class);
        app(InternalApi::class)->get('warehouses');
    }
}
