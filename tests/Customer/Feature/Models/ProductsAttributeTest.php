<?php

namespace Tests\Feature\Models;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductsAttribute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductsAttributeTest extends TestCase
{
    use RefreshDatabase;

    private function setup_product_with_attribute(int $stock = 10, int $status = 1): array
    {
        $category  = Category::factory()->create(['parent_id' => 0, 'category_discount' => 0, 'status' => 1]);
        $product   = Product::factory()->create(['category_id' => $category->id, 'section_id' => 1]);
        $attribute = ProductsAttribute::factory()->create([
            'product_id' => $product->id,
            'size'       => 'M',
            'stock'      => $stock,
            'status'     => $status,
        ]);

        return [$product, $attribute];
    }

    /**
     * Test 4.1 — getProductStock trả về đúng số lượng
     */
    public function test_getProductStock_returns_correct_stock(): void
    {
        [$product] = $this->setup_product_with_attribute(stock: 15);

        $this->assertEquals(15, ProductsAttribute::getProductStock($product->id, 'M'));
    }

    /**
     * Test 4.2 — getAttributeStatus = 1 (active)
     */
    public function test_getAttributeStatus_returns_1_for_active(): void
    {
        [$product] = $this->setup_product_with_attribute(status: 1);

        $this->assertEquals(1, ProductsAttribute::getAttributeStatus($product->id, 'M'));
    }

    /**
     * Test 4.3 — getAttributeStatus = 0 (inactive)
     */
    public function test_getAttributeStatus_returns_0_for_inactive(): void
    {
        [$product] = $this->setup_product_with_attribute(status: 0);

        $this->assertEquals(0, ProductsAttribute::getAttributeStatus($product->id, 'M'));
    }
}
