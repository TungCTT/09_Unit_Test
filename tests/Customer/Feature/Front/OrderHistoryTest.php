<?php

namespace Tests\Feature\Front;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_orders_requires_authentication(): void
    {
        $response = $this->get('/user/orders');

        $response->assertRedirect('/user/login-register');
    }

    public function test_user_orders_list_includes_only_current_user_orders(): void
    {
        $user = User::factory()->create(['status' => 1]);
        $otherUser = User::factory()->create(['status' => 1]);

        $myOrder = Order::factory()->create(['user_id' => $user->id]);
        Order::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->actingAs($user)->get('/user/orders');

        $response->assertStatus(200);
        $response->assertViewIs('front.orders.orders');
        $response->assertViewHas('orders', function (array $orders) use ($myOrder) {
            return count($orders) === 1
                && (int) $orders[0]['id'] === (int) $myOrder->id;
        });
    }

    public function test_user_order_detail_returns_expected_order_for_owner(): void
    {
        $user = User::factory()->create(['status' => 1]);
        $order = Order::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->get('/user/orders/' . $order->id);

        $response->assertStatus(200);
        $response->assertViewIs('front.orders.order_details');
        $response->assertViewHas('orderDetails', function (array $orderDetails) use ($order) {
            return (int) $orderDetails['id'] === (int) $order->id;
        });
    }

    public function test_user_order_detail_of_other_user_should_not_be_accessible(): void
    {
        $user = User::factory()->create(['status' => 1]);
        $otherUser = User::factory()->create(['status' => 1]);
        $otherOrder = Order::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->actingAs($user)->get('/user/orders/' . $otherOrder->id);

        $response->assertStatus(403);
    }
}
