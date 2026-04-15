<?php

namespace Tests\Vendor\Feature\Dash_Profile;

use App\Models\Admin;
use App\Models\Vendor;
use App\Models\VendorsBankDetail;
use App\Models\VendorsBusinessDetail;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class VendorProfTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Rollback strategy:
        // Rebuild DB strictly from dedicated migration baseline.
        $this->artisan('migrate:fresh', [
            '--path' => 'database/migrations/2026_04_14_212542_update_database_schema_v2.php',
            '--force' => true,
        ])->assertExitCode(0);

        $this->ensureVendorSupportTablesExist();
        $this->ensureImageDirectoriesExist();
    }

    /**
     * Create tables required by updateVendorDetails() that are not in migration baseline.
     */
    private function ensureVendorSupportTablesExist(): void
    {
        if (!Schema::hasTable('countries')) {
            Schema::create('countries', function (Blueprint $table): void {
                $table->id();
                $table->string('country_name')->nullable();
                $table->tinyInteger('status')->default(1);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('vendors_business_details')) {
            Schema::create('vendors_business_details', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('vendor_id');
                $table->string('shop_name')->nullable();
                $table->string('shop_mobile')->nullable();
                $table->string('shop_website')->nullable();
                $table->string('shop_address')->nullable();
                $table->string('shop_city')->nullable();
                $table->string('shop_state')->nullable();
                $table->string('shop_country')->nullable();
                $table->string('shop_pincode')->nullable();
                $table->string('business_license_number')->nullable();
                $table->string('gst_number')->nullable();
                $table->string('pan_number')->nullable();
                $table->string('address_proof')->nullable();
                $table->string('address_proof_image')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('vendors_bank_details')) {
            Schema::create('vendors_bank_details', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('vendor_id');
                $table->string('account_holder_name')->nullable();
                $table->string('bank_name')->nullable();
                $table->string('account_number')->nullable();
                $table->string('bank_ifsc_code')->nullable();
                $table->timestamps();
            });
        }
    }

    private function ensureImageDirectoriesExist(): void
    {
        if (!is_dir('admin/images/photos')) {
            mkdir('admin/images/photos', 0777, true);
        }

        if (!is_dir('admin/images/proofs')) {
            mkdir('admin/images/proofs', 0777, true);
        }
    }

    /**
     * Seed active/inactive countries to validate filtering behavior.
     */
    private function seedCountries(): void
    {
        \DB::table('countries')->insert([
            [
                'country_name' => 'Vietnam',
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'country_name' => 'Japan',
                'status' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'country_name' => 'France',
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    /**
     * Create vendor + vendor admin and authenticate through admin guard.
     */
    private function actingAsVendorAdmin(array $vendorOverrides = [], array $adminOverrides = []): array
    {
        $vendor = new Vendor();
        $vendor->name = $vendorOverrides['name'] ?? 'Vendor Default';
        $vendor->address = $vendorOverrides['address'] ?? 'Default Address';
        $vendor->city = $vendorOverrides['city'] ?? 'Hanoi';
        $vendor->state = $vendorOverrides['state'] ?? 'HN';
        $vendor->country = $vendorOverrides['country'] ?? 'Vietnam';
        $vendor->pincode = $vendorOverrides['pincode'] ?? '100000';
        $vendor->mobile = $vendorOverrides['mobile'] ?? '0911000111';
        $vendor->email = $vendorOverrides['email'] ?? 'vendor.profile@example.com';
        $vendor->confirm = $vendorOverrides['confirm'] ?? 'Yes';
        $vendor->status = $vendorOverrides['status'] ?? 1;
        $vendor->save();

        $admin = new Admin();
        $admin->name = $adminOverrides['name'] ?? 'Vendor Admin';
        $admin->type = $adminOverrides['type'] ?? 'vendor';
        $admin->vendor_id = $adminOverrides['vendor_id'] ?? $vendor->id;
        $admin->mobile = $adminOverrides['mobile'] ?? '0911222333';
        $admin->email = $adminOverrides['email'] ?? 'vendor.admin@example.com';
        $admin->password = Hash::make($adminOverrides['password'] ?? 'secret123');
        $admin->image = $adminOverrides['image'] ?? null;
        $admin->confirm = $adminOverrides['confirm'] ?? 'Yes';
        $admin->status = $adminOverrides['status'] ?? 1;
        $admin->save();

        $this->actingAs($admin, 'admin');

        return [$vendor, $admin];
    }

    private function validPersonalPayload(array $overrides = []): array
    {
        return array_merge([
            'vendor_name' => 'Vendor New Name',
            'vendor_address' => 'Updated Address',
            'vendor_city' => 'Da Nang',
            'vendor_state' => 'DN',
            'vendor_country' => 'Vietnam',
            'vendor_pincode' => '550000',
            'vendor_mobile' => '0988111222',
            'current_vendor_image' => '',
        ], $overrides);
    }

    private function validBusinessPayload(array $overrides = []): array
    {
        return array_merge([
            'shop_name' => 'Sunrise Shop',
            'shop_mobile' => '0912333444',
            'shop_website' => 'https://sunrise.example.com',
            'shop_address' => '123 Shop Street',
            'shop_city' => 'Hanoi',
            'shop_state' => 'HN',
            'shop_country' => 'Vietnam',
            'shop_pincode' => '100000',
            'business_license_number' => 'BLN-12345',
            'gst_number' => 'GST-67890',
            'pan_number' => 'PAN-98765',
            'address_proof' => 'Passport',
            'current_address_proof' => '',
        ], $overrides);
    }

    private function validBankPayload(array $overrides = []): array
    {
        return array_merge([
            'account_holder_name' => 'Vendor Holder',
            'bank_name' => 'ACB',
            'account_number' => '1234567890',
            'bank_ifsc_code' => 'IFSC0001',
        ], $overrides);
    }

    // ---------------------------------------------------------------------
    // AdminController@updateVendorDetails($slug) test cases
    // ---------------------------------------------------------------------

    /**
     * TC-UVEN-01
     * Guest users must be redirected for both GET and POST.
     */
    public function test_vendor_details_unauth(): void
    {
        $getResponse = $this->get('/admin/update-vendor-details/personal');
        $postResponse = $this->post('/admin/update-vendor-details/personal', []);

        $getResponse->assertRedirect('/admin/login');
        $postResponse->assertRedirect('/admin/login');
    }

    /**
     * TC-UVEN-02
     * Personal slug validation: vendor_name required.
     */
    public function test_vendor_details_personal_name_required(): void
    {
        $this->seedCountries();
        $this->actingAsVendorAdmin();

        $response = $this->from('/admin/update-vendor-details/personal')->post(
            '/admin/update-vendor-details/personal',
            $this->validPersonalPayload(['vendor_name' => ''])
        );

        $response->assertRedirect('/admin/update-vendor-details/personal');
        $response->assertSessionHasErrors(['vendor_name']);
    }

    /**
     * TC-UVEN-03
     * Personal slug validation: vendor_city regex rejects invalid symbols.
     */
    public function test_vendor_details_personal_city_regex(): void
    {
        $this->seedCountries();
        $this->actingAsVendorAdmin();

        $response = $this->from('/admin/update-vendor-details/personal')->post(
            '/admin/update-vendor-details/personal',
            $this->validPersonalPayload(['vendor_city' => '123@'])
        );

        $response->assertRedirect('/admin/update-vendor-details/personal');
        $response->assertSessionHasErrors(['vendor_city']);
    }

    /**
     * TC-UVEN-04
     * Load each valid slug and confirm session page markers are set correctly.
     */
    public function test_vendor_details_get_view_personal_business_bank(): void
    {
        $this->seedCountries();
        [$vendor] = $this->actingAsVendorAdmin();

        \DB::table('vendors_business_details')->insert([
            'vendor_id' => $vendor->id,
            'shop_name' => 'Base Shop',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \DB::table('vendors_bank_details')->insert([
            'vendor_id' => $vendor->id,
            'account_holder_name' => 'Base Holder',
            'bank_name' => 'Base Bank',
            'account_number' => '11111',
            'bank_ifsc_code' => 'BASE001',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $personal = $this->get('/admin/update-vendor-details/personal');
        $personal->assertStatus(200);
        $personal->assertSessionHas('page', 'update_personal_details');

        $business = $this->get('/admin/update-vendor-details/business');
        $business->assertStatus(200);
        $business->assertSessionHas('page', 'update_business_details');

        $bank = $this->get('/admin/update-vendor-details/bank');
        $bank->assertStatus(200);
        $bank->assertSessionHas('page', 'update_bank_details');
    }

    /**
     * TC-UVEN-05
     * Business slug validation: shop_name required.
     */
    public function test_vendor_details_business_shop_name_required(): void
    {
        $this->seedCountries();
        $this->actingAsVendorAdmin();

        $response = $this->from('/admin/update-vendor-details/business')->post(
            '/admin/update-vendor-details/business',
            $this->validBusinessPayload(['shop_name' => ''])
        );

        $response->assertRedirect('/admin/update-vendor-details/business');
        $response->assertSessionHasErrors(['shop_name']);
    }

    /**
     * TC-UVEN-06
     * Business slug validation: shop_mobile must be numeric.
     */
    public function test_vendor_details_business_shop_mobile_numeric(): void
    {
        $this->seedCountries();
        $this->actingAsVendorAdmin();

        $response = $this->from('/admin/update-vendor-details/business')->post(
            '/admin/update-vendor-details/business',
            $this->validBusinessPayload(['shop_mobile' => 'abc'])
        );

        $response->assertRedirect('/admin/update-vendor-details/business');
        $response->assertSessionHasErrors(['shop_mobile']);
    }

    /**
     * TC-UVEN-07
     * Business slug validation: address_proof required.
     */
    public function test_vendor_details_business_address_proof_required(): void
    {
        $this->seedCountries();
        $this->actingAsVendorAdmin();

        $response = $this->from('/admin/update-vendor-details/business')->post(
            '/admin/update-vendor-details/business',
            $this->validBusinessPayload(['address_proof' => ''])
        );

        $response->assertRedirect('/admin/update-vendor-details/business');
        $response->assertSessionHasErrors(['address_proof']);
    }

    /**
     * TC-UVEN-08
     * Bank slug validation: account_holder_name required.
     */
    public function test_vendor_details_bank_holder_required(): void
    {
        $this->seedCountries();
        $this->actingAsVendorAdmin();

        $response = $this->from('/admin/update-vendor-details/bank')->post(
            '/admin/update-vendor-details/bank',
            $this->validBankPayload(['account_holder_name' => ''])
        );

        $response->assertRedirect('/admin/update-vendor-details/bank');
        $response->assertSessionHasErrors(['account_holder_name']);
    }

    /**
     * TC-UVEN-09
     * Bank slug validation: account_number must be numeric.
     */
    public function test_vendor_details_bank_account_number_numeric(): void
    {
        $this->seedCountries();
        $this->actingAsVendorAdmin();

        $response = $this->from('/admin/update-vendor-details/bank')->post(
            '/admin/update-vendor-details/bank',
            $this->validBankPayload(['account_number' => 'abc'])
        );

        $response->assertRedirect('/admin/update-vendor-details/bank');
        $response->assertSessionHasErrors(['account_number']);
    }

    /**
     * TC-UVEN-10
     * Personal update should update both admins and vendors tables.
     */
    public function test_vendor_details_personal_updates_admin_and_vendor(): void
    {
        $this->seedCountries();
        [$vendor, $admin] = $this->actingAsVendorAdmin();

        $response = $this->post('/admin/update-vendor-details/personal', $this->validPersonalPayload());

        $response->assertRedirect();
        $response->assertSessionHas('success_message', 'Vendor details updated successfully!');

        // CheckDB: admin mirror fields are updated.
        $this->assertDatabaseHas('admins', [
            'id' => $admin->id,
            'name' => 'Vendor New Name',
            'mobile' => '0988111222',
        ]);

        // CheckDB: vendor personal details are updated.
        $this->assertDatabaseHas('vendors', [
            'id' => $vendor->id,
            'name' => 'Vendor New Name',
            'city' => 'Da Nang',
            'address' => 'Updated Address',
            'mobile' => '0988111222',
        ]);
    }

    /**
     * TC-UVEN-11
     * Personal update keeps current_vendor_image when no new upload provided.
     */
    public function test_vendor_details_personal_keep_image(): void
    {
        $this->seedCountries();
        [, $admin] = $this->actingAsVendorAdmin([], ['image' => 'vendor_old.jpg']);

        $response = $this->post('/admin/update-vendor-details/personal', $this->validPersonalPayload([
            'current_vendor_image' => 'vendor_old.jpg',
        ]));

        $response->assertRedirect();
        $response->assertSessionHas('success_message', 'Vendor details updated successfully!');

        // CheckDB: admin image stays unchanged from current_vendor_image branch.
        $this->assertDatabaseHas('admins', [
            'id' => $admin->id,
            'image' => 'vendor_old.jpg',
        ]);
    }

    /**
     * TC-UVEN-12
     * Personal update stores newly uploaded vendor image.
     */
    public function test_vendor_details_personal_new_image(): void
    {
        $this->seedCountries();
        [, $admin] = $this->actingAsVendorAdmin([], ['image' => 'before.jpg']);

        $newImage = UploadedFile::fake()->image('vendor_new.jpg', 100, 100);

        $response = $this->post('/admin/update-vendor-details/personal', $this->validPersonalPayload([
            'current_vendor_image' => 'before.jpg',
            'vendor_image' => $newImage,
        ]));

        $response->assertRedirect();
        $response->assertSessionHas('success_message', 'Vendor details updated successfully!');

        $updatedAdmin = Admin::findOrFail($admin->id);

        // CheckDB: image must be replaced with generated jpg filename.
        $this->assertNotSame('before.jpg', $updatedAdmin->image);
        $this->assertStringEndsWith('.jpg', $updatedAdmin->image);
    }

    /**
     * TC-UVEN-13
     * Business branch inserts when vendor has no existing business details.
     */
    public function test_vendor_details_business_insert(): void
    {
        $this->seedCountries();
        [$vendor] = $this->actingAsVendorAdmin();

        $response = $this->post('/admin/update-vendor-details/business', $this->validBusinessPayload());

        $response->assertRedirect();
        $response->assertSessionHas('success_message', 'Vendor details updated successfully!');

        // CheckDB: record inserted with expected fields.
        $this->assertDatabaseHas('vendors_business_details', [
            'vendor_id' => $vendor->id,
            'shop_name' => 'Sunrise Shop',
            'address_proof' => 'Passport',
        ]);
    }

    /**
     * TC-UVEN-14
     * Business branch updates existing record instead of inserting a second row.
     */
    public function test_vendor_details_business_update_existing(): void
    {
        $this->seedCountries();
        [$vendor] = $this->actingAsVendorAdmin();

        \DB::table('vendors_business_details')->insert([
            'vendor_id' => $vendor->id,
            'shop_name' => 'Old Shop',
            'shop_mobile' => '0900000000',
            'shop_city' => 'Old City',
            'address_proof' => 'ID Card',
            'address_proof_image' => 'old_proof.png',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->post('/admin/update-vendor-details/business', $this->validBusinessPayload([
            'shop_name' => 'Updated Shop Name',
            'current_address_proof' => 'old_proof.png',
        ]));

        $response->assertRedirect();
        $response->assertSessionHas('success_message', 'Vendor details updated successfully!');

        // CheckDB: updated values exist and only one record remains for this vendor.
        $this->assertDatabaseHas('vendors_business_details', [
            'vendor_id' => $vendor->id,
            'shop_name' => 'Updated Shop Name',
        ]);
        $this->assertSame(1, VendorsBusinessDetail::where('vendor_id', $vendor->id)->count());
    }

    /**
     * TC-UVEN-15
     * Business branch keeps current proof image when no new upload is provided.
     */
    public function test_vendor_details_business_keep_proof_image(): void
    {
        $this->seedCountries();
        [$vendor] = $this->actingAsVendorAdmin();

        \DB::table('vendors_business_details')->insert([
            'vendor_id' => $vendor->id,
            'shop_name' => 'Old Shop',
            'address_proof' => 'Passport',
            'address_proof_image' => 'proof_old.png',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->post('/admin/update-vendor-details/business', $this->validBusinessPayload([
            'current_address_proof' => 'proof_old.png',
        ]));

        $response->assertRedirect();
        $response->assertSessionHas('success_message', 'Vendor details updated successfully!');

        // CheckDB: existing proof image remains preserved.
        $this->assertDatabaseHas('vendors_business_details', [
            'vendor_id' => $vendor->id,
            'address_proof_image' => 'proof_old.png',
        ]);
    }

    /**
     * TC-UVEN-16
     * Bank branch should insert first, then update existing row on next submission.
     */
    public function test_vendor_details_bank_insert_then_update(): void
    {
        $this->seedCountries();
        [$vendor] = $this->actingAsVendorAdmin();

        $insertResponse = $this->post('/admin/update-vendor-details/bank', $this->validBankPayload());
        $insertResponse->assertRedirect();
        $insertResponse->assertSessionHas('success_message', 'Vendor details updated successfully!');

        $this->assertDatabaseHas('vendors_bank_details', [
            'vendor_id' => $vendor->id,
            'account_holder_name' => 'Vendor Holder',
            'account_number' => '1234567890',
        ]);

        $updateResponse = $this->post('/admin/update-vendor-details/bank', $this->validBankPayload([
            'account_holder_name' => 'Holder Updated',
            'account_number' => '999999999',
        ]));
        $updateResponse->assertRedirect();
        $updateResponse->assertSessionHas('success_message', 'Vendor details updated successfully!');

        // CheckDB: row is updated, not duplicated.
        $this->assertDatabaseHas('vendors_bank_details', [
            'vendor_id' => $vendor->id,
            'account_holder_name' => 'Holder Updated',
            'account_number' => '999999999',
        ]);
        $this->assertSame(1, VendorsBankDetail::where('vendor_id', $vendor->id)->count());
    }

    /**
     * TC-UVEN-17
     * Countries passed to view must include only active (status=1) entries.
     */
    public function test_vendor_details_only_active_countries(): void
    {
        $this->seedCountries();
        $this->actingAsVendorAdmin();

        $response = $this->get('/admin/update-vendor-details/personal');

        $response->assertStatus(200);
        $countries = $response->viewData('countries');

        $this->assertNotEmpty($countries);
        foreach ($countries as $country) {
            $this->assertSame(1, (int) ($country['status'] ?? 0));
        }
    }

    /**
     * TC-UVEN-18
     * Handle unknown slug gracefully without crashing.
     */
    public function test_vendor_invalid_slug(): void
    {
        $this->seedCountries();
        $this->actingAsVendorAdmin();

        $response = $this->get('/admin/update-vendor-details/not-a-real-slug');

        // Expected behavior: safe redirect or 404 page, but never 500 crash.
        $this->assertTrue(in_array($response->getStatusCode(), [302, 404], true));
    }
}
