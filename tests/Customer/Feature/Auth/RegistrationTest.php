<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered()
    {
        $response = $this->get('/user/login-register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register()
    {
        $response = $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->post('/user/register', [
            'name' => 'Test User',
            'mobile' => '0987654321',
            'email' => 'test@example.com',
            'password' => 'Password123!',
            'accept' => 'on',
        ]);

        $response->assertOk();
        $response->assertJson([
            'type' => 'success',
        ]);

        $this->assertDatabaseHas('users', [
            'email' => 'test@example.com',
            'status' => 0,
        ]);

        $this->assertGuest();
    }

    public function test_registration_fails_when_email_already_exists()
    {
        \App\Models\User::factory()->create([
            'email' => 'test@example.com',
            'status' => 1,
        ]);

        $response = $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->post('/user/register', [
                'name' => 'Another User',
                'mobile' => '0987654321',
                'email' => 'test@example.com',
                'password' => 'Password123!',
                'accept' => 'on',
            ]);

        $response->assertOk();
        $response->assertJson(['type' => 'error']);
        $this->assertArrayHasKey('email', $response->json('errors'));

        $this->assertEquals(1, \App\Models\User::where('email', 'test@example.com')->count());
    }
}
