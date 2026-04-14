<?php

namespace Tests\Feature\Models;

use App\Models\Cart;
use App\Models\Product;
use App\Models\User;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

class CartModelTest extends TestCase
{
    use RefreshDatabase;

    private function makeProduct(): Product
    {
        $cat = Category::factory()->create(['parent_id' => 0, 'category_discount' => 0, 'status' => 1]);
        return Product::factory()->create(['category_id' => $cat->id, 'section_id' => 1]);
    }

    /**
     * Test 6.1 — Auth::check() = TRUE: getCartItems theo user_id
     */
    public function test_getCartItems_returns_items_for_logged_in_user(): void
    {
        $user    = User::factory()->create(['status' => 1]);
        $product = $this->makeProduct();

        Cart::factory()->create([
            'user_id'    => $user->id,
            'product_id' => $product->id,
            'session_id' => 'sess-123',
            'size'       => 'M',
            'quantity'   => 2,
        ]);

        $this->actingAs($user);
        $items = Cart::getCartItems();

        $this->assertCount(1, $items);
        $this->assertEquals($product->id, $items[0]['product_id']);
    }

    /**
     * Test 6.2 — Auth::check() = FALSE: getCartItems theo session_id
     */
    public function test_getCartItems_returns_items_for_guest_by_session(): void
    {
        $product   = $this->makeProduct();
        $sessionId = 'guest-session-abc';

        Session::put('session_id', $sessionId);

        Cart::factory()->create([
            'user_id'    => null,
            'session_id' => $sessionId,
            'product_id' => $product->id,
            'size'       => 'L',
            'quantity'   => 1,
        ]);

        // Không actingAs => guest
        $items = Cart::getCartItems();

        $this->assertCount(1, $items);
        $this->assertEquals($product->id, $items[0]['product_id']);
    }

    /**
     * Test 6.3 — Auth = TRUE nhưng giỏ rỗng => trả về []
     */
    public function test_getCartItems_returns_empty_array_when_cart_is_empty(): void
    {
        $user = User::factory()->create(['status' => 1]);
        $this->actingAs($user);

        $items = Cart::getCartItems();

        $this->assertIsArray($items);
        $this->assertEmpty($items);
    }
}
