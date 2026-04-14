<?php

namespace Tests\Feature\Front;

use App\Models\Cart;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductsAttribute;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

class CartManagementTest extends TestCase
{
    use RefreshDatabase;

    private array $ajax = ['X-Requested-With' => 'XMLHttpRequest'];

    private function setupProduct(int $stock = 10, int $status = 1): array
    {
        $section   = \App\Models\Section::factory()->create();
        $category  = Category::factory()->create(['parent_id' => 0, 'section_id' => $section->id, 'category_discount' => 0, 'status' => 1]);
        $product   = Product::factory()->create(['category_id' => $category->id, 'section_id' => $section->id, 'status' => $status]);
        $attribute = ProductsAttribute::factory()->create([
            'product_id' => $product->id,
            'size'       => 'M',
            'price'      => 100,
            'stock'      => $stock,
            'status'     => 1,
        ]);

        return [$product, $attribute, $category];
    }

    // =========================================================================
    // cartAdd — Tests 15.x
    // =========================================================================

    /**
     * Test 15.1 — D1=[T]: qty=0 => auto-set=1, tạo cart mới cho user
     */
    public function test_cartAdd_sets_quantity_to_1_when_zero_and_creates_new_cart(): void
    {
        $user = User::factory()->create(['status' => 1]);
        $this->actingAs($user);
        [$product] = $this->setupProduct(stock: 5);

        $this->post('/cart/add', [
            'product_id' => $product->id,
            'size'       => 'M',
            'quantity'   => 0,
        ]);

        $this->assertDatabaseHas('carts', [
            'user_id'    => $user->id,
            'product_id' => $product->id,
            'quantity'   => 1,
        ]);
    }

