<?php

namespace Tests\Feature\Front;

use App\Models\Category;
use App\Models\Product;
use App\Models\Rating;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RatingTest extends TestCase
{
    use RefreshDatabase;

    private function makeProduct(): Product
    {
        $section  = \App\Models\Section::factory()->create();
        $category = Category::factory()->create(['parent_id' => 0, 'section_id' => $section->id, 'status' => 1]);
        return Product::factory()->create(['category_id' => $category->id, 'section_id' => $section->id, 'status' => 1]);
    }

    /**
     * Test 23.1 — D1=[T]: guest cố rate => redirect với "Log in"
     */
    public function test_addRating_redirects_guest_with_login_message(): void
    {
        $product  = $this->makeProduct();
        $response = $this->post('/add-rating', [
            'product_id' => $product->id,
            'rating'     => 4,
            'review'     => 'Great product',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error_message', fn ($v) => str_contains(strtolower($v), 'log in'));
    }

    /**
     * Test 23.2 — D1=[F], D2=[T]: user đã rate sản phẩm rồi => error
     */
    public function test_addRating_prevents_duplicate_rating(): void
    {
        $user    = User::factory()->create(['status' => 1]);
        $product = $this->makeProduct();

        // Đã có rating
        Rating::factory()->create([
            'user_id'    => $user->id,
            'product_id' => $product->id,
        ]);

        $response = $this->actingAs($user)->post('/add-rating', [
            'product_id' => $product->id,
            'rating'     => 5,
            'review'     => 'Love it',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error_message', fn ($v) => str_contains(strtolower($v), 'already rated'));
    }

    /**
     * Test 23.3 — D1=[F], D2=[F], D3=[T]: không chọn sao => error
     */
    public function test_addRating_fails_when_no_star_selected(): void
    {
        $user    = User::factory()->create(['status' => 1]);
        $product = $this->makeProduct();

        $response = $this->actingAs($user)->post('/add-rating', [
            'product_id' => $product->id,
            'review'     => 'OK',
            // 'rating' bị thiếu
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error_message', fn ($v) => str_contains(strtolower($v), 'star'));
    }

    /**
     * Test 23.4 — D1=[F], D2=[F], D3=[F]: tất cả hợp lệ => rating saved với status=0
     */
    public function test_addRating_saves_rating_with_pending_status(): void
    {
        $user    = User::factory()->create(['status' => 1]);
        $product = $this->makeProduct();

        $response = $this->actingAs($user)->post('/add-rating', [
            'product_id' => $product->id,
            'rating'     => 4,
            'review'     => 'Very good product!',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success_message');

        $this->assertDatabaseHas('ratings', [
            'user_id'    => $user->id,
            'product_id' => $product->id,
            'rating'     => 4,
            'status'     => 0,
        ]);
    }
}
