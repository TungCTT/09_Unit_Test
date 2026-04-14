<?php

namespace Tests\Feature\Front;

use App\Models\Cart;
use App\Models\Category;
use App\Models\DeliveryAddress;
use App\Models\Product;
use App\Models\ProductsAttribute;
use App\Models\ShippingCharge;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (!Schema::hasTable('countries')) {
            Schema::create('countries', function ($table) {
                $table->id();
                $table->string('country_name')->nullable();
                $table->tinyInteger('status')->default(1);
                $table->timestamps();
            });
        }

        \DB::table('countries')->insert([
            'country_name' => 'India',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Setup environment chung: user, section, category, product, attribute, cart, address, shipping
     */
    private function setupCheckoutEnv(array $overrides = []): array
    {
        $section   = \App\Models\Section::factory()->create();
        $category  = Category::factory()->create([
            'parent_id'         => 0,
            'section_id'        => $section->id,
            'category_discount' => 0,
            'status'            => $overrides['cat_status'] ?? 1,
        ]);
        $product = Product::factory()->create([
            'category_id' => $category->id,
            'section_id'  => $section->id,
            'vendor_id'   => $overrides['vendor_id'] ?? 0,
            'admin_id'    => 1,
            'admin_type'  => 'admin',
            'status'      => $overrides['product_status'] ?? 1,
            'product_weight' => 300,
        ]);
        $attribute = ProductsAttribute::factory()->create([
            'product_id' => $product->id,
            'size'       => 'M',
            'price'      => 200,
            'stock'      => $overrides['stock'] ?? 10,
            'status'     => $overrides['attr_status'] ?? 1,
        ]);

        $user = User::factory()->create([
            'status'  => 1,
            'address' => '123 Main St',
            'city'    => 'Hanoi',
            'state'   => 'HN',
            'country' => 'India',
            'pincode' => '100000',
            'mobile'  => '0987654321',
        ]);

        $cart = Cart::factory()->create([
            'user_id'    => $user->id,
            'product_id' => $product->id,
            'size'       => 'M',
            'quantity'   => $overrides['cart_qty'] ?? 2,
            'session_id' => 'test-sess',
        ]);

        $address = DeliveryAddress::factory()->create([
            'user_id' => $user->id,
            'country' => 'India',
            'pincode' => '110001',
            'mobile'  => '0987654321',
        ]);

        ShippingCharge::factory()->create([
            'country'     => 'India',
            '0_500g'      => 50,
            '501g_1000g'  => 100,
            '1001_2000g'  => 150,
            '2001g_5000g' => 200,
            'above_5000g' => 300,
        ]);

        return [$user, $product, $attribute, $category, $cart, $address];
    }

    private function postCheckout(User $user, int $addressId, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user)
            ->withSession(['couponAmount' => 0, 'couponCode' => null])
            ->post('/checkout', array_merge([
                'address_id'      => $addressId,
                'payment_gateway' => 'COD',
                'accept'          => 'on',
            ], $extra));
    }

    // ─── Test 19.1 ───────────────────────────────────────────────────────────
    public function test_checkout_redirects_when_cart_is_empty(): void
    {
        $user = User::factory()->create(['status' => 1]);
        $this->actingAs($user);

        $response = $this->get('/checkout');

        $response->assertRedirect('/cart');
        $response->assertSessionHas('error_message');
    }

    // ─── Test 19.2 ───────────────────────────────────────────────────────────
    public function test_checkout_post_redirects_to_cart_when_product_is_inactive(): void
    {
        [$user, , , , , $address] = $this->setupCheckoutEnv(['product_status' => 0]);

        $response = $this->postCheckout($user, $address->id);

        $response->assertRedirect('/cart');
        $response->assertSessionHas('error_message');
    }

    // ─── Test 19.3 ───────────────────────────────────────────────────────────
    public function test_checkout_post_redirects_to_cart_when_stock_is_zero(): void
    {
        [$user, , , , , $address] = $this->setupCheckoutEnv(['stock' => 0]);

        $response = $this->postCheckout($user, $address->id);

        $response->assertRedirect('/cart');
        $response->assertSessionHas('error_message');
    }

    // ─── Test 19.4 ───────────────────────────────────────────────────────────
    public function test_checkout_post_redirects_when_attribute_is_inactive(): void
    {
        [$user, , , , , $address] = $this->setupCheckoutEnv(['attr_status' => 0]);

        $response = $this->postCheckout($user, $address->id);

        $response->assertRedirect('/cart');
        $response->assertSessionHas('error_message');
    }

    // ─── Test 19.5 ───────────────────────────────────────────────────────────
    public function test_checkout_post_redirects_when_category_is_inactive(): void
    {
        [$user, , , , , $address] = $this->setupCheckoutEnv(['cat_status' => 0]);

        $response = $this->postCheckout($user, $address->id);

        $response->assertRedirect('/cart');
        $response->assertSessionHas('error_message');
    }

    // ─── Test 19.6 ───────────────────────────────────────────────────────────
    public function test_checkout_post_redirects_when_no_address_selected(): void
    {
        [$user] = $this->setupCheckoutEnv();

        $response = $this->actingAs($user)
            ->withSession(['couponAmount' => 0])
            ->post('/checkout', [
                'payment_gateway' => 'COD',
                'accept'          => 'on',
                // address_id bị thiếu
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('error_message', fn ($v) => str_contains($v, 'Delivery Address'));
    }

    // ─── Test 19.7 ───────────────────────────────────────────────────────────
    public function test_checkout_post_redirects_when_no_payment_gateway_selected(): void
    {
        [$user, , , , , $address] = $this->setupCheckoutEnv();

        $response = $this->actingAs($user)
            ->withSession(['couponAmount' => 0])
            ->post('/checkout', [
                'address_id' => $address->id,
                'accept'     => 'on',
                // payment_gateway bị thiếu
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('error_message', fn ($v) => str_contains($v, 'Payment Method'));
    }

    // ─── Test 19.8 ───────────────────────────────────────────────────────────
    public function test_checkout_post_redirects_when_terms_not_accepted(): void
    {
        [$user, , , , , $address] = $this->setupCheckoutEnv();

        $response = $this->actingAs($user)
            ->withSession(['couponAmount' => 0])
            ->post('/checkout', [
                'address_id'      => $address->id,
                'payment_gateway' => 'COD',
                // accept bị thiếu
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('error_message', fn ($v) => str_contains($v, 'T&C'));
    }

    // ─── Test 19.9 ───────────────────────────────────────────────────────────
    public function test_checkout_post_redirects_when_cart_qty_exceeds_stock_at_save(): void
    {
        // stock=5, cart qty=10 => D9 triggers
        [$user, , , , , $address] = $this->setupCheckoutEnv(['stock' => 5, 'cart_qty' => 10]);

        $response = $this->postCheckout($user, $address->id);

        $response->assertRedirect('/cart');
        $response->assertSessionHas('error_message');
    }

    // ─── Test 19.10 — vendor_id = 0: không set commission ────────────────────
    public function test_checkout_does_not_set_commission_for_admin_product(): void
    {
        Mail::fake();

        [$user, $product, , , , $address] = $this->setupCheckoutEnv(['vendor_id' => 0]);

        $this->postCheckout($user, $address->id);

        // orders_products saved, commission should be null (no vendor)
        $this->assertDatabaseHas('orders_products', [
            'product_id' => $product->id,
            'commission' => null,
        ]);
    }

    // ─── Test 19.11 — vendor_id > 0: commission được set ─────────────────────
    public function test_checkout_sets_commission_for_vendor_product(): void
    {
        Mail::fake();

        // Tạo vendor
        $vendorRow = \DB::table('vendors')->insertGetId([
            'name'       => 'Test Vendor',
            'email'      => 'vendor@example.com',
            'mobile'     => '0900000000',
            'status'     => 1,
            'commission' => 15,
            'confirm'    => 'Yes',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        [$user, $product, , , , $address] = $this->setupCheckoutEnv(['vendor_id' => $vendorRow]);

        $this->postCheckout($user, $address->id);

        $orderProduct = \DB::table('orders_products')->where('product_id', $product->id)->first();
        $this->assertNotNull($orderProduct->commission);
    }

    // ─── Test 19.12 — COD: order tạo, stock giảm, redirect /thanks ──────────
    public function test_checkout_cod_creates_order_reduces_stock_and_redirects(): void
    {
        Mail::fake();

        [$user, $product, $attribute, , , $address] = $this->setupCheckoutEnv([
            'stock'    => 10,
            'cart_qty' => 2,
        ]);

        $response = $this->postCheckout($user, $address->id);

        $response->assertRedirect('thanks');

        $this->assertDatabaseHas('orders', [
            'user_id'        => $user->id,
            'payment_method' => 'COD',
        ]);

        // Stock phải giảm 2
        $this->assertDatabaseHas('products_attributes', [
            'id'    => $attribute->id,
            'stock' => 8,
        ]);

        // Cart phải được xóa sau /thanks
        $this->get('/thanks');
        $this->assertDatabaseMissing('carts', ['user_id' => $user->id]);
    }

    // ─── Test 20.1 — /thanks với order_id trong session ─────────────────────
    public function test_thanks_clears_cart_and_shows_thanks_view_when_order_id_exists(): void
    {
        $user = User::factory()->create(['status' => 1]);
        $section = \App\Models\Section::factory()->create();
        $category = Category::factory()->create(['parent_id' => 0, 'section_id' => $section->id, 'status' => 1]);
        $product = Product::factory()->create(['category_id' => $category->id, 'section_id' => $section->id, 'status' => 1]);

        Cart::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'size' => 'M',
            'quantity' => 1,
            'session_id' => 'thanks-session',
        ]);

        $response = $this->actingAs($user)
            ->withSession(['order_id' => 999])
            ->get('/thanks');

        $response->assertStatus(200);
        $this->assertDatabaseMissing('carts', ['user_id' => $user->id]);
    }

    // ─── Test 20.2 — /thanks không có order_id trong session ─────────────────
    public function test_thanks_redirects_to_cart_when_order_id_missing(): void
    {
        $user = User::factory()->create(['status' => 1]);

        $response = $this->actingAs($user)->get('/thanks');

        $response->assertRedirect('cart');
    }
}