    /**
     * Test 15.2 — D2=[T]: qty > stock => redirect với error
     */
    public function test_cartAdd_redirects_with_error_when_quantity_exceeds_stock(): void
    {
        $user = User::factory()->create(['status' => 1]);
        $this->actingAs($user);
        [$product] = $this->setupProduct(stock: 2);

        $response = $this->post('/cart/add', [
            'product_id' => $product->id,
            'size'       => 'M',
            'quantity'   => 5,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error_message');
        $this->assertDatabaseMissing('carts', ['product_id' => $product->id]);
    }

    /**
     * Test 15.3 — D3=[T] no session, D4=[F] guest, D5=[F] new: session tạo mới, cart record mới
     */
    public function test_cartAdd_creates_new_session_and_cart_for_fresh_guest(): void
    {
        [$product] = $this->setupProduct(stock: 10);

        // Không actingAs, không set session_id trước
        $this->post('/cart/add', [
            'product_id' => $product->id,
            'size'       => 'M',
            'quantity'   => 1,
        ]);

        $this->assertDatabaseHas('carts', [
            'user_id'    => 0,
            'product_id' => $product->id,
            'quantity'   => 1,
        ]);
    }

    /**
     * Test 15.4 — D4=[F] guest, D5=[T]: sản phẩm đã có trong giỏ => increment
     */
    public function test_cartAdd_increments_quantity_for_existing_guest_cart_item(): void
    {
        [$product] = $this->setupProduct(stock: 20);
        $sessionId = 'guest-increment-sess';

        Session::put('session_id', $sessionId);

        // Đã có sẵn trong giỏ
        $cart = Cart::factory()->create([
            'session_id' => $sessionId,
            'user_id'    => 0,
            'product_id' => $product->id,
            'size'       => 'M',
            'quantity'   => 2,
        ]);

        $this->withSession(['session_id' => $sessionId])
            ->post('/cart/add', [
                'product_id' => $product->id,
                'size'       => 'M',
                'quantity'   => 3,
            ]);

        $this->assertDatabaseHas('carts', [
            'id'       => $cart->id,
            'quantity' => 5, // 2 + 3
        ]);
    }

    /**
     * Test 15.5 — D4=[T] auth, D5=[F] new: cart record mới với user_id
     */
    public function test_cartAdd_creates_new_cart_item_for_logged_in_user(): void
    {
        $user = User::factory()->create(['status' => 1]);
        $this->actingAs($user);
        [$product] = $this->setupProduct(stock: 10);

        $this->post('/cart/add', [
            'product_id' => $product->id,
            'size'       => 'M',
            'quantity'   => 2,
        ]);

        $this->assertDatabaseHas('carts', [
            'user_id'    => $user->id,
            'product_id' => $product->id,
            'quantity'   => 2,
        ]);
    }

    /**
     * Test 15.6 — D4=[T] auth, D5=[T] exists: quantity được increment
     */
    public function test_cartAdd_increments_existing_cart_item_for_logged_in_user(): void
    {
        $user = User::factory()->create(['status' => 1]);
        $this->actingAs($user);
        [$product] = $this->setupProduct(stock: 20);

        $sessionId = 'auth-increment-sess';
        Session::put('session_id', $sessionId);

        // Đã có sẵn trong giỏ
        $cart = Cart::factory()->create([
            'session_id' => $sessionId,
            'user_id'    => $user->id,
            'product_id' => $product->id,
            'size'       => 'M',
            'quantity'   => 1,
        ]);

        $this->withSession(['session_id' => $sessionId])
            ->post('/cart/add', [
                'product_id' => $product->id,
                'size'       => 'M',
                'quantity'   => 4,
            ]);

        $this->assertDatabaseHas('carts', [
            'id'       => $cart->id,
            'quantity' => 5, // 1 + 4
        ]);
    }

    // =========================================================================
    // cartUpdate — Tests 16.x (AJAX)
    // =========================================================================

    /**
     * Test 16.1 — D1=[T]: qty > stock => JSON status=false
     */
    public function test_cartUpdate_returns_error_when_qty_exceeds_stock(): void
    {
        $user = User::factory()->create(['status' => 1]);
        $this->actingAs($user);
        [$product] = $this->setupProduct(stock: 3);

        $cart = Cart::factory()->create([
            'user_id'    => $user->id,
            'product_id' => $product->id,
            'size'       => 'M',
            'quantity'   => 1,
            'session_id' => 'ss',
        ]);

        $response = $this->withHeaders($this->ajax)
            ->post('/cart/update', ['cartid' => $cart->id, 'qty' => 10]);

        $response->assertJson(['status' => false]);
        $this->assertStringContainsString('Stock', $response->json('message'));
    }

    /**
     * Test 16.2 — D1=[F], D2=[T]: size inactive => JSON status=false
     */
    public function test_cartUpdate_returns_error_when_size_is_inactive(): void
    {
        $user = User::factory()->create(['status' => 1]);
        $this->actingAs($user);
        [$product, $attribute] = $this->setupProduct(stock: 10);

        // Đặt attribute thành inactive
        $attribute->update(['status' => 0]);

        $cart = Cart::factory()->create([
            'user_id'    => $user->id,
            'product_id' => $product->id,
            'size'       => 'M',
            'quantity'   => 1,
            'session_id' => 'ss',
        ]);

        $response = $this->withHeaders($this->ajax)
            ->post('/cart/update', ['cartid' => $cart->id, 'qty' => 2]);

        $response->assertJson(['status' => false]);
    }

    /**
     * Test 16.3 — D1=[F], D2=[F]: update thành công
     */
    public function test_cartUpdate_succeeds_when_stock_and_size_available(): void
    {
        $user = User::factory()->create(['status' => 1]);
        $this->actingAs($user);
        [$product] = $this->setupProduct(stock: 10);

        $cart = Cart::factory()->create([
            'user_id'    => $user->id,
            'product_id' => $product->id,
            'size'       => 'M',
            'quantity'   => 1,
            'session_id' => 'ss',
        ]);

        $response = $this->withHeaders($this->ajax)
            ->post('/cart/update', ['cartid' => $cart->id, 'qty' => 3]);

        $response->assertJson(['status' => true]);
        $this->assertDatabaseHas('carts', ['id' => $cart->id, 'quantity' => 3]);
    }

    // =========================================================================
    // cartDelete — Test 17.x (AJAX)
    // =========================================================================

    /**
     * Test 17.1 — Xóa cart item thành công
     */
    public function test_cartDelete_removes_item_and_returns_updated_totals(): void
    {
        $user = User::factory()->create(['status' => 1]);
        $this->actingAs($user);
        [$product] = $this->setupProduct(stock: 10);

        $cart = Cart::factory()->create([
            'user_id'    => $user->id,
            'product_id' => $product->id,
            'size'       => 'M',
            'quantity'   => 2,
            'session_id' => 'ss',
        ]);

        $response = $this->withHeaders($this->ajax)
            ->post('/cart/delete', ['cartid' => $cart->id]);

        $response->assertJsonStructure(['totalCartItems', 'view', 'headerview']);
        $this->assertDatabaseMissing('carts', ['id' => $cart->id]);
    }
}
