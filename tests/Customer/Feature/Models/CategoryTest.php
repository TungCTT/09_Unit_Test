<?php

namespace Tests\Feature\Models;

use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CategoryTest extends TestCase
{
    use RefreshDatabase;

    private function makeSection(): \App\Models\Section
    {
        return \App\Models\Section::factory()->create(['status' => 1]);
    }

    /**
     * Test 8.1 — getCategoryStatus: status = 1
     */
    public function test_getCategoryStatus_returns_1_for_active(): void
    {
        $cat = Category::factory()->create(['parent_id' => 0, 'status' => 1]);

        $this->assertEquals(1, Category::getCategoryStatus($cat->id));
    }

    /**
     * Test 8.2 — getCategoryStatus: status = 0
     */
    public function test_getCategoryStatus_returns_0_for_inactive(): void
    {
        $cat = Category::factory()->create(['parent_id' => 0, 'status' => 0]);

        $this->assertEquals(0, Category::getCategoryStatus($cat->id));
    }

    /**
     * Test 8.3 — getCategoryName trả về đúng tên
     */
    public function test_getCategoryName_returns_correct_name(): void
    {
        $cat = Category::factory()->create(['parent_id' => 0, 'category_name' => 'Electronics', 'status' => 1]);

        $this->assertEquals('Electronics', Category::getCategoryName($cat->id));
    }

    /**
     * Test 9.1 — categoryDetails: parent_id = 0, breadcrumb 1 cấp, catIds chỉ có 1 ID
     */
    public function test_categoryDetails_top_level_category_has_single_breadcrumb(): void
    {
        $url = 'electronics-' . Str::random(4);
        $cat = Category::factory()->create([
            'parent_id'     => 0,
            'category_name' => 'Electronics',
            'url'           => $url,
            'status'        => 1,
        ]);

        $result = Category::categoryDetails($url);

        $this->assertContains($cat->id, $result['catIds']);
        $this->assertCount(1, $result['catIds']); // chỉ 1 ID = chính nó
        $this->assertStringContainsString('is-marked', $result['breadcrumbs']);
        $this->assertStringNotContainsString('has-separator', $result['breadcrumbs']);
    }

    /**
     * Test 9.2 — categoryDetails: parent_id = 0 + có subcategories => catIds chứa sub IDs
     */
    public function test_categoryDetails_includes_subcategory_ids(): void
    {
        $url    = 'fashion-' . Str::random(4);
        $parent = Category::factory()->create([
            'parent_id'     => 0,
            'category_name' => 'Fashion',
            'url'           => $url,
            'status'        => 1,
        ]);

        $sub1 = Category::factory()->create(['parent_id' => $parent->id, 'status' => 1]);
        $sub2 = Category::factory()->create(['parent_id' => $parent->id, 'status' => 1]);

        $result = Category::categoryDetails($url);

        $this->assertContains($parent->id, $result['catIds']);
        $this->assertContains($sub1->id, $result['catIds']);
        $this->assertContains($sub2->id, $result['catIds']);
        $this->assertCount(3, $result['catIds']);
    }

    /**
     * Test 9.3 — categoryDetails: có parent (parent_id > 0) => breadcrumb 2 cấp
     */
    public function test_categoryDetails_child_category_has_two_level_breadcrumb(): void
    {
        $parentUrl = 'fashion-' . Str::random(4);
        $parent    = Category::factory()->create([
            'parent_id'     => 0,
            'category_name' => 'Fashion',
            'url'           => $parentUrl,
            'status'        => 1,
        ]);

        $childUrl = 'mens-' . Str::random(4);
        Category::factory()->create([
            'parent_id'     => $parent->id,
            'category_name' => "Men's",
            'url'           => $childUrl,
            'status'        => 1,
        ]);

        $result = Category::categoryDetails($childUrl);

        $this->assertStringContainsString('has-separator', $result['breadcrumbs']);
        $this->assertStringContainsString('is-marked', $result['breadcrumbs']);
        $this->assertStringContainsString('Fashion', $result['breadcrumbs']);
    }
}
