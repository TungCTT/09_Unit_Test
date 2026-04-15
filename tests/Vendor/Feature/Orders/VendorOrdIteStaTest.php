<?php

namespace Tests\Vendor\Feature\Orders;

use App\Models\Admin;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class VendorOrdIteStaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Rollback strategy:
        // Rebuild database from the approved migration file before each test.
        $this->artisan('migrate:fresh', [
            '--path' => 'database/migrations/2026_04_14_212542_update_database_schema_v2.php',
            '--force' => true,
        ])->assertExitCode(0);

        $this->ensureOrderSupportTablesExist();

        // Prevent external mail transport side-effects while still allowing facade spying.
        config(['mail.default' => 'array']);
    }

    /**
     * Create test-only tables needed by updateOrderItemStatus().
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

    private function createOrderItem(int $orderId, int $vendorId, array $overrides = []): int
    {
        return (int) DB::table('orders_products')->insertGetId(array_merge([
            'order_id' => $orderId,
            'vendor_id' => $vendorId,
            'product_id' => 1,
            'product_name' => 'Sample Product',
            'product_code' => 'SP-01',
            'product_color' => 'Black',
            'product_size' => 'M',
            'product_qty' => 1,
            'product_price' => 100,
            'item_status' => 'Pending',
            'courier_name' => null,
            'tracking_number' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function createVendorAdmin(int $vendorId, string $email): Admin
    {
        $admin = new Admin();
        $admin->name = 'Vendor Admin';
        $admin->type = 'vendor';
        $admin->vendor_id = $vendorId;
        $admin->mobile = '0900111222';
        $admin->email = $email;
        $admin->password = Hash::make('secret123');
        $admin->confirm = 'Yes';
        $admin->status = 1;
        $admin->save();

        return $admin;
    }

    private function seedVendorOwnedItem(): array
    {
        $userId = $this->createUser();
        $orderId = $this->createOrder($userId);
        $itemId = $this->createOrderItem($orderId, 1001);

        return [$orderId, $itemId];
    }

    // ---------------------------------------------------------------------
    // OrderController@updateOrderItemStatus() vendor module test cases
    // ---------------------------------------------------------------------

    /**
     * TC-VUOIS-01
     * Block unauthenticated update attempts.
     */
    public function test_update_item_status_guest_redirect(): void
    {
        [$orderId, $itemId] = $this->seedVendorOwnedItem();

        $response = $this->post('/admin/update-order-item-status', [
            'order_item_id' => $itemId,
            'order_item_status' => 'Shipped',
        ]);

        $response->assertRedirect('/admin/login');

        // CheckDB: no mutation should happen when user is not authenticated.
        $this->assertDatabaseHas('orders_products', [
            'id' => $itemId,
            'item_status' => 'Pending',
        ]);
    }

    /**
     * TC-VUOIS-02
     * Reject non-POST access in a safe way.
     */
    public function test_update_item_status_requires_post(): void
    {
        $admin = $this->createVendorAdmin(1001, 'vendor-non-post@example.com');
        $this->actingAs($admin, 'admin');

        $response = $this->get('/admin/update-order-item-status');

        $this->assertNotSame(500, $response->getStatusCode());
    }

    /**
     * TC-VUOIS-03
     * Update item status successfully for valid payload.
     */
    public function test_update_item_status_success_basic(): void
    {
        [$orderId, $itemId] = $this->seedVendorOwnedItem();
        $admin = $this->createVendorAdmin(1001, 'vendor-update-basic@example.com');
        $this->actingAs($admin, 'admin');

        $response = $this->post('/admin/update-order-item-status', [
            'order_item_id' => $itemId,
            'order_item_status' => 'Shipped',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success_message', 'Order Item Status has been updated successfully!');

        // CheckDB: confirm row changed exactly as expected.
        $this->assertDatabaseHas('orders_products', [
            'id' => $itemId,
            'item_status' => 'Shipped',
        ]);
    }

    /**
     * TC-VUOIS-04
     * Update tracking fields when both courier and tracking number are provided.
     */
    public function test_update_item_status_with_tracking(): void
    {
        [$orderId, $itemId] = $this->seedVendorOwnedItem();
        $admin = $this->createVendorAdmin(1001, 'vendor-update-tracking@example.com');
        $this->actingAs($admin, 'admin');

        $response = $this->post('/admin/update-order-item-status', [
            'order_item_id' => $itemId,
            'order_item_status' => 'Shipped',
            'item_courier_name' => 'DHL',
            'item_tracking_number' => 'TRACK-1001',
        ]);

        $response->assertRedirect();

        // CheckDB: item status and tracking data must both be updated.
        $this->assertDatabaseHas('orders_products', [
            'id' => $itemId,
            'item_status' => 'Shipped',
            'courier_name' => 'DHL',
            'tracking_number' => 'TRACK-1001',
        ]);
    }

    /**
     * TC-VUOIS-05
     * Without courier/tracking, only status should change.
     */
    public function test_update_item_status_without_tracking(): void
    {
        $userId = $this->createUser('buyer-no-track@example.com');
        $orderId = $this->createOrder($userId);
        $itemId = $this->createOrderItem($orderId, 1001, [
            'courier_name' => null,
            'tracking_number' => null,
        ]);

        $admin = $this->createVendorAdmin(1001, 'vendor-update-no-tracking@example.com');
        $this->actingAs($admin, 'admin');

        $response = $this->post('/admin/update-order-item-status', [
            'order_item_id' => $itemId,
            'order_item_status' => 'In Progress',
        ]);

        $response->assertRedirect();

        // CheckDB: status changes while tracking fields stay empty.
        $this->assertDatabaseHas('orders_products', [
            'id' => $itemId,
            'item_status' => 'In Progress',
            'courier_name' => null,
            'tracking_number' => null,
        ]);
    }

    /**
     * TC-VUOIS-06
     * Confirm that a log record is written after update.
     */
    public function test_update_item_status_creates_log(): void
    {
        [$orderId, $itemId] = $this->seedVendorOwnedItem();
        $admin = $this->createVendorAdmin(1001, 'vendor-log@example.com');
        $this->actingAs($admin, 'admin');

        $response = $this->post('/admin/update-order-item-status', [
            'order_item_id' => $itemId,
            'order_item_status' => 'Shipped',
        ]);

        $response->assertRedirect();

        // CheckDB: verify log tuple generated from controller branch.
        $this->assertDatabaseHas('orders_logs', [
            'order_id' => $orderId,
            'order_item_id' => $itemId,
            'order_status' => 'Shipped',
        ]);
    }

    /**
     * TC-VUOIS-07
     * Mail should be sent with tracking payload when tracking fields are provided.
     */
    public function test_update_item_status_sends_mail_with_tracking(): void
    {
        [$orderId, $itemId] = $this->seedVendorOwnedItem();
        $admin = $this->createVendorAdmin(1001, 'vendor-mail-track@example.com');
        $this->actingAs($admin, 'admin');

        Mail::spy();

        $response = $this->post('/admin/update-order-item-status', [
            'order_item_id' => $itemId,
            'order_item_status' => 'Shipped',
            'item_courier_name' => 'VNPost',
            'item_tracking_number' => 'VN-TRACK-01',
        ]);

        $response->assertRedirect();
        Mail::shouldHaveReceived('send')->once();
    }

    /**
     * TC-VUOIS-08
     * Mail should be sent without tracking payload when tracking inputs are absent.
     */
    public function test_update_item_status_sends_mail_without_tracking(): void
    {
        [$orderId, $itemId] = $this->seedVendorOwnedItem();
        $admin = $this->createVendorAdmin(1001, 'vendor-mail-no-track@example.com');
        $this->actingAs($admin, 'admin');

        Mail::spy();

        $response = $this->post('/admin/update-order-item-status', [
            'order_item_id' => $itemId,
            'order_item_status' => 'In Progress',
        ]);

        $response->assertRedirect();
        Mail::shouldHaveReceived('send')->once();
    }

    /**
     * TC-VUOIS-09
     * Graceful handling expectation for invalid item id.
     *
     * QA expectation: no server crash.
     */
    public function test_update_item_status_invalid_item_id_graceful(): void
    {
        $admin = $this->createVendorAdmin(1001, 'vendor-invalid-item@example.com');
        $this->actingAs($admin, 'admin');

        $response = $this->post('/admin/update-order-item-status', [
            'order_item_id' => 999999,
            'order_item_status' => 'Shipped',
        ]);

        $this->assertTrue(in_array($response->getStatusCode(), [302, 404], true));
    }

    /**
     * TC-VUOIS-10
     * IDOR defect check: vendor must not update another vendor's item.
     *
     * QA expectation: reject update and keep database unchanged.
     */
    public function test_update_item_status_vendor_cannot_update_other_vendor_item(): void
    {
        $userId = $this->createUser('buyer-idor@example.com');
        $orderId = $this->createOrder($userId);
        $itemIdVendorB = $this->createOrderItem($orderId, 2002, ['item_status' => 'Pending']);

        $adminVendorA = $this->createVendorAdmin(1001, 'vendor-a-idor@example.com');
        $this->actingAs($adminVendorA, 'admin');

        $response = $this->post('/admin/update-order-item-status', [
            'order_item_id' => $itemIdVendorB,
            'order_item_status' => 'Canceled',
        ]);

        // Expected secure behavior: no cross-vendor modification.
        $this->assertDatabaseHas('orders_products', [
            'id' => $itemIdVendorB,
            'item_status' => 'Pending',
        ]);
    }
}
