<?php

namespace Tests\Vendor\Feature\Orders;

use App\Models\Admin;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class VendorOrdTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Rollback strategy:
        // Rebuild database for every test run from the single approved migration file.
        $this->artisan('migrate:fresh', [
            '--path' => 'database/migrations/2026_04_14_212542_update_database_schema_v2.php',
            '--force' => true,
        ])->assertExitCode(0);

        $this->ensureOrderSupportTablesExist();
    }

    /**
     * Create test-only tables needed by OrderController methods.
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
                $table->string('item_status')->nullable();
                $table->string('courier_name')->nullable();
                $table->string('tracking_number')->nullable();
                $table->timestamps();
            });
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
            'item_status' => 'Pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Create an admin account for vendor/admin guard scenarios.
     */
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
    // OrderController@orders() vendor module test cases
    // ---------------------------------------------------------------------

    /**
     * TC-VORD-01
     * Block unauthenticated access to vendor orders listing.
     */
    public function test_orders_guest_redirect(): void
    {
        $response = $this->get('/admin/orders');

        $response->assertRedirect('/admin/login');
    }

    /**
     * TC-VORD-02
     * Restrict unapproved vendor accounts from accessing orders page.
     */
    public function test_orders_vendor_unapproved_redirect(): void
    {
        $admin = $this->createAdminAccount([
            'type' => 'vendor',
            'vendor_id' => 1001,
            'status' => 0,
            'email' => 'vendor-unapproved@example.com',
        ]);
        $this->actingAs($admin, 'admin');

        $response = $this->get('/admin/orders');

        $response->assertRedirect('admin/update-vendor-details/personal');
        $response->assertSessionHas('error_message');
    }

    /**
     * TC-VORD-03
     * Allow approved vendor to access orders view.
     */
    public function test_orders_vendor_approved_access(): void
    {
        $userId = $this->createUser();
        $orderId = $this->createOrder($userId);
        $this->createOrderItem($orderId, 1001, 1);

        $admin = $this->createAdminAccount([
            'type' => 'vendor',
            'vendor_id' => 1001,
            'status' => 1,
            'email' => 'vendor-approved@example.com',
        ]);
        $this->actingAs($admin, 'admin');

        $response = $this->get('/admin/orders');

        $response->assertStatus(200);
        $response->assertViewIs('admin.orders.orders');
    }

    /**
     * TC-VORD-04
     * Verify vendor data isolation: only own order items are loaded.
     */
    public function test_orders_vendor_only_sees_own_items(): void
    {
        $userId = $this->createUser('buyer1@example.com');
        $orderId = $this->createOrder($userId);
        $this->createOrderItem($orderId, 1001, 1);
        $this->createOrderItem($orderId, 2002, 2);

        $admin = $this->createAdminAccount([
            'vendor_id' => 1001,
            'status' => 1,
            'email' => 'vendor-a@example.com',
        ]);
        $this->actingAs($admin, 'admin');

        $response = $this->get('/admin/orders');

        $response->assertStatus(200);
        $orders = $response->viewData('orders');

        $this->assertNotEmpty($orders);
        foreach ($orders as $order) {
            foreach ($order['orders_products'] as $item) {
                $this->assertSame(1001, (int) $item['vendor_id']);
            }
        }
    }

    /**
     * TC-VORD-05
     * Prevent leakage of other vendor items in relation list.
     */
    public function test_orders_vendor_sees_empty_items_for_other_vendor_orders(): void
    {
        $userId = $this->createUser('buyer2@example.com');
        $orderId = $this->createOrder($userId);
        $this->createOrderItem($orderId, 2002, 2);

        $admin = $this->createAdminAccount([
            'vendor_id' => 1001,
            'status' => 1,
            'email' => 'vendor-a2@example.com',
        ]);
        $this->actingAs($admin, 'admin');

        $response = $this->get('/admin/orders');

        $response->assertStatus(200);
        $orders = $response->viewData('orders');

        $target = collect($orders)->firstWhere('id', $orderId);
        $this->assertNotNull($target);
        $this->assertCount(0, $target['orders_products']);
    }

    /**
     * TC-VORD-06
     * Verify descending sorting by order ID.
     */
    public function test_orders_sorted_desc(): void
    {
        $userId = $this->createUser('buyer3@example.com');
        $olderOrderId = $this->createOrder($userId, ['created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);
        $newerOrderId = $this->createOrder($userId);
        $this->createOrderItem($olderOrderId, 1001, 1);
        $this->createOrderItem($newerOrderId, 1001, 1);

        $admin = $this->createAdminAccount([
            'vendor_id' => 1001,
            'status' => 1,
            'email' => 'vendor-sort@example.com',
        ]);
        $this->actingAs($admin, 'admin');

        $response = $this->get('/admin/orders');

        $response->assertStatus(200);
        $orders = array_values($response->viewData('orders'));

        $this->assertGreaterThanOrEqual(2, count($orders));
        $this->assertSame($newerOrderId, (int) $orders[0]['id']);
        $this->assertSame($olderOrderId, (int) $orders[1]['id']);
    }

    /**
     * TC-VORD-07
     * Ensure session marker page=orders is set.
     */
    public function test_orders_sets_session_page(): void
    {
        $userId = $this->createUser('buyer4@example.com');
        $orderId = $this->createOrder($userId);
        $this->createOrderItem($orderId, 1001, 1);

        $admin = $this->createAdminAccount([
            'vendor_id' => 1001,
            'status' => 1,
            'email' => 'vendor-session@example.com',
        ]);
        $this->actingAs($admin, 'admin');

        $response = $this->get('/admin/orders');

        $response->assertStatus(200);
        $response->assertSessionHas('page', 'orders');
    }

    /**
     * TC-VORD-08
     * Vendor should not receive full all-vendor order item scope.
     */
    public function test_orders_vendor_cannot_see_admin_only_full_scope(): void
    {
        $userId = $this->createUser('buyer5@example.com');
        $orderId = $this->createOrder($userId);
        $this->createOrderItem($orderId, 1001, 1);
        $this->createOrderItem($orderId, 2002, 1);

        // CheckDB pre-condition: two items truly exist at database level.
        $this->assertSame(2, DB::table('orders_products')->where('order_id', $orderId)->count());

        $admin = $this->createAdminAccount([
            'vendor_id' => 1001,
            'status' => 1,
            'email' => 'vendor-scope@example.com',
        ]);
        $this->actingAs($admin, 'admin');

        $response = $this->get('/admin/orders');

        $response->assertStatus(200);
        $orders = $response->viewData('orders');
        $target = collect($orders)->firstWhere('id', $orderId);

        $this->assertNotNull($target);
        $this->assertCount(1, $target['orders_products']);
        $this->assertSame(1001, (int) $target['orders_products'][0]['vendor_id']);
    }
}
