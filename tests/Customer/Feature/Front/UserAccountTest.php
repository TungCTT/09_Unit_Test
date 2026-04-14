<?php

namespace Tests\Feature\Front;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserAccountTest extends TestCase
{
    use RefreshDatabase;

    private array $ajax = ['X-Requested-With' => 'XMLHttpRequest'];

    public function test_user_account_requires_authentication(): void
    {
        $response = $this->get('/user/account');

        $response->assertRedirect('/user/login-register');
    }

    public function test_user_account_get_returns_view_for_authenticated_user(): void
    {
        $user = User::factory()->create(['status' => 1]);

        $response = $this->actingAs($user)->get('/user/account');

        $response->assertStatus(200);
        $response->assertViewIs('front.users.user_account');
    }

    public function test_user_account_ajax_returns_validation_errors_for_invalid_payload(): void
    {
        $user = User::factory()->create(['status' => 1]);

        $response = $this->actingAs($user)
            ->withHeaders($this->ajax)
            ->post('/user/account', [
                'name' => '',
                'mobile' => 'abc',
            ]);

        $response->assertJson(['type' => 'error']);
        $this->assertArrayHasKey('name', $response->json('errors'));
        $this->assertArrayHasKey('mobile', $response->json('errors'));
    }

    public function test_user_account_ajax_updates_profile_for_authenticated_user(): void
    {
        $user = User::factory()->create([
            'status' => 1,
            'name' => 'Old Name',
            'mobile' => '0900000000',
            'city' => 'Old City',
            'state' => 'Old State',
            'country' => 'Old Country',
            'pincode' => '111111',
            'address' => 'Old Address',
        ]);

        $response = $this->actingAs($user)
            ->withHeaders($this->ajax)
            ->post('/user/account', [
                'name' => 'New Name',
                'mobile' => '0912345678',
                'city' => 'Ha Noi',
                'state' => 'HN',
                'country' => 'Vietnam',
                'pincode' => '700000',
                'address' => '1 Nguyen Hue',
            ]);

        $response->assertJson(['type' => 'success']);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'New Name',
            'mobile' => '0912345678',
            'city' => 'Ha Noi',
            'state' => 'HN',
            'country' => 'Vietnam',
            'pincode' => '700000',
            'address' => '1 Nguyen Hue',
        ]);
    }
}
