<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class LogoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_logout_and_revoke_token(): void
    {
        $user = User::factory()->create([
            'email' => 'logout.user@example.com',
            'password' => bcrypt('password123'),
        ]);

        $token = $user->createToken('test_token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->postJson(route('api.v1.auth.logout'));

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'Successfully logged out and token revoked',
            ]);

        // Token should be deleted from the database
        $this->assertDatabaseCount('personal_access_tokens', 0);

        // Reset the in-memory auth guard cache so the next request tests token resolution
        Auth::forgetGuards();

        // Attempting to use the revoked token must now be unauthorized
        $subsequentResponse = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->getJson(route('api.v1.auth.me'));

        $subsequentResponse->assertStatus(401);
    }
}
