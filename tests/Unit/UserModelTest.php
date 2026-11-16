<?php

namespace Tests\Unit;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_api_token(): void
    {
        $user = User::factory()->create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'secret1234',
        ]);

        $token = $user->createToken('test_token');

        $this->assertNotEmpty($token->plainTextToken);
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => 'test_token',
        ]);
    }

    public function test_user_password_is_hashed_and_hidden_in_serialization(): void
    {
        $user = User::factory()->create([
            'password' => 'plaintext_secret',
        ]);

        $this->assertNotEquals('plaintext_secret', $user->password);
        $this->assertTrue(password_verify('plaintext_secret', $user->password));

        $array = $user->toArray();
        $this->assertArrayNotHasKey('password', $array);
        $this->assertArrayNotHasKey('remember_token', $array);
    }
}
