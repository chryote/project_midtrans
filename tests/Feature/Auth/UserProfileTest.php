<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_access_profile(): void
    {
        $user = User::factory()->create([
            'name' => 'Charlie User',
            'email' => 'charlie@example.com',
        ]);

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson(route('api.v1.auth.me'));

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'id' => $user->id,
                    'name' => 'Charlie User',
                    'email' => 'charlie@example.com',
                ],
            ]);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson(route('api.v1.auth.me'));

        $response->assertStatus(401);
    }
}
