<?php

namespace Tests\Feature;

use App\Domain\Organizations\Models\Branch;
use App\Domain\Organizations\Models\Organization;
use App\Models\User;
use Tests\FeatureTestCase;

class IdempotencyTest extends FeatureTestCase
{
    public function test_retrying_with_the_same_key_replays_without_duplicating(): void
    {
        $user = User::factory()->create();
        $body = ['name' => 'Retry Pharmacy', 'type' => 'pharmacy', 'country_code' => 'NG'];
        $headers = ['Idempotency-Key' => 'onboard-attempt-0001'];

        $first = $this->actingAsApi($user)->api('POST', 'organizations', $body, $headers)->assertCreated();
        $second = $this->api('POST', 'organizations', $body, $headers)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Organization::query()->where('name', 'Retry Pharmacy')->count());
    }

    public function test_reusing_a_key_for_a_different_request_is_rejected(): void
    {
        [$owner, $org] = $this->organizationMember();
        $headers = ['Idempotency-Key' => 'branch-create-0001'];

        $this->actingAsApi($owner, $org)->api('POST', 'branches', ['code' => 'B1', 'name' => 'One'], $headers)->assertCreated();
        $this->api('POST', 'branches', ['code' => 'B2', 'name' => 'Two'], $headers)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'idempotency_key_reused');

        $this->assertSame(1, $this->inTenant($org, fn () => Branch::query()->count()));
    }

    public function test_keys_are_scoped_per_user_and_tenant(): void
    {
        [$alice, $orgA] = $this->organizationMember();
        [$bob, $orgB] = $this->organizationMember();
        $headers = ['Idempotency-Key' => 'shared-key-123'];

        $this->actingAsApi($alice, $orgA)->api('POST', 'branches', ['code' => 'HQ', 'name' => 'HQ'], $headers)->assertCreated();
        $this->actingAsApi($bob, $orgB)->api('POST', 'branches', ['code' => 'HQ', 'name' => 'HQ'], $headers)
            ->assertCreated()
            ->assertHeaderMissing('Idempotent-Replayed');

        $this->assertSame(2, Branch::acrossTenants()->count());
    }

    public function test_failed_validation_does_not_burn_the_key(): void
    {
        [$owner, $org] = $this->organizationMember();
        $headers = ['Idempotency-Key' => 'fix-and-retry-01'];

        $this->actingAsApi($owner, $org)->api('POST', 'branches', ['code' => 'B1'], $headers)->assertUnprocessable();
        // Corrected body with the same key now works.
        $this->api('POST', 'branches', ['code' => 'B1', 'name' => 'Fixed'], $headers)->assertCreated();
    }

    public function test_malformed_keys_are_rejected(): void
    {
        [$owner, $org] = $this->organizationMember();

        $this->actingAsApi($owner, $org)->api('POST', 'branches', ['code' => 'B1', 'name' => 'x'], ['Idempotency-Key' => 'bad key!'])
            ->assertStatus(400)->assertJsonPath('error.code', 'idempotency_key_invalid');
    }
}
