<?php

namespace Tests\Feature\Front;

use App\Models\DeliveryAddress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliveryAddressTest extends TestCase
{
    use RefreshDatabase;

    private array $ajax = ['X-Requested-With' => 'XMLHttpRequest'];

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'delivery_name'    => 'Nguyen Van A',
            'delivery_address' => '123 Nguyen Trai',
            'delivery_city'    => 'Hanoi',
            'delivery_state'   => 'HN',
            'delivery_country' => 'India',
            'delivery_pincode' => '110001',
            'delivery_mobile'  => '0987654321',
        ], $overrides);
    }

    /**
     * Test 22.1 — D1=[F]: pincode không phải 6 chữ số => error
     */
    public function test_saveDeliveryAddress_fails_when_pincode_not_6_digits(): void
    {
        $user = User::factory()->create(['status' => 1]);
        $this->actingAs($user);

        $response = $this->withHeaders($this->ajax)
            ->post('/save-delivery-address', $this->validPayload(['delivery_pincode' => '123']));

        $response->assertJson(['type' => 'error']);
        $this->assertArrayHasKey('delivery_pincode', $response->json('errors'));
    }

    /**
     * Test 22.2 — D1=[F]: mobile không phải 10 chữ số => error
     */
    public function test_saveDeliveryAddress_fails_when_mobile_not_10_digits(): void
    {
        $user = User::factory()->create(['status' => 1]);
        $this->actingAs($user);

        $response = $this->withHeaders($this->ajax)
            ->post('/save-delivery-address', $this->validPayload(['delivery_mobile' => '123']));

        $response->assertJson(['type' => 'error']);
        $this->assertArrayHasKey('delivery_mobile', $response->json('errors'));
    }

    /**
     * Test 22.3 — D1=[T], D2=[F]: không có delivery_id => tạo mới
     */
    public function test_saveDeliveryAddress_creates_new_address_when_no_delivery_id(): void
    {
        $user = User::factory()->create(['status' => 1]);
        $this->actingAs($user);

        $this->withHeaders($this->ajax)
            ->post('/save-delivery-address', $this->validPayload());

        $this->assertDatabaseHas('delivery_addresses', [
            'user_id' => $user->id,
            'name'    => 'Nguyen Van A',
            'city'    => 'Hanoi',
        ]);
    }

    /**
     * Test 22.4 — D1=[T], D2=[T]: có delivery_id => cập nhật địa chỉ
     */
    public function test_saveDeliveryAddress_updates_existing_address_when_delivery_id_provided(): void
    {
        $user    = User::factory()->create(['status' => 1]);
        $address = DeliveryAddress::factory()->forUser($user->id)->create(['city' => 'OldCity']);

        $this->actingAs($user);

        $this->withHeaders($this->ajax)
            ->post('/save-delivery-address', $this->validPayload([
                'delivery_id'   => $address->id,
                'delivery_city' => 'NewCity',
            ]));

        $this->assertDatabaseHas('delivery_addresses', [
            'id'   => $address->id,
            'city' => 'NewCity',
        ]);
    }

    /**
     * Test 22.5 — removeDeliveryAddress: xóa địa chỉ
     */
    public function test_removeDeliveryAddress_deletes_the_address(): void
    {
        $user    = User::factory()->create(['status' => 1]);
        $address = DeliveryAddress::factory()->forUser($user->id)->create();

        $this->actingAs($user);

        $this->withHeaders($this->ajax)
            ->post('/remove-delivery-address', ['addressid' => $address->id]);

        $this->assertDatabaseMissing('delivery_addresses', ['id' => $address->id]);
    }
}
