<?php

namespace Tests\Vendor\Feature\Dash_Profile;

use App\Models\Admin;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class VendorDasTest extends TestCase
{
    /**
     * Bootstrap the dashboard test database.
     *
     * Rules applied here:
     * - Use only the dedicated migration file requested by the user for the base schema.
     * - Create the extra tables needed by AdminController@dashboard() directly in the test setup.
     * - Keep the setup deterministic so each test starts from a clean DB state.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Reset to the dedicated lightweight schema used across the vendor test suite.
        $this->artisan('migrate:fresh', [
            '--path' => 'database/migrations/2026_04_14_212542_update_database_schema_v2.php',
            '--force' => true,
        ])->assertExitCode(0);

        $this->createDashboardSupportTables();
        $this->registerSQLiteDateFunctions();
    }

    /**
     * Create the dashboard support tables required by the controller query set.
     *
     * The dashboard reads counts and aggregates from these tables:
     * sections, brands, users, products, orders, orders_products, products_attributes.
     */
    private function createDashboardSupportTables(): void
    {
        if (!Schema::hasTable('sections')) {
            Schema::create('sections', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->tinyInteger('status');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('brands')) {
            Schema::create('brands', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->tinyInteger('status');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('coupons')) {
            Schema::create('coupons', function (Blueprint $table): void {
                $table->id();
                $table->string('coupon_code')->nullable();
                $table->string('coupon_amount')->nullable();
                $table->string('coupon_type')->nullable();
                $table->tinyInteger('status')->default(1);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('address')->nullable();
                $table->string('city')->nullable();
                $table->string('state')->nullable();
                $table->string('country')->nullable();
                $table->string('pincode')->nullable();
                $table->string('mobile')->nullable();
                $table->string('email')->unique();
                $table->timestamp('email_verified_at')->nullable();
                $table->string('password');
                $table->tinyInteger('status')->default(0);
                $table->rememberToken();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('products')) {
            Schema::create('products', function (Blueprint $table): void {
                $table->id();
                $table->integer('section_id');
                $table->integer('category_id');
                $table->integer('brand_id');
                $table->integer('vendor_id');
                $table->integer('admin_id');
                $table->string('admin_type');
                $table->string('product_name');
                $table->string('product_code');
                $table->string('product_color');
                $table->float('product_price');
                $table->float('product_discount');
                $table->integer('product_weight');
                $table->string('product_image')->nullable();
                $table->string('product_video')->nullable();
                $table->string('group_code')->nullable();
                $table->text('description')->nullable();
                $table->string('meta_title')->nullable();
                $table->string('meta_keywords')->nullable();
                $table->string('meta_description')->nullable();
                $table->enum('is_featured', ['No', 'Yes']);
                $table->tinyInteger('status');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('orders')) {
            Schema::create('orders', function (Blueprint $table): void {
                $table->id();
                $table->integer('user_id');
                $table->string('name');
                $table->string('address');
                $table->string('city');
                $table->string('state');
                $table->string('country');
                $table->string('pincode');
                $table->string('mobile');
                $table->string('email');
                $table->float('shipping_charges');
                $table->string('coupon_code')->nullable();
                $table->float('coupon_amount')->nullable();
                $table->string('order_status');
                $table->string('payment_method');
                $table->string('payment_gateway');
                $table->float('grand_total');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('orders_products')) {
            Schema::create('orders_products', function (Blueprint $table): void {
                $table->id();
                $table->integer('order_id');
                $table->integer('user_id');
                $table->integer('vendor_id');
                $table->integer('admin_id');
                $table->integer('product_id');
                $table->string('product_code');
                $table->string('product_name');
                $table->string('product_color');
                $table->string('product_size');
                $table->float('product_price');
                $table->integer('product_qty');
                $table->string('item_status');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('products_attributes')) {
            Schema::create('products_attributes', function (Blueprint $table): void {
                $table->id();
                $table->integer('product_id');
                $table->string('size');
                $table->float('price');
                $table->integer('stock');
                $table->string('sku');
                $table->tinyInteger('status');
                $table->timestamps();
            });
        }
    }

    /**
     * Register MONTH() and YEAR() helpers for SQLite.
     *
     * The dashboard controller uses MySQL-style date functions, so the test
     * environment adds compatible SQLite functions without changing source code.
     */
    private function registerSQLiteDateFunctions(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        $pdo = DB::connection()->getPdo();

        if (method_exists($pdo, 'sqliteCreateFunction')) {
            $pdo->sqliteCreateFunction('MONTH', function ($dateValue) {
                return Carbon::parse($dateValue)->month;
            }, 1);

            $pdo->sqliteCreateFunction('YEAR', function ($dateValue) {
                return Carbon::parse($dateValue)->year;
            }, 1);
        }
    }

    /**
     * Create a lightweight vendor-type admin record for the admin guard.
     *
     * The dashboard route uses auth:admin, so this helper builds a vendor-type
     * account inside the admins table and then authenticates with the admin guard.
     */
    private function createVendorTypeAdminAccount(array $overrides = []): Admin
    {
        $admin = new Admin();
        $admin->name = $overrides['name'] ?? 'Vendor Dashboard User';
        $admin->type = 'vendor';
        $admin->vendor_id = $overrides['vendor_id'] ?? 0;
        $admin->mobile = $overrides['mobile'] ?? '0912345678';
        $admin->email = $overrides['email'] ?? 'vendor.dashboard@example.com';
        $admin->password = Hash::make($overrides['password'] ?? 'secret123');
        $admin->confirm = $overrides['confirm'] ?? 'Yes';
        $admin->status = $overrides['status'] ?? 1;
        $admin->save();

        return $admin;
    }

    /**
     * Clear dashboard business tables so a test can simulate an empty dataset.
     */
    private function clearDashboardBusinessTables(bool $includeCategories = false): void
    {
        foreach (['sections', 'brands', 'coupons', 'users', 'products', 'orders', 'orders_products', 'products_attributes'] as $tableName) {
            DB::table($tableName)->delete();
        }

        if ($includeCategories) {
            DB::table('categories')->delete();
        }
    }

    /**
     * Insert a user row with the minimum fields needed by the dashboard count query.
     */
    private function seedCustomerUsers(int $count): void
    {
        for ($index = 1; $index <= $count; $index++) {
            DB::table('users')->insert([
                'name' => 'Customer ' . $index,
                'email' => 'customer' . $index . '@example.com',
                'password' => Hash::make('secret123'),
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Seed product rows with all fields required by the products table schema.
     */
    private function seedProducts(array $productRows): void
    {
        foreach ($productRows as $productRow) {
            DB::table('products')->insert([
                'section_id' => $productRow['section_id'] ?? 0,
                'category_id' => $productRow['category_id'] ?? 1,
                'brand_id' => $productRow['brand_id'] ?? 0,
                'vendor_id' => $productRow['vendor_id'] ?? 0,
                'admin_id' => $productRow['admin_id'] ?? 1,
                'admin_type' => $productRow['admin_type'] ?? 'vendor',
                'product_name' => $productRow['product_name'],
                'product_code' => $productRow['product_code'],
                'product_color' => $productRow['product_color'] ?? 'Black',
                'product_price' => $productRow['product_price'],
                'product_discount' => $productRow['product_discount'] ?? 0,
                'product_weight' => $productRow['product_weight'] ?? 100,
                'product_image' => null,
                'product_video' => null,
                'group_code' => $productRow['group_code'] ?? null,
                'description' => $productRow['description'] ?? null,
                'meta_title' => $productRow['meta_title'] ?? null,
                'meta_keywords' => $productRow['meta_keywords'] ?? null,
                'meta_description' => $productRow['meta_description'] ?? null,
                'is_featured' => $productRow['is_featured'] ?? 'No',
                'status' => $productRow['status'] ?? 1,
                'created_at' => $productRow['created_at'] ?? now(),
                'updated_at' => $productRow['updated_at'] ?? now(),
            ]);
        }
    }

    /**
     * Seed an order row using the dashboard schema.
     */
    private function seedOrder(array $orderRow): int
    {
        return DB::table('orders')->insertGetId([
            'user_id' => $orderRow['user_id'] ?? 1,
            'name' => $orderRow['name'] ?? 'Customer Name',
            'address' => $orderRow['address'] ?? 'Customer Address',
            'city' => $orderRow['city'] ?? 'Hanoi',
            'state' => $orderRow['state'] ?? 'Ha Noi',
            'country' => $orderRow['country'] ?? 'Vietnam',
            'pincode' => $orderRow['pincode'] ?? '100000',
            'mobile' => $orderRow['mobile'] ?? '0900000000',
            'email' => $orderRow['email'] ?? 'customer@example.com',
            'shipping_charges' => $orderRow['shipping_charges'] ?? 10,
            'coupon_code' => $orderRow['coupon_code'] ?? null,
            'coupon_amount' => $orderRow['coupon_amount'] ?? null,
            'order_status' => $orderRow['order_status'],
            'payment_method' => $orderRow['payment_method'] ?? 'COD',
            'payment_gateway' => $orderRow['payment_gateway'] ?? 'COD',
            'grand_total' => $orderRow['grand_total'],
            'created_at' => $orderRow['created_at'] ?? now(),
            'updated_at' => $orderRow['updated_at'] ?? now(),
        ]);
    }

    /**
     * Seed an order item row for the grouped order-status and revenue queries.
     */
    private function seedOrderItem(array $orderItemRow): void
    {
        DB::table('orders_products')->insert([
            'order_id' => $orderItemRow['order_id'],
            'user_id' => $orderItemRow['user_id'] ?? 1,
            'vendor_id' => $orderItemRow['vendor_id'] ?? 0,
            'admin_id' => $orderItemRow['admin_id'] ?? 1,
            'product_id' => $orderItemRow['product_id'],
            'product_code' => $orderItemRow['product_code'],
            'product_name' => $orderItemRow['product_name'],
            'product_color' => $orderItemRow['product_color'] ?? 'Black',
            'product_size' => $orderItemRow['product_size'] ?? 'M',
            'product_price' => $orderItemRow['product_price'],
            'product_qty' => $orderItemRow['product_qty'],
            'item_status' => $orderItemRow['item_status'] ?? 'New',
            'created_at' => $orderItemRow['created_at'] ?? now(),
            'updated_at' => $orderItemRow['updated_at'] ?? now(),
        ]);
    }

    /**
     * Seed a product attribute row used by the top-stock dashboard query.
     */
    private function seedProductAttribute(array $attributeRow): void
    {
        DB::table('products_attributes')->insert([
            'product_id' => $attributeRow['product_id'],
            'size' => $attributeRow['size'] ?? 'M',
            'price' => $attributeRow['price'],
            'stock' => $attributeRow['stock'],
            'sku' => $attributeRow['sku'],
            'status' => $attributeRow['status'] ?? 1,
            'created_at' => $attributeRow['created_at'] ?? now(),
            'updated_at' => $attributeRow['updated_at'] ?? now(),
        ]);
    }

    // ---------------------------------------------------------------------
    // AdminController@dashboard() test cases
    // ---------------------------------------------------------------------

    /**
     * TC-VDAS-01
     * Block access for unauthenticated users.
     */
    public function test_dashboard_blocks_guest(): void
    {
        $response = $this->get('/admin/dashboard');

        $response->assertRedirect('/admin/login');
        $response->assertStatus(302);

        // CheckDB: guest access must not modify any dashboard tables.
        $this->assertDatabaseCount('sections', 0);
        $this->assertDatabaseCount('brands', 0);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('orders_products', 0);
        $this->assertDatabaseCount('products_attributes', 0);
    }

    /**
     * TC-VDAS-02
     * Render dashboard and set the session page for a vendor-type account
     * authenticated through the admin guard.
     */
    public function test_dashboard_auth_and_session(): void
    {
        $this->actingAs($this->createVendorTypeAdminAccount(), 'admin');

        $response = $this->get('/admin/dashboard');

        $response->assertStatus(200);
        $response->assertViewIs('admin.dashboard');
        $response->assertSessionHas('page', 'dashboard');
    }

    /**
     * TC-VDAS-03
     * Verify the dashboard does not crash when every business table is empty.
     */
    public function test_dashboard_handles_empty_dataset(): void
    {
        $this->clearDashboardBusinessTables(true);
        $this->actingAs($this->createVendorTypeAdminAccount(), 'admin');

        $response = $this->get('/admin/dashboard');

        $response->assertStatus(200);
        $response->assertViewIs('admin.dashboard');
        $response->assertViewHas('sectionsCount', 0);
        $response->assertViewHas('categoriesCount', 0);
        $response->assertViewHas('productsCount', 0);
        $response->assertViewHas('ordersCount', 0);
        $response->assertViewHas('couponsCount', 0);
        $response->assertViewHas('brandsCount', 0);
        $response->assertViewHas('usersCount', 0);
    }

    /**
     * TC-VDAS-04
     * Verify the dashboard count variables match the database exactly.
     */
    public function test_dashboard_basic_counts_accurate(): void
    {
        $this->seedCustomerUsers(2);
        $this->seedProducts([
            ['product_name' => 'Product 1', 'product_code' => 'P001', 'product_price' => 100],
            ['product_name' => 'Product 2', 'product_code' => 'P002', 'product_price' => 120],
            ['product_name' => 'Product 3', 'product_code' => 'P003', 'product_price' => 140],
        ]);

        $this->seedOrder([
            'order_status' => 'Shipped',
            'grand_total' => 100,
            'shipping_charges' => 10,
            'created_at' => Carbon::today(),
        ]);

        $this->actingAs($this->createVendorTypeAdminAccount(), 'admin');

        $response = $this->get('/admin/dashboard');

        $response->assertStatus(200);
        $response->assertViewHas('sectionsCount', 0);
        $response->assertViewHas('categoriesCount', 3);
        $response->assertViewHas('productsCount', 3);
        $response->assertViewHas('ordersCount', 1);
        $response->assertViewHas('couponsCount', 0);
        $response->assertViewHas('brandsCount', 0);
        $response->assertViewHas('usersCount', 2);
    }

    /**
     * TC-VDAS-05
     * Verify shipped, canceled, and processing buckets are grouped correctly.
     */
    public function test_dashboard_order_status_grouping(): void
    {
        $this->seedOrder(['order_status' => 'Shipped', 'grand_total' => 100, 'shipping_charges' => 10]);
        $this->seedOrder(['order_status' => 'Canceled', 'grand_total' => 200, 'shipping_charges' => 10]);
        $this->seedOrder(['order_status' => 'New', 'grand_total' => 300, 'shipping_charges' => 10]);

        $this->actingAs($this->createVendorTypeAdminAccount(), 'admin');

        $response = $this->get('/admin/dashboard');

        $response->assertStatus(200);
        $response->assertViewHas('ordersByStatus', function (array $ordersByStatus): bool {
            return $ordersByStatus['shipped'] === 1
                && $ordersByStatus['canceled'] === 1
                && $ordersByStatus['processing'] === 1;
        });
    }

    /**
     * TC-VDAS-06
     * Verify revenue excludes canceled orders across day, month, and year aggregates.
     */
    public function test_dashboard_revenue_excludes_canceled(): void
    {
        $currentDate = Carbon::today();

        $this->seedOrder([
            'order_status' => 'Shipped',
            'grand_total' => 100,
            'shipping_charges' => 10,
            'created_at' => $currentDate,
            'updated_at' => $currentDate,
        ]);
        $this->seedOrder([
            'order_status' => 'Canceled',
            'grand_total' => 200,
            'shipping_charges' => 10,
            'created_at' => $currentDate,
            'updated_at' => $currentDate,
        ]);

        $this->actingAs($this->createVendorTypeAdminAccount(), 'admin');

        $response = $this->get('/admin/dashboard');

        $response->assertStatus(200);
        $response->assertViewHas('revenueByDay', function ($revenueByDay): bool {
            return $revenueByDay->count() === 1 && (float) $revenueByDay[0]->total === 90.0;
        });
        $response->assertViewHas('revenueByMonth', function ($revenueByMonth): bool {
            return $revenueByMonth->count() === 1 && (float) $revenueByMonth[0]->total === 90.0;
        });
        $response->assertViewHas('revenueByYear', function ($revenueByYear): bool {
            return $revenueByYear->count() === 1 && (float) $revenueByYear[0]->total === 90.0;
        });
    }

    /**
     * TC-VDAS-07
     * Verify revenue by month only includes the current year.
     */
    public function test_dashboard_revenue_by_month_current_year(): void
    {
        $currentYear = Carbon::now()->year;
        $lastYear = Carbon::now()->subYear()->year;

        $this->seedOrder([
            'order_status' => 'Shipped',
            'grand_total' => 100,
            'shipping_charges' => 10,
            'created_at' => Carbon::create($lastYear, 5, 10, 12, 0, 0),
            'updated_at' => Carbon::create($lastYear, 5, 10, 12, 0, 0),
        ]);
        $this->seedOrder([
            'order_status' => 'Shipped',
            'grand_total' => 100,
            'shipping_charges' => 10,
            'created_at' => Carbon::create($currentYear, 6, 10, 12, 0, 0),
            'updated_at' => Carbon::create($currentYear, 6, 10, 12, 0, 0),
        ]);

        $this->actingAs($this->createVendorTypeAdminAccount(), 'admin');

        $response = $this->get('/admin/dashboard');

        $response->assertStatus(200);
        $response->assertViewHas('revenueByMonth', function ($revenueByMonth): bool {
            return $revenueByMonth->count() === 1 && (float) $revenueByMonth[0]->total === 90.0;
        });
    }

    /**
     * TC-VDAS-08
     * Verify the dashboard returns the top five products for quantity, revenue,
     * and stock in descending order.
     */
    public function test_dashboard_top_five_product_stats(): void
    {
        $this->seedCustomerUsers(1);

        $products = [];
        for ($index = 1; $index <= 6; $index++) {
            $products[] = [
                'product_name' => 'Product ' . $index,
                'product_code' => 'P00' . $index,
                'product_price' => $index * 10,
                'created_at' => Carbon::today(),
                'updated_at' => Carbon::today(),
            ];
        }
        $this->seedProducts($products);

        // Create one order so the controller's grouped product queries have data to read.
        $orderId = $this->seedOrder([
            'order_status' => 'Shipped',
            'grand_total' => 600,
            'shipping_charges' => 10,
            'created_at' => Carbon::today(),
        ]);

        $productRows = DB::table('products')->orderBy('id')->get();
        foreach ($productRows as $index => $productRow) {
            $rank = $index + 1;
            $this->seedOrderItem([
                'order_id' => $orderId,
                'product_id' => $productRow->id,
                'product_code' => $productRow->product_code,
                'product_name' => $productRow->product_name,
                'product_price' => $rank * 10,
                'product_qty' => $rank,
                'item_status' => 'New',
                'created_at' => Carbon::today(),
                'updated_at' => Carbon::today(),
            ]);

            $this->seedProductAttribute([
                'product_id' => $productRow->id,
                'size' => 'M',
                'price' => $rank * 10,
                'stock' => $rank * 50,
                'sku' => 'SKU-' . $rank,
                'status' => 1,
                'created_at' => Carbon::today(),
                'updated_at' => Carbon::today(),
            ]);
        }

        $this->actingAs($this->createVendorTypeAdminAccount(), 'admin');

        $response = $this->get('/admin/dashboard');

        $response->assertStatus(200);
        $response->assertViewHas('mostPurchased', function ($mostPurchased): bool {
            return $mostPurchased->count() === 5
                && $mostPurchased[0]->product->product_name === 'Product 6'
                && $mostPurchased[4]->product->product_name === 'Product 2';
        });
        $response->assertViewHas('bestSelling', function ($bestSelling): bool {
            return $bestSelling->count() === 5
                && $bestSelling[0]->product->product_name === 'Product 6'
                && $bestSelling[4]->product->product_name === 'Product 2';
        });
        $response->assertViewHas('mostInStock', function ($mostInStock): bool {
            return $mostInStock->count() === 5
                && $mostInStock[0]->product_name === 'Product 6'
                && $mostInStock[4]->product_name === 'Product 2';
        });
    }

    /**
     * TC-VDAS-09
     * Defect case: the controller currently takes the oldest seven rows instead
     * of the latest seven rows because it orders ascending before take(7).
     */
    public function test_dashboard_revenue_by_day_logic_defect(): void
    {
        for ($dayOffset = 0; $dayOffset < 10; $dayOffset++) {
            $day = Carbon::today()->subDays($dayOffset);
            $this->seedOrder([
                'order_status' => 'Shipped',
                'grand_total' => 100,
                'shipping_charges' => 10,
                'created_at' => $day,
                'updated_at' => $day,
            ]);
        }

        $this->actingAs($this->createVendorTypeAdminAccount(), 'admin');

        $response = $this->get('/admin/dashboard');

        $response->assertStatus(200);
        $response->assertViewHas('revenueByDay', function ($revenueByDay): bool {
            $latestDay = Carbon::today()->toDateString();
            return $revenueByDay->first()->date === $latestDay;
        });
    }
}
