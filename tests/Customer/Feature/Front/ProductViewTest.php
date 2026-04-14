<?php

namespace Tests\Feature\Front;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductsAttribute;
use App\Models\Section;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductViewTest extends TestCase
{
    use RefreshDatabase;

    private function makeProduct(): Product
    {
        $section = Section::factory()->create();
        $category = Category::factory()->create([
            'section_id' => $section->id,
            'status' => 1,
        ]);

        $product = Product::factory()->create([
            'section_id' => $section->id,
            'category_id' => $category->id,
            'status' => 1,
            'product_price' => 120,
        ]);

        ProductsAttribute::factory()->create([
            'product_id' => $product->id,
            'size' => 'M',
            'price' => 120,
            'stock' => 10,
            'status' => 1,
        ]);

        return $product;
    }

    public function test_product_detail_page_renders_for_valid_product(): void
    {
        $product = $this->makeProduct();

        $response = $this->get('/product/' . $product->id);

        $response->assertStatus(200);
        $response->assertViewIs('front.products.detail');
        $response->assertSessionHas('session_id');
    }

    public function test_product_detail_does_not_duplicate_recently_viewed_record_in_same_session(): void
    {
        $product = $this->makeProduct();

        $this->withSession(['session_id' => 'fixed-session-id'])
            ->get('/product/' . $product->id)
            ->assertStatus(200);

        $this->withSession(['session_id' => 'fixed-session-id'])
            ->get('/product/' . $product->id)
            ->assertStatus(200);

        $count = DB::table('recently_viewed_products')
            ->where('session_id', 'fixed-session-id')
            ->where('product_id', $product->id)
            ->count();

        $this->assertSame(1, $count);
    }

    public function test_product_detail_invalid_id_should_return_404(): void
    {
        $response = $this->get('/product/999999');

        $response->assertStatus(404);
    }
}
