<?php

namespace Tests\Feature\Helpers;

use App\Models\Cart;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

class CartHelperTest extends TestCase
{
    use RefreshDatabase;

    private function makeProduct(): Product
    {
        $cat = Category::factory()->create(['parent_id' => 0, 'category_discount' => 0, 'status' => 1]);
        return Product::factory()->create(['category_id' => $cat->id, 'section_id' => 1]);
    }

    /**
     * Test 7.1 — Auth = TRUE, 3 items qty=2 => totalCartItems = 6
     */
    public function test_totalCartItems_returns_sum_for_logged_in_user(): void
    {
        $user = User::factory()->create(['status' => 1]);
        $this->actingAs($user);

        $p1 = $this->makeProduct();
        $p2 = $this->makeProduct();
        $p3 = $this->makeProduct();

        Cart::factory()->create(['user_id' => $user->id, 'product_id' => $p1->id, 'quantity' => 2, 'session_id' => 'x']);
        Cart::factory()->create(['user_id' => $user->id, 'product_id' => $p2->id, 'quantity' => 2, 'session_id' => 'x']);
        Cart::factory()->create(['user_id' => $user->id, 'product_id' => $p3->id, 'quantity' => 2, 'session_id' => 'x']);

        $this->assertEquals(6, totalCartItems());
    }

    /**
     * Test 7.2 — Auth = FALSE (guest), 2 items qty=3 => totalCartItems = 6
     */
    public function test_totalCartItems_returns_sum_for_guest_via_session(): void
    {
        $sessionId = 'guest-helper-sess';
        Session::put('session_id', $sessionId);

        $p1 = $this->makeProduct();
        $p2 = $this->makeProduct();

        Cart::factory()->create(['user_id' => null, 'session_id' => $sessionId, 'product_id' => $p1->id, 'quantity' => 3]);
        Cart::factory()->create(['user_id' => null, 'session_id' => $sessionId, 'product_id' => $p2->id, 'quantity' => 3]);

        $this->assertEquals(6, totalCartItems());
    }

    /**
     * Test 7.3 — Auth = TRUE, giỏ rỗng => 0
     */
    public function test_totalCartItems_returns_zero_for_empty_cart(): void
    {
        $user = User::factory()->create(['status' => 1]);
        $this->actingAs($user);

        $this->assertEquals(0, totalCartItems());
    }
}
