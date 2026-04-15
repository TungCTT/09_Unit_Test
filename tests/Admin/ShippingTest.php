<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\ShippingCharge;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ShippingTest - Feature tests for Admin\ShippingController
 *
 * Covered controller methods:
 *  - shippingCharges()
 *  - updateShippingStatus()
 *  - editShippingCharges()
 */
class ShippingTest extends TestCase
{
    protected static bool $shippingSchemaReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (!self::$shippingSchemaReady) {
            $backupMigrations = glob(database_path('migrations/*_backup.php')) ?: [];
            sort($backupMigrations);
            $latestBackupMigration = end($backupMigrations);

            if ($latestBackupMigration === false) {
                $this->fail('Cannot find *_backup migration file in database/migrations.');
            }

            $this->artisan('migrate:fresh', [
                '--path' => $latestBackupMigration,
                '--realpath' => true,
                '--force' => true,
            ])->assertExitCode(0);

            self::$shippingSchemaReady = true;
        }

        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        parent::tearDown();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /** Create an admin account for guard('admin'). */
    private function makeAdmin(array $attrs = []): Admin
    {
        $admin = new Admin();
        $admin->name = $attrs['name'] ?? 'Test Admin';
        $admin->type = $attrs['type'] ?? 'superadmin';
        $admin->vendor_id = $attrs['vendor_id'] ?? 0;
        $admin->mobile = $attrs['mobile'] ?? '0123456789';
        $admin->email = $attrs['email'] ?? 'admin_' . Str::random(8) . '@test.com';
        $admin->password = bcrypt($attrs['password'] ?? 'password123');
        $admin->image = $attrs['image'] ?? null;
        $admin->confirm = $attrs['confirm'] ?? 'Yes';
        $admin->status = $attrs['status'] ?? 1;
        $admin->save();

        return $admin;
    }

    /** Create a shipping charge row. */
    private function makeShippingCharge(array $attrs = []): ShippingCharge
    {
        $shipping = new ShippingCharge();
        $shipping->country = $attrs['country'] ?? 'Vietnam';
        $shipping->{'0_500g'} = $attrs['0_500g'] ?? 1.0;
        $shipping->{'501g_1000g'} = $attrs['501g_1000g'] ?? 2.0;
        $shipping->{'1001_2000g'} = $attrs['1001_2000g'] ?? 3.0;
        $shipping->{'2001g_5000g'} = $attrs['2001g_5000g'] ?? 4.0;
        $shipping->{'above_5000g'} = $attrs['above_5000g'] ?? 5.0;
        $shipping->status = $attrs['status'] ?? 1;
        $shipping->save();

        return $shipping;
    }

    /** Login as admin. */
    private function loginAdmin(?Admin $admin = null): Admin
    {
        $admin = $admin ?? $this->makeAdmin();
        $this->actingAs($admin, 'admin');

        return $admin;
    }

    // =========================================================================
    // 1. shippingCharges()
    // =========================================================================

    public function test_shippingCharges_redirects_unauthenticated_admin(): void
    {
        $response = $this->get('/admin/shipping-charges');

        $response->assertRedirect('/admin/login');
    }

    public function test_shippingCharges_returns_view_with_shipping_data_for_authenticated_admin(): void
    {
        $this->loginAdmin();
        $this->makeShippingCharge();

        $response = $this->get('/admin/shipping-charges');

        $response->assertStatus(200);
        $response->assertViewIs('admin.shipping.shipping_charges');
        $response->assertViewHas('shippingCharges');
        $this->assertIsArray($response->viewData('shippingCharges'));
    }

    // =========================================================================
    // 2. updateShippingStatus()
    // =========================================================================

    public function test_updateShippingStatus_redirects_unauthenticated_admin(): void
    {
        $shipping = $this->makeShippingCharge(['status' => 1]);

        $response = $this->post('/admin/update-shipping-status', [
            'shipping_id' => $shipping->id,
            'status' => 'Active',
        ]);

        $response->assertRedirect('/admin/login');
    }

    public function test_updateShippingStatus_non_ajax_request_does_not_change_status(): void
    {
        $this->loginAdmin();
        $shipping = $this->makeShippingCharge(['status' => 1]);

        $response = $this->post('/admin/update-shipping-status', [
            'shipping_id' => $shipping->id,
            'status' => 'Active',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('shipping_charges', [
            'id' => $shipping->id,
            'status' => 1,
        ]);
    }

    public function test_updateShippingStatus_sets_status_to_inactive_when_current_is_active(): void
    {
        $this->loginAdmin();
        $shipping = $this->makeShippingCharge(['status' => 1]);

        $response = $this->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->postJson('/admin/update-shipping-status', [
                'shipping_id' => $shipping->id,
                'status' => 'Active',
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 0,
            'shipping_id' => $shipping->id,
        ]);

        $this->assertDatabaseHas('shipping_charges', [
            'id' => $shipping->id,
            'status' => 0,
        ]);
    }

    public function test_updateShippingStatus_sets_status_to_active_when_current_is_inactive(): void
    {
        $this->loginAdmin();
        $shipping = $this->makeShippingCharge(['status' => 0]);

        $response = $this->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->postJson('/admin/update-shipping-status', [
                'shipping_id' => $shipping->id,
                'status' => 'Inactive',
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 1,
            'shipping_id' => $shipping->id,
        ]);

        $this->assertDatabaseHas('shipping_charges', [
            'id' => $shipping->id,
            'status' => 1,
        ]);
    }

    // =========================================================================
    // 3. editShippingCharges()
    // =========================================================================

    public function test_editShippingCharges_get_redirects_unauthenticated_admin(): void
    {
        $shipping = $this->makeShippingCharge();

        $response = $this->get('/admin/edit-shipping-charges/' . $shipping->id);

        $response->assertRedirect('/admin/login');
    }

    public function test_editShippingCharges_get_returns_view_for_authenticated_admin(): void
    {
        $this->loginAdmin();
        $shipping = $this->makeShippingCharge();

        $response = $this->get('/admin/edit-shipping-charges/' . $shipping->id);

        $response->assertStatus(200);
        $response->assertViewIs('admin.shipping.edit_shipping_charges');
        $response->assertViewHas('shippingDetails');
        $response->assertViewHas('title', 'Edit Shipping Charges');
    }

    public function test_editShippingCharges_post_updates_shipping_rates_successfully(): void
    {
        $this->loginAdmin();
        $shipping = $this->makeShippingCharge([
            '0_500g' => 1.0,
            '501g_1000g' => 2.0,
            '1001_2000g' => 3.0,
            '2001g_5000g' => 4.0,
            'above_5000g' => 5.0,
        ]);

        $response = $this->post('/admin/edit-shipping-charges/' . $shipping->id, [
            '0_500g' => 10.5,
            '501g_1000g' => 20.5,
            '1001_2000g' => 30.5,
            '2001g_5000g' => 40.5,
            'above_5000g' => 50.5,
        ]);

        $response->assertStatus(302);
        $response->assertSessionHas('success_message', 'Shipping Charges updated successfully!');

        $this->assertDatabaseHas('shipping_charges', [
            'id' => $shipping->id,
            '0_500g' => 10.5,
            '501g_1000g' => 20.5,
            '1001_2000g' => 30.5,
            '2001g_5000g' => 40.5,
            'above_5000g' => 50.5,
        ]);
    }
}
