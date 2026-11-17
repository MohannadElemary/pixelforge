<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
    }

    public function test_guests_cannot_access_the_studio_or_create_requests(): void
    {
        $this->get('/studio')->assertRedirect('/login');
        $this->postJson('/estimate', [])->assertUnauthorized();
        $this->postJson('/generations', [])->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_login_authenticates_and_logout_clears_encrypted_private_history(): void
    {
        $user = User::factory()->create(['email' => 'alex@example.test', 'password' => 'valid-password-123']);
        $this->postJson('/login', ['email' => $user->email, 'password' => 'incorrect'])->assertUnprocessable();
        $this->assertGuest();
        $this->post('/login', ['email' => $user->email, 'password' => 'valid-password-123', 'remember' => false])->assertRedirect('/studio');
        $this->assertAuthenticatedAs($user);
        $this->get('/studio')->assertViewHas('page', fn (array $page): bool => $page['encryptHistory'] === true);
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
        $this->get('/login')->assertViewHas('page', fn (array $page): bool => $page['clearHistory'] === true && $page['encryptHistory'] === false);
    }

    public function test_existing_mixed_case_email_remains_usable_without_changing_stored_identity(): void
    {
        $user = User::factory()->create([
            'email' => 'Alex.Existing@Example.test',
            'password' => 'existing-password-123',
        ]);

        $this->post('/login', ['email' => $user->email, 'password' => 'existing-password-123'])
            ->assertRedirect('/studio');
        $this->assertAuthenticatedAs($user);
        $this->assertSame('Alex.Existing@Example.test', $user->fresh()->email);
    }

    public function test_login_limits_an_account_and_ip_without_locking_out_another_ip(): void
    {
        $user = User::factory()->create(['email' => 'target@example.test', 'password' => 'valid-password-123']);

        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->postJson('/login', ['email' => $user->email, 'password' => 'incorrect'])->assertUnprocessable();
        }

        $this->postJson('/login', ['email' => $user->email, 'password' => 'valid-password-123'])
            ->assertTooManyRequests();
        $this->assertGuest();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.8'])
            ->post('/login', ['email' => $user->email, 'password' => 'valid-password-123'])
            ->assertRedirect('/studio');
        $this->assertAuthenticatedAs($user);
    }

    public function test_guest_auth_pages_clear_history_without_an_explicit_logout(): void
    {
        $this->get('/login')->assertViewHas('page', fn (array $page): bool => $page['clearHistory'] === true);
        $this->get('/register')->assertViewHas('page', fn (array $page): bool => $page['clearHistory'] === true);
    }
}
