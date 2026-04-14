<?php

namespace Tests\Unit\Models;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductDiscountTest extends TestCase
{
    use RefreshDatabase;

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function makeCategory(float $catDiscount = 0): Category
    {
        return Category::factory()->create([
            'parent_id'         => 0,
            'category_discount' => $catDiscount,
            'status'            => 1,
        ]);
    }

    private function makeProduct(Category $category, float $price, float $prodDiscount = 0): Product
    {
        return Product::factory()->create([
            'category_id'      => $category->id,
            'section_id'       => 1,
            'product_price'    => $price,
            'product_discount' => $prodDiscount,
            'status'           => 1,
        ]);
    }

    // ─── getDiscountPrice ─────────────────────────────────────────────────────

    /**
     * Test 1.1 — product_discount > 0 => giảm theo product discount
     */
    public function test_getDiscountPrice_uses_product_discount_when_positive(): void
    {
        $category = $this->makeCategory(catDiscount: 0);
        $product  = $this->makeProduct($category, price: 200, prodDiscount: 10);

        $result = Product::getDiscountPrice($product->id);

        // 200 - (200 * 10 / 100) = 180
        $this->assertEquals(180, $result);
    }

    /**
     * Test 1.2 — product_discount = 0, category_discount > 0 => giảm theo category
     */
    public function test_getDiscountPrice_uses_category_discount_when_product_discount_zero(): void
    {
        $category = $this->makeCategory(catDiscount: 20);
        $product  = $this->makeProduct($category, price: 200, prodDiscount: 0);

        $result = Product::getDiscountPrice($product->id);

        // 200 - (200 * 20 / 100) = 160
        $this->assertEquals(160, $result);
    }

    /**
     * Test 1.3 — cả hai discount = 0 => trả về 0
     */
    public function test_getDiscountPrice_returns_zero_when_no_discount(): void
    {
        $category = $this->makeCategory(catDiscount: 0);
        $product  = $this->makeProduct($category, price: 200, prodDiscount: 0);

        $result = Product::getDiscountPrice($product->id);

        $this->assertEquals(0, $result);
    }

    // ─── getDiscountAttributePrice ───────────────────────────────────────────

    private function makeAttribute(Product $product, float $attrPrice, string $size = 'M'): void
    {
        \App\Models\ProductsAttribute::factory()->create([
            'product_id' => $product->id,
            'size'       => $size,
            'price'      => $attrPrice,
            'stock'      => 10,
            'status'     => 1,
        ]);
    }

    /**
     * Test 2.1 — product_discount > 0 => final_price & discount tính từ product_discount
     */
    public function test_getDiscountAttributePrice_uses_product_discount(): void
    {
        $category = $this->makeCategory(catDiscount: 0);
        $product  = $this->makeProduct($category, price: 300, prodDiscount: 10);
        $this->makeAttribute($product, attrPrice: 200, size: 'M');

        $result = Product::getDiscountAttributePrice($product->id, 'M');

        // final = 200 - (200 * 10 / 100) = 180
        $this->assertEquals(200, $result['product_price']);
        $this->assertEquals(180, $result['final_price']);
        $this->assertEquals(20, $result['discount']);
    }

    /**
     * Test 2.2 — product_discount = 0, category_discount > 0 => giảm theo category
     */
    public function test_getDiscountAttributePrice_uses_category_discount_when_product_zero(): void
    {
        $category = $this->makeCategory(catDiscount: 25);
        $product  = $this->makeProduct($category, price: 400, prodDiscount: 0);
        $this->makeAttribute($product, attrPrice: 200, size: 'L');

        $result = Product::getDiscountAttributePrice($product->id, 'L');

        // final = 200 - (200 * 25 / 100) = 150
        $this->assertEquals(200, $result['product_price']);
        $this->assertEquals(150, $result['final_price']);
        $this->assertEquals(50, $result['discount']);
    }

    /**
     * Test 2.3 — cả hai discount = 0 => final_price = attr price, discount = 0
     */
    public function test_getDiscountAttributePrice_returns_full_price_when_no_discount(): void
    {
        $category = $this->makeCategory(catDiscount: 0);
        $product  = $this->makeProduct($category, price: 100, prodDiscount: 0);
        $this->makeAttribute($product, attrPrice: 200, size: 'S');

        $result = Product::getDiscountAttributePrice($product->id, 'S');

        $this->assertEquals(200, $result['product_price']);
        $this->assertEquals(200, $result['final_price']);
        $this->assertEquals(0,   $result['discount']);
    }

    // ─── isProductNew ─────────────────────────────────────────────────────────

    /**
     * Test 3.1 — product là 1 trong 3 sản phẩm mới nhất => 'Yes'
     */
    public function test_isProductNew_returns_yes_for_recent_product(): void
    {
        $category = $this->makeCategory();

        // Tạo 3 sản phẩm mới nhất
        $newest = collect();
        for ($i = 0; $i < 3; $i++) {
            $newest->push($this->makeProduct($category, price: 100));
        }

        $result = Product::isProductNew($newest->last()->id);

        $this->assertEquals('Yes', $result);
    }

    /**
     * Test 3.2 — product không thuộc top 3 mới nhất => 'No'
     */
    public function test_isProductNew_returns_no_for_old_product(): void
    {
        $category = $this->makeCategory();

        // Tạo sản phẩm cũ
        $old = $this->makeProduct($category, price: 100);

        // Tạo thêm 3 sản phẩm mới hơn để đẩy $old ra khỏi top 3
        for ($i = 0; $i < 3; $i++) {
            $this->makeProduct($category, price: 100);
        }

        $result = Product::isProductNew($old->id);

        $this->assertEquals('No', $result);
    }

    // ─── getProductStatus ─────────────────────────────────────────────────────

    /**
     * Test — status = 1 => trả về 1
     */
    public function test_getProductStatus_returns_1_for_active_product(): void
    {
        $category = $this->makeCategory();
        $product  = $this->makeProduct($category, price: 100);

        $this->assertEquals(1, Product::getProductStatus($product->id));
    }

    /**
     * Test — status = 0 => trả về 0
     */
    public function test_getProductStatus_returns_0_for_inactive_product(): void
    {
        $category = $this->makeCategory();
        $product  = $this->makeProduct($category, price: 100);
        $product->update(['status' => 0]);

        $this->assertEquals(0, Product::getProductStatus($product->id));
    }
}
