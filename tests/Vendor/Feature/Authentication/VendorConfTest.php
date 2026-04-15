<?php

namespace Tests\Vendor\Feature\Authentication;

use App\Models\Admin;
use App\Models\Vendor;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class VendorConfTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Rollback strategy for this test suite:
        // run a fresh schema for every test using only the dedicated migration file.
        // This guarantees isolation and returns DB state to baseline between test cases.
        $this->artisan('migrate:fresh', [
            '--path' => 'database/migrations/2026_04_14_212542_update_database_schema_v2.php',
            '--force' => true,
        ])->assertExitCode(0);
    }

    /**
     * Seed both vendor + admin rows required by confirmVendor() logic.
     *
     * confirmVendor() reads vendors table and updates confirm field in BOTH
     * vendors and admins tables for the same email.
     */
    private function seedVendorConfirmationAccount(array $overrides = []): array
    {
        $email = $overrides['email'] ?? 'new@test.com';
        $confirm = $overrides['confirm'] ?? 'No';

        $vendor = new Vendor();
        $vendor->name = $overrides['vendor_name'] ?? 'Vendor Confirm User';
        $vendor->mobile = $overrides['mobile'] ?? '0912345678';
        $vendor->email = $email;
        $vendor->confirm = $confirm;
        $vendor->status = $overrides['vendor_status'] ?? 1;
        $vendor->save();

        $admin = new Admin();
        $admin->name = $overrides['admin_name'] ?? 'Vendor Confirm Admin Row';
        $admin->type = 'vendor';
        $admin->vendor_id = $vendor->id;
        $admin->mobile = $overrides['mobile'] ?? '0912345678';
        $admin->email = $email;
        $admin->password = Hash::make($overrides['password'] ?? 'secret123');
        $admin->confirm = $confirm;
        $admin->status = $overrides['admin_status'] ?? 1;
        $admin->save();

        return [
            'email' => $email,
            'vendor' => $vendor,
            'admin' => $admin,
            'encoded_email' => base64_encode($email),
        ];
    }

    // ---------------------------------------------------------------------
    // VendorController@confirmVendor() test cases
    // ---------------------------------------------------------------------

    /**
     * TC-VCONF-01
     * Block non-existent email and return 404.
     */
    public function test_confirm_vendor_404_not_found(): void
    {
        $encodedEmail = base64_encode('wrong@test');

        $response = $this->get('/vendor/confirm/' . $encodedEmail);

        $response->assertStatus(404);

        // CheckDB: no matching account should be created/modified by this request.
        $this->assertDatabaseMissing('vendors', ['email' => 'wrong@test']);
        $this->assertDatabaseMissing('admins', ['email' => 'wrong@test']);
    }

    /**
     * TC-VCONF-02
     * Already confirmed vendor should be redirected with error message
     * and must not be updated again.
     */
    public function test_confirm_vendor_already_confirmed(): void
    {
        $seeded = $this->seedVendorConfirmationAccount([
            'email' => 'active@test',
            'confirm' => 'Yes',
        ]);

        $response = $this->get('/vendor/confirm/' . $seeded['encoded_email']);

        $response->assertRedirect('vendor/login-register');
        $response->assertSessionHas('error_message', 'Your Vendor Account is already confirmed. You can login');

        // CheckDB: confirm flags must remain Yes in both tables.
        $this->assertDatabaseHas('vendors', [
            'email' => 'active@test',
            'confirm' => 'Yes',
        ]);
        $this->assertDatabaseHas('admins', [
            'email' => 'active@test',
            'confirm' => 'Yes',
        ]);
    }

    /**
     * TC-VCONF-03
     * First-time confirmation should activate both rows and return success.
     */
    public function test_confirm_vendor_success(): void
    {
        Mail::spy();

        $seeded = $this->seedVendorConfirmationAccount([
            'email' => 'new@test',
            'confirm' => 'No',
        ]);

        $response = $this->get('/vendor/confirm/' . $seeded['encoded_email']);

        $response->assertRedirect('vendor/login-register');
        $response->assertSessionHas('success_message', 'Your Vendor Email account is confirmed. You can login and add your personal, business and bank details to activate your Vendor Account to add products');

        // CheckDB: confirm must be updated to Yes in both admins and vendors.
        $this->assertDatabaseHas('vendors', [
            'email' => 'new@test',
            'confirm' => 'Yes',
        ]);
        $this->assertDatabaseHas('admins', [
            'email' => 'new@test',
            'confirm' => 'Yes',
        ]);

        // Verify mail send() was invoked for the vendor confirmed template.
        Mail::shouldHaveReceived('send')->once()->withArgs(function ($view, $data, $callback) use ($seeded) {
            return $view === 'emails.vendor_confirmed'
                && isset($data['email'])
                && $data['email'] === $seeded['email']
                && $callback instanceof \Closure;
        });
    }

    /**
     * TC-VCONF-04
     * Malformed base64 input should decode to invalid/not-found email path,
     * and end with 404.
     */
    public function test_confirm_vendor_malformed_base64(): void
    {
        $response = $this->get('/vendor/confirm/!!!broken_code!!!');

        $response->assertStatus(404);

        // CheckDB: malformed code must not touch vendor/admin records.
        $this->assertDatabaseCount('vendors', 0);
        $this->assertDatabaseCount('admins', 0);
    }
}
