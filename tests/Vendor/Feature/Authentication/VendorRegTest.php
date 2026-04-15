<?php

namespace Tests\Vendor\Feature\Authentication;

use App\Models\Admin;
use App\Models\Vendor;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class VendorRegTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Run only the dedicated migration file and skip legacy broken migrations.
        $this->artisan('migrate:fresh', [
            '--path' => 'database/migrations/2026_04_14_212542_update_database_schema_v2.php',
            '--force' => true,
        ])->assertExitCode(0);
    }

    /**
     * A reusable valid payload for vendor registration.
     *
     * The values are intentionally explicit so each test can
     * override only the field under test.
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Vendor Test User',
            'email' => 'vendor.test@example.com',
            'mobile' => '0912345678',
            'password' => 'secret123',
            'accept' => 'on',
        ], $overrides);
    }

    /**
     * Seed one vendor row for duplicate-validation tests.
     */
    private function seedVendor(array $attributes = []): Vendor
    {
        $vendor = new Vendor();
        $vendor->name = $attributes['name'] ?? 'Existing Vendor';
        $vendor->mobile = $attributes['mobile'] ?? '0999999999';
        $vendor->email = $attributes['email'] ?? 'existing.vendor@example.com';
        $vendor->confirm = $attributes['confirm'] ?? 'No';
        $vendor->status = $attributes['status'] ?? 0;
        $vendor->save();

        return $vendor;
    }

    /**
     * Seed one admin row for duplicate-validation tests.
     */
    private function seedAdmin(array $attributes = []): Admin
    {
        $admin = new Admin();
        $admin->name = $attributes['name'] ?? 'Existing Admin';
        $admin->type = $attributes['type'] ?? 'admin';
        $admin->vendor_id = $attributes['vendor_id'] ?? 0;
        $admin->mobile = $attributes['mobile'] ?? '0988888888';
        $admin->email = $attributes['email'] ?? 'existing.admin@example.com';
        $admin->password = $attributes['password'] ?? bcrypt('secret123');
        $admin->confirm = $attributes['confirm'] ?? 'Yes';
        $admin->status = $attributes['status'] ?? 1;
        $admin->save();

        return $admin;
    }

    // ---------------------------------------------------------------------
    // vendorRegister() test cases
    // ---------------------------------------------------------------------

    // TC-VREG-01
    public function test_vendor_register_get_method_denied(): void
    {
        $vendorsBefore = Vendor::count();
        $adminsBefore = Admin::count();

        // Route is defined as POST-only, so GET must be denied.
        $response = $this->get('/vendor/register');

        $response->assertStatus(405);

        // CheckDB: no write should happen for denied GET requests.
        $this->assertSame($vendorsBefore, Vendor::count());
        $this->assertSame($adminsBefore, Admin::count());
    }

    // TC-VREG-02
    public function test_register_valid_data_creates_user_with_status_zero(): void
    {
        Mail::fake();

        $payload = $this->validPayload();
        $response = $this->post('/vendor/register', $payload);

        $response->assertRedirect();
        $response->assertSessionHas('success_message');

        // CheckDB: vendor row must be created with expected core fields.
        $this->assertDatabaseHas('vendors', [
            'name' => $payload['name'],
            'email' => $payload['email'],
            'mobile' => $payload['mobile'],
            'status' => 0,
        ]);

        $createdVendor = Vendor::where('email', $payload['email'])->firstOrFail();

        // CheckDB: admin row must be created and linked to vendor_id.
        $this->assertDatabaseHas('admins', [
            'name' => $payload['name'],
            'email' => $payload['email'],
            'mobile' => $payload['mobile'],
            'type' => 'vendor',
            'vendor_id' => $createdVendor->id,
            'status' => 0,
        ]);
    }

    // TC-VREG-03
    public function test_register_fails_no_name(): void
    {
        $payload = $this->validPayload(['name' => null, 'email' => 'tc03@example.com']);

        $response = $this->from('/vendor/login-register')->post('/vendor/register', $payload);

        $response->assertRedirect('/vendor/login-register');
        $response->assertSessionHasErrors(['name']);

        // CheckDB: nothing must be created when validation fails.
        $this->assertDatabaseMissing('vendors', ['email' => 'tc03@example.com']);
        $this->assertDatabaseMissing('admins', ['email' => 'tc03@example.com']);
    }

    // TC-VREG-04
    public function test_register_fails_invalid_email(): void
    {
        $payload = $this->validPayload([
            'email' => 'user@invalid',
            'mobile' => '0912345679',
        ]);

        $response = $this->from('/vendor/login-register')->post('/vendor/register', $payload);

        $response->assertRedirect('/vendor/login-register');
        $response->assertSessionHasErrors(['email']);

        // CheckDB: invalid email must not persist into either table.
        $this->assertDatabaseMissing('vendors', ['mobile' => '0912345679']);
        $this->assertDatabaseMissing('admins', ['mobile' => '0912345679']);
    }

    // TC-VREG-05
    public function test_register_fails_email_exists_vendor(): void
    {
        $this->seedVendor(['email' => 'dup.vendor@example.com', 'mobile' => '0911111111']);

        $payload = $this->validPayload([
            'email' => 'dup.vendor@example.com',
            'mobile' => '0912345680',
        ]);

        $response = $this->from('/vendor/login-register')->post('/vendor/register', $payload);

        $response->assertRedirect('/vendor/login-register');
        // Exact message requested by current codebase typo: "Email alreay exists".
        $response->assertSessionHasErrors(['email' => 'Email alreay exists']);

        // CheckDB: new pair must not be inserted.
        $this->assertDatabaseMissing('vendors', ['mobile' => '0912345680']);
        $this->assertDatabaseMissing('admins', ['mobile' => '0912345680']);
    }

    // TC-VREG-06
    public function test_register_fails_email_exists_admin(): void
    {
        $this->seedAdmin(['email' => 'dup.admin@example.com', 'mobile' => '0922222222']);

        $payload = $this->validPayload([
            'email' => 'dup.admin@example.com',
            'mobile' => '0912345681',
        ]);

        $response = $this->from('/vendor/login-register')->post('/vendor/register', $payload);

        $response->assertRedirect('/vendor/login-register');
        $response->assertSessionHasErrors(['email' => 'Email alreay exists']);

        // CheckDB: no new rows with this mobile.
        $this->assertDatabaseMissing('vendors', ['mobile' => '0912345681']);
        $this->assertDatabaseMissing('admins', ['mobile' => '0912345681']);
    }

    // TC-VREG-07
    public function test_register_fails_mobile_not_numeric(): void
    {
        $payload = $this->validPayload([
            'email' => 'tc07@example.com',
            'mobile' => '090-abc',
        ]);

        $response = $this->from('/vendor/login-register')->post('/vendor/register', $payload);

        $response->assertRedirect('/vendor/login-register');
        $response->assertSessionHasErrors(['mobile']);

        // CheckDB: invalid mobile must not create data.
        $this->assertDatabaseMissing('vendors', ['email' => 'tc07@example.com']);
        $this->assertDatabaseMissing('admins', ['email' => 'tc07@example.com']);
    }

    // TC-VREG-08
    public function test_register_fails_mobile_too_short(): void
    {
        $payload = $this->validPayload([
            'email' => 'tc08@example.com',
            'mobile' => '09123',
        ]);

        $response = $this->from('/vendor/login-register')->post('/vendor/register', $payload);

        $response->assertRedirect('/vendor/login-register');
        $response->assertSessionHasErrors(['mobile']);

        // CheckDB: short mobile should not persist.
        $this->assertDatabaseMissing('vendors', ['email' => 'tc08@example.com']);
        $this->assertDatabaseMissing('admins', ['email' => 'tc08@example.com']);
    }

    // TC-VREG-09
    public function test_register_fails_mobile_exists_vendor(): void
    {
        $this->seedVendor(['email' => 'seed09@example.com', 'mobile' => '0933333333']);

        $payload = $this->validPayload([
            'email' => 'tc09@example.com',
            'mobile' => '0933333333',
        ]);

        $response = $this->from('/vendor/login-register')->post('/vendor/register', $payload);

        $response->assertRedirect('/vendor/login-register');
        $response->assertSessionHasErrors(['mobile' => 'Mobile alreay exists']);

        // CheckDB: no second row should be created with test email.
        $this->assertDatabaseMissing('vendors', ['email' => 'tc09@example.com']);
        $this->assertDatabaseMissing('admins', ['email' => 'tc09@example.com']);
    }

    // TC-VREG-10
    public function test_register_fails_mobile_exists_admin(): void
    {
        $this->seedAdmin(['email' => 'seed10@example.com', 'mobile' => '0944444444']);

        $payload = $this->validPayload([
            'email' => 'tc10@example.com',
            'mobile' => '0944444444',
        ]);

        $response = $this->from('/vendor/login-register')->post('/vendor/register', $payload);

        $response->assertRedirect('/vendor/login-register');
        $response->assertSessionHasErrors(['mobile' => 'Mobile alreay exists']);

        // CheckDB: no new rows should be created.
        $this->assertDatabaseMissing('vendors', ['email' => 'tc10@example.com']);
        $this->assertDatabaseMissing('admins', ['email' => 'tc10@example.com']);
    }

    // TC-VREG-11
    public function test_register_fails_no_accept(): void
    {
        $payload = $this->validPayload([
            'email' => 'tc11@example.com',
        ]);
        unset($payload['accept']);

        $response = $this->from('/vendor/login-register')->post('/vendor/register', $payload);

        $response->assertRedirect('/vendor/login-register');
        $response->assertSessionHasErrors(['accept' => 'Please accept Terms & Conditions']);

        // CheckDB: record must not exist when terms are not accepted.
        $this->assertDatabaseMissing('vendors', ['email' => 'tc11@example.com']);
        $this->assertDatabaseMissing('admins', ['email' => 'tc11@example.com']);
    }

    // TC-VREG-12
    public function test_register_password_missing_key(): void
    {
        Mail::fake();

        $payload = $this->validPayload([
            'email' => 'tc12@example.com',
            'mobile' => '0955555555',
        ]);
        unset($payload['password']);

        // Defect case: controller accesses $data['password'] without validation rule.
        $response = $this->post('/vendor/register', $payload);

        $response->assertStatus(500);

        // CheckDB: if server errors, no rows should be persisted.
        $this->assertDatabaseMissing('vendors', ['email' => 'tc12@example.com']);
        $this->assertDatabaseMissing('admins', ['email' => 'tc12@example.com']);
    }

    // TC-VREG-13
    public function test_register_fails_password_empty_string(): void
    {
        $payload = $this->validPayload([
            'email' => 'tc13@example.com',
            'mobile' => '0966666666',
            'password' => '',
        ]);

        $response = $this->from('/vendor/login-register')->post('/vendor/register', $payload);

        $response->assertRedirect('/vendor/login-register');
        $response->assertSessionHasErrors(['password']);

        // CheckDB: empty password must be rejected, no rows created.
        $this->assertDatabaseMissing('vendors', ['email' => 'tc13@example.com']);
        $this->assertDatabaseMissing('admins', ['email' => 'tc13@example.com']);
    }
}
