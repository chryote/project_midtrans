<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_with_valid_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'bob@example.com',
            'password' => Hash::make('mypassword123'),
        ]);

        $payload = [
            'email' => 'bob@example.com',
            'password' => 'mypassword123',
        ];

        $response = $this->postJson(route('api.v1.auth.login'), $payload);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'code',
                'message',
                'data' => [
                    'user' => ['id', 'name', 'email'],
                    'token',
                    'token_type',
                ],
            ])
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'user' => [
                        'email' => 'bob@example.com',
                    ],
                    'token_type' => 'Bearer',
                ],
            ]);
    }

    public function test_user_cannot_login_with_wrong_password(): void
    {
        User::factory()->create([
            'email' => 'bob@example.com',
            'password' => Hash::make('correctPassword'),
        ]);

        $payload = [
            'email' => 'bob@example.com',
            'password' => 'wrongPassword',
        ];

        $response = $this->postJson(route('api.v1.auth.login'), $payload);

        $response->assertStatus(401)
            ->assertJson([
                'status' => 'error',
                'code' => 401,
                'message' => 'Invalid email or password credentials',
            ]);
    }

    public function test_user_cannot_login_with_nonexistent_email(): void
    {
        $payload = [
            'email' => 'nonexistent@example.com',
            'password' => 'anyPassword',
        ];

        $response = $this->postJson(route('api.v1.auth.login'), $payload);

        $response->assertStatus(401)
            ->assertJson([
                'status' => 'error',
                'code' => 401,
            ]);
    }
}
