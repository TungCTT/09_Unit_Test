<?php

namespace Tests\Vendor\Feature\Orders;

use App\Models\Admin;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class VendorOrdDetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Rollback strategy:
        // Rebuild database for every test from the dedicated migration file only.
        $this->artisan('migrate:fresh', [
            '--path' => 'database/migrations/2026_04_14_212542_update_database_schema_v2.php',
            '--force' => true,
        ])->assertExitCode(0);

        $this->ensureOrderSupportTablesExist();
    }

    /**
     * Create test-only tables required by orderDetails() data graph.
     */
    private function ensureOrderSupportTablesExist(): void
    {
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
                $table->string('password')->nullable();
                $table->tinyInteger('status')->default(1);
                $table->rememberToken();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('orders')) {
            Schema::create('orders', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('name');
                $table->string('email');
                $table->string('mobile')->nullable();
                $table->string('address')->nullable();
                $table->string('city')->nullable();
                $table->string('state')->nullable();
                $table->string('country')->nullable();
                $table->string('pincode')->nullable();
                $table->decimal('coupon_amount', 10, 2)->default(0);
                $table->string('coupon_code')->nullable();
                $table->decimal('shipping_charges', 10, 2)->default(0);
                $table->decimal('grand_total', 10, 2)->default(0);
                $table->decimal('commission', 10, 2)->default(0);
                $table->string('order_status')->default('Pending');
                $table->string('payment_method')->nullable();
                $table->string('payment_gateway')->nullable();
                $table->string('courier_name')->nullable();
                $table->string('tracking_number')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('orders_products')) {
            Schema::create('orders_products', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('order_id');
                $table->unsignedBigInteger('vendor_id')->default(0);
                $table->unsignedBigInteger('product_id')->nullable();
                $table->string('product_name')->nullable();
                $table->string('product_code')->nullable();
                $table->string('product_color')->nullable();
                $table->string('product_size')->nullable();
                $table->integer('product_qty')->default(1);
                $table->decimal('product_price', 10, 2)->default(0);
                $table->decimal('commission', 10, 2)->default(0);
                $table->string('item_status')->nullable();
                $table->string('courier_name')->nullable();
                $table->string('tracking_number')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('coupons')) {
            Schema::create('coupons', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('vendor_id')->default(0);
                $table->string('coupon_code')->nullable();
                $table->string('amount_type')->nullable();
                $table->decimal('amount', 10, 2)->default(0);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('products')) {
            Schema::create('products', function (Blueprint $table): void {
                $table->id();
                $table->string('product_name')->nullable();
                $table->string('product_image')->nullable();
                $table->timestamps();
            });

            DB::table('products')->insert([
                'id' => 1,
                'product_name' => 'Sample Product',
                'product_image' => 'default.png',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (!Schema::hasTable('orders_logs')) {
            Schema::create('orders_logs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('order_id');
                $table->unsignedBigInteger('order_item_id')->nullable();
                $table->string('order_status')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('order_statuses')) {
            Schema::create('order_statuses', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->tinyInteger('status')->default(1);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('order_item_statuses')) {
            Schema::create('order_item_statuses', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->tinyInteger('status')->default(1);
                $table->timestamps();
            });
        }
    }

    private function createUser(string $email = 'buyer@example.com'): int
    {
        return (int) DB::table('users')->insertGetId([
            'name' => 'Buyer User',
            'address' => 'Buyer Address',
            'city' => 'Da Nang',
            'state' => 'DN',
            'country' => 'Vietnam',
            'pincode' => '550000',
            'mobile' => '0911222333',
            'email' => $email,
            'password' => Hash::make('secret123'),
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createOrder(int $userId, array $overrides = []): int
    {
        return (int) DB::table('orders')->insertGetId(array_merge([
            'user_id' => $userId,
            'name' => 'Buyer User',
            'email' => 'buyer@example.com',
            'mobile' => '0911222333',
            'address' => 'Test Address',
            'city' => 'Da Nang',
            'state' => 'DN',
            'country' => 'Vietnam',
            'pincode' => '550000',
            'coupon_amount' => 0,
            'coupon_code' => null,
            'shipping_charges' => 0,
            'grand_total' => 200,
            'commission' => 0,
            'order_status' => 'Pending',
            'payment_method' => 'COD',
            'payment_gateway' => 'COD',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function createOrderItem(int $orderId, int $vendorId, int $qty = 1): int
    {
        return (int) DB::table('orders_products')->insertGetId([
            'order_id' => $orderId,
            'vendor_id' => $vendorId,
            'product_id' => 1,
            'product_name' => 'Sample Product',
            'product_code' => 'SP-01',
            'product_color' => 'Black',
            'product_size' => 'M',
            'product_qty' => $qty,
            'product_price' => 100,
            'commission' => 0,
            'item_status' => 'Pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedStatusesAndLogs(int $orderId, ?int $orderItemId = null): void
    {
        DB::table('order_statuses')->insert([
            ['name' => 'Pending', 'status' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Shipped', 'status' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('order_item_statuses')->insert([
            ['name' => 'Pending', 'status' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Shipped', 'status' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('orders_logs')->insert([
            'order_id' => $orderId,
            'order_item_id' => $orderItemId,
            'order_status' => 'Pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createAdminAccount(array $overrides = []): Admin
    {
        $admin = new Admin();
        $admin->name = $overrides['name'] ?? 'Vendor Admin';
        $admin->type = $overrides['type'] ?? 'vendor';
        $admin->vendor_id = $overrides['vendor_id'] ?? 1;
        $admin->mobile = $overrides['mobile'] ?? '0900111222';
        $admin->email = $overrides['email'] ?? 'vendor-admin@example.com';
        $admin->password = Hash::make($overrides['password'] ?? 'secret123');
        $admin->confirm = $overrides['confirm'] ?? 'Yes';
        $admin->status = $overrides['status'] ?? 1;
        $admin->save();

        return $admin;
    }

    // ---------------------------------------------------------------------
    // OrderController@orderDetails() vendor module test cases
    // ---------------------------------------------------------------------

    /**
     * TC-VORDD-01
     * Block unauthenticated access.
     */
    public function test_order_details_guest_redirect(): void
    {
        $response = $this->get('/admin/orders/1');

        $response->assertRedirect('/admin/login');
    }

    /**
     * TC-VORDD-02
     * Restrict unapproved vendor from order details page.
     */
    public function test_order_details_vendor_unapproved_redirect(): void
    {
        $userId = $this->createUser();
        $orderId = $this->createOrder($userId);

        $admin = $this->createAdminAccount([
            'type' => 'vendor',
            'vendor_id' => 1001,
            'status' => 0,
            'email' => 'vendor-unapproved-detail@example.com',
        ]);
        $this->actingAs($admin, 'admin');

        $response = $this->get('/admin/orders/' . $orderId);

        $response->assertRedirect('admin/update-vendor-details/personal');
        $response->assertSessionHas('error_message');
    }

    /**
     * TC-VORDD-03
     * Approved vendor can open order details view.
     */
    public function test_order_details_vendor_approved_access(): void
    {
        $userId = $this->createUser('buyer3@example.com');
        $orderId = $this->createOrder($userId);
        $itemId = $this->createOrderItem($orderId, 1001, 1);
        $this->seedStatusesAndLogs($orderId, $itemId);

        $admin = $this->createAdminAccount([
            'vendor_id' => 1001,
            'status' => 1,
            'email' => 'vendor-approved-detail@example.com',
        ]);
        $this->actingAs($admin, 'admin');

        $response = $this->get('/admin/orders/' . $orderId);

        $response->assertStatus(200);
        $response->assertViewIs('admin.orders.order_details');
    }

    /**
     * TC-VORDD-04
     * Vendor relation is filtered to own items only.
     */
    public function test_order_details_vendor_only_sees_own_items(): void
    {
        $userId = $this->createUser('buyer4@example.com');
        $orderId = $this->createOrder($userId);
        $itemIdVendorA = $this->createOrderItem($orderId, 1001, 1);
        $this->createOrderItem($orderId, 2002, 2);
        $this->seedStatusesAndLogs($orderId, $itemIdVendorA);

        $admin = $this->createAdminAccount([
            'vendor_id' => 1001,
            'status' => 1,
            'email' => 'vendor-own-items@example.com',
        ]);
        $this->actingAs($admin, 'admin');

        $response = $this->get('/admin/orders/' . $orderId);

        $response->assertStatus(200);
        $orderDetails = $response->viewData('orderDetails');

        $this->assertNotEmpty($orderDetails['orders_products']);
        foreach ($orderDetails['orders_products'] as $item) {
            $this->assertSame(1001, (int) $item['vendor_id']);
        }
    }

    /**
     * TC-VORDD-05
     * Related arrays must be loaded correctly.
     */
    public function test_order_details_loads_related_data(): void
    {
        $userId = $this->createUser('buyer5@example.com');
        $orderId = $this->createOrder($userId);
        $itemId = $this->createOrderItem($orderId, 1001, 1);
        $this->seedStatusesAndLogs($orderId, $itemId);

        $admin = $this->createAdminAccount([
            'vendor_id' => 1001,
            'status' => 1,
            'email' => 'vendor-related@example.com',
        ]);
        $this->actingAs($admin, 'admin');

        $response = $this->get('/admin/orders/' . $orderId);

        $response->assertStatus(200);
        $this->assertNotEmpty($response->viewData('userDetails'));
        $this->assertNotEmpty($response->viewData('orderStatuses'));
        $this->assertNotEmpty($response->viewData('orderItemStatuses'));
        $this->assertNotEmpty($response->viewData('orderLog'));
    }

    /**
     * TC-VORDD-06
     * If coupon amount is zero then item_discount must be zero.
     */
    public function test_order_details_item_discount_zero(): void
    {
        $userId = $this->createUser('buyer6@example.com');
        $orderId = $this->createOrder($userId, ['coupon_amount' => 0]);
        $itemId = $this->createOrderItem($orderId, 1001, 2);
        $this->seedStatusesAndLogs($orderId, $itemId);

        $admin = $this->createAdminAccount([
            'vendor_id' => 1001,
            'status' => 1,
            'email' => 'vendor-discount-zero@example.com',
        ]);
        $this->actingAs($admin, 'admin');

        $response = $this->get('/admin/orders/' . $orderId);

        $response->assertStatus(200);
        $this->assertSame(0, $response->viewData('item_discount'));
    }

    /**
     * TC-VORDD-07
     * Coupon amount should be distributed and rounded by total items.
     */
    public function test_order_details_item_discount_calculated(): void
    {
        $userId = $this->createUser('buyer7@example.com');
        DB::table('coupons')->insert([
            'vendor_id' => 0,
            'coupon_code' => 'SAVE10',
            'amount_type' => 'Fixed',
            'amount' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $orderId = $this->createOrder($userId, [
            'coupon_amount' => 10,
            'coupon_code' => 'SAVE10',
        ]);
        $itemId = $this->createOrderItem($orderId, 1001, 3); // total_items = 3
        $this->seedStatusesAndLogs($orderId, $itemId);

        $admin = $this->createAdminAccount([
            'vendor_id' => 1001,
            'status' => 1,
            'email' => 'vendor-discount-calc@example.com',
        ]);
        $this->actingAs($admin, 'admin');

        $response = $this->get('/admin/orders/' . $orderId);

        $response->assertStatus(200);
        $this->assertSame(3.33, (float) $response->viewData('item_discount'));
    }

    /**
     * TC-VORDD-08
     * Graceful handling expectation for invalid order id.
     *
     * This test is intentionally QA-style. If current code crashes, test fails.
     */
    public function test_order_details_invalid_order_id_graceful(): void
    {
        $admin = $this->createAdminAccount([
            'vendor_id' => 1001,
            'status' => 1,
            'email' => 'vendor-invalid-order@example.com',
        ]);
        $this->actingAs($admin, 'admin');

        $response = $this->get('/admin/orders/999999');

        $this->assertTrue(in_array($response->getStatusCode(), [302, 404], true));
    }

    /**
     * TC-VORDD-09
     * Vendor can open order, but must not see items of other vendors.
     */
    public function test_order_details_vendor_not_owner_blocked(): void
    {
        $userId = $this->createUser('buyer8@example.com');
        $orderId = $this->createOrder($userId);
        $itemId = $this->createOrderItem($orderId, 2002, 1);
        $this->seedStatusesAndLogs($orderId, $itemId);

        $admin = $this->createAdminAccount([
            'vendor_id' => 1001,
            'status' => 1,
            'email' => 'vendor-not-owner@example.com',
        ]);
        $this->actingAs($admin, 'admin');

        $response = $this->get('/admin/orders/' . $orderId);

        $response->assertStatus(200);
        $orderDetails = $response->viewData('orderDetails');
        $this->assertCount(0, $orderDetails['orders_products']);
    }

    /**
     * TC-VORDD-10
     * Session page marker should be set to orders.
     */
    public function test_order_details_sets_session_page(): void
    {
        $userId = $this->createUser('buyer9@example.com');
        $orderId = $this->createOrder($userId);
        $itemId = $this->createOrderItem($orderId, 1001, 1);
        $this->seedStatusesAndLogs($orderId, $itemId);

        $admin = $this->createAdminAccount([
            'vendor_id' => 1001,
            'status' => 1,
            'email' => 'vendor-session-detail@example.com',
        ]);
        $this->actingAs($admin, 'admin');

        $response = $this->get('/admin/orders/' . $orderId);

        $response->assertStatus(200);
        $response->assertSessionHas('page', 'orders');
    }
}
