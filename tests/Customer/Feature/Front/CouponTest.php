<?php

namespace Tests\Feature\Front;

use App\Models\Cart;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductsAttribute;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

class CouponTest extends TestCase
{
    use RefreshDatabase;

    private array $ajax = ['X-Requested-With' => 'XMLHttpRequest'];

    private function makeUserWithCart(): array
    {
        $user     = User::factory()->create(['status' => 1]);
        $section  = \App\Models\Section::factory()->create();
        $category = Category::factory()->create([
            'parent_id'         => 0,
            'section_id'        => $section->id,
            'category_discount' => 0,
            'status'            => 1,
        ]);
        $product   = Product::factory()->create(['category_id' => $category->id, 'section_id' => $section->id, 'vendor_id' => 0, 'status' => 1]);
        $attribute = ProductsAttribute::factory()->create([
            'product_id' => $product->id,
            'size'       => 'M',
            'price'      => 200,
            'stock'      => 10,
            'status'     => 1,
        ]);
        Cart::factory()->create([
            'user_id'    => $user->id,
            'product_id' => $product->id,
            'size'       => 'M',
            'quantity'   => 1,
            'session_id' => 'test-session',
        ]);

        return [$user, $product, $category, $attribute];
    }

    private function applyCoupon(User $user, string $code): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user)
            ->withHeaders($this->ajax)
            ->post('/apply-coupon', ['code' => $code]);
    }

    // ─── Test 18.1: coupon không tồn tại ─────────────────────────────────────
    public function test_applyCoupon_returns_error_for_invalid_code(): void
    {
        [$user] = $this->makeUserWithCart();

        $response = $this->applyCoupon($user, 'INVALIDCODE');

        $response->assertJson(['status' => false]);
        $this->assertStringContainsString('invalid', strtolower($response->json('message')));
    }

    // ─── Test 18.2: coupon tồn tại nhưng status=0 ────────────────────────────
    public function test_applyCoupon_returns_error_for_inactive_coupon(): void
    {
        [$user, , $category] = $this->makeUserWithCart();
        $coupon = Coupon::factory()->inactive()->create(['categories' => (string)$category->id]);

        $response = $this->applyCoupon($user, $coupon->coupon_code);

        $response->assertJson(['status' => false]);
        $this->assertStringContainsString('inactive', strtolower($response->json('message')));
    }

    // ─── Test 18.3: coupon hết hạn ───────────────────────────────────────────
    public function test_applyCoupon_returns_error_for_expired_coupon(): void
    {
        [$user, , $category] = $this->makeUserWithCart();
        
        // Freeze time at 2026-01-01 to ensure repeatable tests
        $this->travelTo('2026-01-01 10:00:00');
        // Coupon expired yesterday (2025-12-31)
        $coupon = Coupon::factory()->expired()->create(['categories' => (string)$category->id]);
        $this->travelBack();

        $response = $this->applyCoupon($user, $coupon->coupon_code);

        $response->assertJson(['status' => false]);
        $this->assertStringContainsString('expired', strtolower($response->json('message')));
    }

    // ─── Test 18.4: Single Time, user đã dùng ────────────────────────────────
    public function test_applyCoupon_returns_error_for_already_used_single_time_coupon(): void
    {
        [$user, , $category] = $this->makeUserWithCart();
        $coupon = Coupon::factory()->singleTime()->create(['categories' => (string)$category->id]);

        // Tạo 1 order đã dùng coupon này
        Order::factory()->create([
            'user_id'     => $user->id,
            'coupon_code' => $coupon->coupon_code,
        ]);

        $response = $this->applyCoupon($user, $coupon->coupon_code);

        $response->assertJson(['status' => false]);
        $this->assertStringContainsString('availed', strtolower($response->json('message')));
    }

    // ─── Test 18.5: Single Time, user chưa dùng nhưng category không khớp ────
    public function test_applyCoupon_returns_error_when_category_does_not_match(): void
    {
        [$user] = $this->makeUserWithCart();
        // Coupon chỉ áp dụng cho category ID 9999
        $coupon = Coupon::factory()->create([
            'coupon_type' => 'Single Time',
            'categories'  => '9999',
        ]);

        $response = $this->applyCoupon($user, $coupon->coupon_code);

        $response->assertJson(['status' => false]);
        $this->assertStringContainsString('categor', strtolower($response->json('message')));
    }

    // ─── Test 18.6: isset(users)=T && !empty=T, user KHÔNG trong whitelist ───
    public function test_applyCoupon_returns_error_when_user_not_in_whitelist(): void
    {
        [$user, , $category] = $this->makeUserWithCart();
        $otherUser = User::factory()->create(['email' => 'other@example.com', 'status' => 1]);

        $coupon = Coupon::factory()->forUsers($otherUser->email)->create([
            'categories' => (string)$category->id,
        ]);

        $response = $this->applyCoupon($user, $coupon->coupon_code);

        $response->assertJson(['status' => false]);
        $this->assertStringContainsString('not available for you', strtolower($response->json('message')));
    }

    // ─── Test 18.7: isset(users)=T && !empty=F (chuỗi rỗng) → skip whitelist
    public function test_applyCoupon_skips_user_check_when_users_field_is_empty_string(): void
    {
        [$user, , $category] = $this->makeUserWithCart();
        // users = '' => !empty() = false => bỏ qua kiểm tra whitelist
        $coupon = Coupon::factory()->create([
            'categories' => (string)$category->id,
            'users'      => '',
            'vendor_id'  => 0,
        ]);

        $response = $this->applyCoupon($user, $coupon->coupon_code);

        // Không có lỗi user whitelist => coupon áp dụng thành công
        $response->assertJson(['status' => true]);
    }

    // ─── Test 18.8: vendor_id > 0, product không thuộc vendor đó ────────────
    public function test_applyCoupon_returns_error_when_product_not_from_vendor(): void
    {
        [$user, , $category] = $this->makeUserWithCart();
        // vendor_id = 999 nhưng product có vendor_id = 0
        $coupon = Coupon::factory()->forVendor(999)->create([
            'categories' => (string)$category->id,
        ]);

        $response = $this->applyCoupon($user, $coupon->coupon_code);

        $response->assertJson(['status' => false]);
        $this->assertStringContainsString('vendor', strtolower($response->json('message')));
    }

    // ─── Test 18.9: vendor_id = 0 (skip vendor check), amount_type = Fixed ──
    public function test_applyCoupon_applies_fixed_amount_coupon_successfully(): void
    {
        [$user, , $category] = $this->makeUserWithCart();
        $coupon = Coupon::factory()->create([
            'categories'  => (string)$category->id,
            'vendor_id'   => 0,
            'users'       => '',
            'amount_type' => 'Fixed',
            'amount'      => 30,
        ]);

        $response = $this->applyCoupon($user, $coupon->coupon_code);

        $response->assertJson(['status' => true]);
        $this->assertEquals(30, $response->json('couponAmount'));
        // grand_total = 200 - 30 = 170
        $this->assertEquals(170, $response->json('grand_total'));
    }

    // ─── Test 18.10: amount_type = Percentage ────────────────────────────────
    public function test_applyCoupon_applies_percentage_coupon_successfully(): void
    {
        [$user, , $category] = $this->makeUserWithCart();
        $coupon = Coupon::factory()->percentage(10)->create([
            'categories' => (string)$category->id,
            'vendor_id'  => 0,
            'users'      => '',
        ]);

        $response = $this->applyCoupon($user, $coupon->coupon_code);

        $response->assertJson(['status' => true]);
        // total = 200, 10% = 20
        $this->assertEquals(20, $response->json('couponAmount'));
        $this->assertEquals(180, $response->json('grand_total'));
    }
}
