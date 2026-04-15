<?php

namespace Tests\Vendor\Feature\Authentication;

use App\Models\Admin;
use App\Models\Vendor;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class VendorLogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Only bootstrap the dedicated migration requested by the user.
        // This keeps the login tests isolated from the legacy broken migrations.
        $this->artisan('migrate:fresh', [
            '--path' => 'database/migrations/2026_04_14_212542_update_database_schema_v2.php',
            '--force' => true,
        ])->assertExitCode(0);
    }

    /**
     * Create a vendor account in the exact structure used by the shared
     * admin login flow.
     *
     * The login controller authenticates against the admins table, so this
     * helper creates both the vendor row and the matching admin row.
     */
    private function createVendorLoginAccount(array $overrides = []): array
    {
        $vendor = new Vendor();
        $vendor->name = $overrides['vendor_name'] ?? 'Vendor Login User';
        $vendor->mobile = $overrides['mobile'] ?? '0912345678';
        $vendor->email = $overrides['email'] ?? 'vendor.login@example.com';
        $vendor->address = $overrides['address'] ?? 'Vendor Address';
        $vendor->city = $overrides['city'] ?? 'Hanoi';
        $vendor->state = $overrides['state'] ?? 'Ha Noi';
        $vendor->country = $overrides['country'] ?? 'Vietnam';
        $vendor->pincode = $overrides['pincode'] ?? '100000';
        $vendor->confirm = $overrides['confirm'] ?? 'Yes';
        $vendor->commission = $overrides['commission'] ?? 0;
        $vendor->status = $overrides['status'] ?? 1;
        $vendor->save();

        $admin = new Admin();
        $admin->name = $overrides['admin_name'] ?? $vendor->name;
        $admin->type = 'vendor';
        $admin->vendor_id = $vendor->id;
        $admin->mobile = $vendor->mobile;
        $admin->email = $vendor->email;
        $admin->password = Hash::make($overrides['password'] ?? 'secret123');
        $admin->confirm = $overrides['confirm'] ?? 'Yes';
        $admin->status = $overrides['status'] ?? 1;
        $admin->save();

        return [
            'vendor' => $vendor,
            'admin' => $admin,
        ];
    }

    /**
     * Create a normal admin account that can authenticate through the same
     * shared login form.
     */
    private function createAdminLoginAccount(array $overrides = []): Admin
    {
        $admin = new Admin();
        $admin->name = $overrides['admin_name'] ?? 'Admin Login User';
        $admin->type = $overrides['type'] ?? 'admin';
        $admin->vendor_id = $overrides['vendor_id'] ?? 0;
        $admin->mobile = $overrides['mobile'] ?? '0987654321';
        $admin->email = $overrides['email'] ?? 'admin.login@example.com';
        $admin->password = Hash::make($overrides['password'] ?? 'secret123');
        $admin->confirm = $overrides['confirm'] ?? 'Yes';
        $admin->status = $overrides['status'] ?? 1;
        $admin->save();

        return $admin;
    }

    // ---------------------------------------------------------------------
    // AdminController@login() test cases
    // ---------------------------------------------------------------------

    /**
     * TC-VLOG-01
     * Verify that the login page is rendered for GET requests.
     */
    public function test_login_view_display(): void
    {
        $response = $this->get('/admin/login');

        // CheckDB: this request only renders the view and must not change the DB.
        $response->assertStatus(200);
        $response->assertViewIs('admin.login');
        $this->assertDatabaseCount('admins', 0);
        $this->assertDatabaseCount('vendors', 0);
    }

    /**
     * TC-VLOG-02
     * Reject login when the email field is empty.
     */
    public function test_login_fails_email_required(): void
    {
        $response = $this->from('/admin/login')->post('/admin/login', [
            'email' => '',
            'password' => '123',
        ]);

        $response->assertRedirect('/admin/login');
        $response->assertSessionHasErrors(['email' => 'Email Address is required!']);

        // CheckDB: validation failure must not create or update any account rows.
        $this->assertDatabaseCount('admins', 0);
        $this->assertDatabaseCount('vendors', 0);
    }

    /**
     * TC-VLOG-03
     * Reject login when the email value is not a valid email format.
     */
    public function test_login_fails_invalid_email(): void
    {
        $response = $this->from('/admin/login')->post('/admin/login', [
            'email' => 'bad_email@',
            'password' => '123',
        ]);

        $response->assertRedirect('/admin/login');
        $response->assertSessionHasErrors(['email' => 'Valid Email Address is required']);

        // CheckDB: validation failure must not touch the database.
        $this->assertDatabaseCount('admins', 0);
        $this->assertDatabaseCount('vendors', 0);
    }

    /**
     * TC-VLOG-04
     * Reject login when the password field is empty.
     */
    public function test_login_fails_password_required(): void
    {
        $response = $this->from('/admin/login')->post('/admin/login', [
            'email' => 'test@a.com',
            'password' => '',
        ]);

        $response->assertRedirect('/admin/login');
        $response->assertSessionHasErrors(['password' => 'Password is required!']);

        // CheckDB: validation failure must not create any rows.
        $this->assertDatabaseCount('admins', 0);
        $this->assertDatabaseCount('vendors', 0);
    }

    /**
     * TC-VLOG-05
     * Reject login when credentials do not match any stored account.
     */
    public function test_login_fails_wrong_credentials(): void
    {
        // Seed a valid account so the password mismatch can be verified against a real record.
        $this->createAdminLoginAccount([
            'email' => 'valid@example.com',
            'password' => 'correct-password',
            'status' => 1,
            'confirm' => 'Yes',
        ]);

        $response = $this->from('/admin/login')->post('/admin/login', [
            'email' => 'valid@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertRedirect('/admin/login');
        $response->assertSessionHas('error_message', 'Invalid Email or Password');
        $this->assertGuest('admin');

        // CheckDB: login failure must not alter the seeded account rows.
        $this->assertDatabaseHas('admins', [
            'email' => 'valid@example.com',
            'type' => 'admin',
            'status' => 1,
        ]);
        $this->assertDatabaseCount('vendors', 0);
    }

    /**
     * TC-VLOG-06
     * Block vendor accounts that have not confirmed their email address yet.
     */
    public function test_vendor_blocked_unconfirmed(): void
    {
        $account = $this->createVendorLoginAccount([
            'email' => 'vendor.unconfirmed@example.com',
            'password' => 'secret123',
            'confirm' => 'No',
            'status' => 1,
        ]);

        $response = $this->from('/admin/login')->post('/admin/login', [
            'email' => $account['admin']->email,
            'password' => 'secret123',
        ]);

        $response->assertRedirect('/admin/login');
        $response->assertSessionHas('error_message', 'Please confirm your email to activate your Vendor Account');
        $this->assertGuest('admin');

        // CheckDB: the account must remain unchanged after the blocked login attempt.
        $this->assertDatabaseHas('admins', [
            'email' => 'vendor.unconfirmed@example.com',
            'type' => 'vendor',
            'confirm' => 'No',
        ]);
        $this->assertDatabaseHas('vendors', [
            'email' => 'vendor.unconfirmed@example.com',
            'confirm' => 'No',
        ]);
    }

    /**
     * TC-VLOG-07
     * Allow a confirmed vendor to log in successfully.
     */
    public function test_vendor_login_success(): void
    {
        $account = $this->createVendorLoginAccount([
            'email' => 'vendor.valid@example.com',
            'password' => 'secret123',
            'confirm' => 'Yes',
            'status' => 1,
        ]);

        $response = $this->from('/admin/login')->post('/admin/login', [
            'email' => $account['admin']->email,
            'password' => 'secret123',
        ]);

        $response->assertRedirect('/admin/dashboard');
        $this->assertAuthenticated('admin');

        // CheckDB: the login request must not create duplicate rows or mutate the account.
        $this->assertDatabaseHas('admins', [
            'email' => 'vendor.valid@example.com',
            'type' => 'vendor',
            'confirm' => 'Yes',
            'status' => 1,
        ]);
        $this->assertDatabaseHas('vendors', [
            'email' => 'vendor.valid@example.com',
            'confirm' => 'Yes',
            'status' => 1,
        ]);
    }

    /**
     * TC-VLOG-08
     * Defect case: an inactive vendor should be blocked, but the current code
     * still allows the login because the status check only applies to non-vendor
     * accounts.
     */
    public function test_security_blocks_inactive_vendor(): void
    {
        $account = $this->createVendorLoginAccount([
            'email' => 'vendor.inactive@example.com',
            'password' => 'secret123',
            'confirm' => 'Yes',
            'status' => 0,
        ]);

        $response = $this->from('/admin/login')->post('/admin/login', [
            'email' => $account['admin']->email,
            'password' => 'secret123',
        ]);

        // Expected business rule: inactive vendors must be blocked.
        $response->assertRedirect('/admin/login');
        $response->assertSessionHas('error_message', 'Your vendor account is not active');
        $this->assertGuest('admin');

        // CheckDB: even on this defect case, login should never create or modify records.
        $this->assertDatabaseHas('admins', [
            'email' => 'vendor.inactive@example.com',
            'type' => 'vendor',
            'confirm' => 'Yes',
            'status' => 0,
        ]);
        $this->assertDatabaseHas('vendors', [
            'email' => 'vendor.inactive@example.com',
            'confirm' => 'Yes',
            'status' => 0,
        ]);
    }
}
