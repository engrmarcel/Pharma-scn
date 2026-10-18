<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\FeatureTestCase;

class AuthenticationTest extends FeatureTestCase
{
    public function test_user_can_obtain_and_use_a_token(): void
    {
        $user = User::factory()->create(['email' => 'pharm@example.test', 'password' => 'Secret12345']);

        $token = $this->postJson('/api/v1/auth/tokens', ['email' => 'pharm@example.test', 'password' => 'Secret12345', 'device_name' => 'phpunit'])
            ->assertCreated()
            ->assertJsonStructure(['data' => ['token', 'token_type', 'expires_at']])
            ->json('data.token');

        $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath('data.email', 'pharm@example.test');

        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.token_issued', 'actor_user_id' => $user->id]);
    }

    public function test_wrong_password_is_rejected_without_revealing_which_field(): void
    {
        User::factory()->create(['email' => 'pharm@example.test', 'password' => 'Secret12345']);

        $this->postJson('/api/v1/auth/tokens', ['email' => 'pharm@example.test', 'password' => 'nope', 'device_name' => 'x'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_suspended_user_cannot_obtain_a_token(): void
    {
        User::factory()->create(['email' => 's@example.test', 'password' => 'Secret12345'])->forceFill(['status' => 'suspended'])->save();

        $this->postJson('/api/v1/auth/tokens', ['email' => 's@example.test', 'password' => 'Secret12345', 'device_name' => 'x'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'account_suspended')
            ->assertJsonStructure(['correlation_id']);
    }

    public function test_login_is_throttled(): void
    {
        User::factory()->create(['email' => 't@example.test', 'password' => 'Secret12345']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/tokens', ['email' => 't@example.test', 'password' => 'bad', 'device_name' => 'x'])->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/tokens', ['email' => 't@example.test', 'password' => 'Secret12345', 'device_name' => 'x'])
            ->assertStatus(429);
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('device')->plainTextToken;

        $this->deleteJson('/api/v1/auth/tokens/current', [], ['Authorization' => "Bearer {$token}"])->assertNoContent();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_user_can_revoke_another_of_their_devices_but_not_someone_elses(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $current = $user->createToken('phone')->plainTextToken;
        $tablet = $user->createToken('tablet')->accessToken;
        $foreign = $other->createToken('theirs')->accessToken;

        $this->deleteJson("/api/v1/auth/tokens/{$foreign->id}", [], ['Authorization' => "Bearer {$current}"])->assertNotFound();
        $this->deleteJson("/api/v1/auth/tokens/{$tablet->id}", [], ['Authorization' => "Bearer {$current}"])->assertNoContent();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tablet->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $foreign->id]);
    }

    public function test_suspending_a_user_revokes_tokens_and_blocks_access(): void
    {
        $admin = $this->platformUser('platform_operations_admin');
        $target = User::factory()->create();
        $token = $target->createToken('device')->plainTextToken;

        $this->actingAsApi($admin)->api('POST', "admin/users/{$target->id}/suspend", ['reason' => 'Fraud investigation'])->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$token}"])->assertUnauthorized();
    }

    public function test_responses_carry_a_correlation_id_and_security_headers(): void
    {
        $this->getJson('/api/v1/branding', ['X-Correlation-Id' => 'trace-abc-12345'])
            ->assertOk()
            ->assertHeader('X-Correlation-Id', 'trace-abc-12345')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }
}
