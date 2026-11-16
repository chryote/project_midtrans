<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_successfully(): void
    {
        $payload = [
            'name' => 'Alice Developer',
            'email' => 'alice@example.com',
            'password' => 'secretPassword123!',
            'password_confirmation' => 'secretPassword123!',
        ];

        $response = $this->postJson(route('api.v1.auth.register'), $payload);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'status',
                'code',
                'message',
                'data' => [
                    'user' => [
                        'id',
                        'name',
                        'email',
                        'created_at',
                    ],
                    'token',
                    'token_type',
                ],
            ])
            ->assertJson([
                'status' => 'success',
                'code' => 201,
                'data' => [
                    'user' => [
                        'name' => 'Alice Developer',
                        'email' => 'alice@example.com',
                    ],
                    'token_type' => 'Bearer',
                ],
            ]);

        $this->assertDatabaseHas('users', [
            'email' => 'alice@example.com',
        ]);
    }

    public function test_user_cannot_register_with_existing_email(): void
    {
        User::factory()->create([
            'email' => 'duplicate@example.com',
        ]);

        $payload = [
            'name' => 'Duplicate User',
            'email' => 'duplicate@example.com',
            'password' => 'secretPassword123!',
            'password_confirmation' => 'secretPassword123!',
        ];

        $response = $this->postJson(route('api.v1.auth.register'), $payload);

        $response->assertStatus(422)
            ->assertJson([
                'status' => 'error',
                'code' => 422,
            ])
            ->assertJsonValidationErrors(['email']);
    }

    public function test_user_cannot_register_with_mismatched_password_confirmation(): void
    {
        $payload = [
            'name' => 'Alice Developer',
            'email' => 'alice@example.com',
            'password' => 'secretPassword123!',
            'password_confirmation' => 'wrongPassword!',
        ];

        $response = $this->postJson(route('api.v1.auth.register'), $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }
}
