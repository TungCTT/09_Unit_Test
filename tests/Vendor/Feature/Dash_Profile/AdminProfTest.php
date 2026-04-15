<?php

namespace Tests\Vendor\Feature\Dash_Profile;

use App\Models\Admin;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

class AdminProfTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Rollback strategy:
        // Always rebuild from the single dedicated migration requested by the user.
        $this->artisan('migrate:fresh', [
            '--path' => 'database/migrations/2026_04_14_212542_update_database_schema_v2.php',
            '--force' => true,
        ])->assertExitCode(0);

        $this->ensureImageDirectoriesExist();
    }

    /**
     * Ensure upload destinations used by updateAdminDetails() exist in test env.
     */
    private function ensureImageDirectoriesExist(): void
    {
        if (!is_dir('admin/images/photos')) {
            mkdir('admin/images/photos', 0777, true);
        }
    }

    /**
     * Create an admin account and authenticate via admin guard.
     */
    private function actingAsAdmin(array $overrides = []): Admin
    {
        $admin = new Admin();
        $admin->name = $overrides['name'] ?? 'Admin Profile User';
        $admin->type = $overrides['type'] ?? 'admin';
        $admin->vendor_id = $overrides['vendor_id'] ?? 0;
        $admin->mobile = $overrides['mobile'] ?? '0900123456';
        $admin->email = $overrides['email'] ?? 'admin.profile@example.com';
        $admin->password = Hash::make($overrides['password'] ?? 'secret123');
        $admin->image = $overrides['image'] ?? null;
        $admin->confirm = $overrides['confirm'] ?? 'Yes';
        $admin->status = $overrides['status'] ?? 1;
        $admin->save();

        $this->actingAs($admin, 'admin');

        return $admin;
    }

    // ---------------------------------------------------------------------
    // AdminController@updateAdminDetails() test cases
    // ---------------------------------------------------------------------

    /**
     * TC-UADM-01
     * Block unauthenticated access for both GET and POST.
     */
    public function test_admin_details_unauth(): void
    {
        $getResponse = $this->get('/admin/update-admin-details');
        $postResponse = $this->post('/admin/update-admin-details', []);

        $getResponse->assertRedirect('/admin/login');
        $postResponse->assertRedirect('/admin/login');

        // CheckDB: guest access must not create any admin record.
        $this->assertDatabaseCount('admins', 0);
    }

    /**
     * TC-UADM-02
     * Render update form and set session page flag.
     */
    public function test_admin_details_get_view(): void
    {
        $this->actingAsAdmin();

        $response = $this->get('/admin/update-admin-details');

        $response->assertStatus(200);
        $response->assertViewIs('admin.settings.update_admin_details');
        $response->assertSessionHas('page', 'update_admin_details');
    }

    /**
     * TC-UADM-03
     * Validate admin_name required.
     */
    public function test_admin_details_name_required(): void
    {
        $this->actingAsAdmin();

        $response = $this->from('/admin/update-admin-details')->post('/admin/update-admin-details', [
            'admin_name' => '',
            'admin_mobile' => '0911222333',
        ]);

        $response->assertRedirect('/admin/update-admin-details');
        $response->assertSessionHasErrors(['admin_name']);

        // CheckDB: data must remain unchanged on validation failure.
        $this->assertDatabaseHas('admins', [
            'email' => 'admin.profile@example.com',
            'name' => 'Admin Profile User',
        ]);
    }

    /**
     * TC-UADM-04
     * Validate admin_name regex.
     */
    public function test_admin_details_name_regex(): void
    {
        $this->actingAsAdmin();

        $response = $this->from('/admin/update-admin-details')->post('/admin/update-admin-details', [
            'admin_name' => '123@',
            'admin_mobile' => '0911222333',
        ]);

        $response->assertRedirect('/admin/update-admin-details');
        $response->assertSessionHasErrors(['admin_name']);
    }

    /**
     * TC-UADM-05
     * Validate admin_mobile required.
     */
    public function test_admin_details_mobile_required(): void
    {
        $this->actingAsAdmin();

        $response = $this->from('/admin/update-admin-details')->post('/admin/update-admin-details', [
            'admin_name' => 'Valid Name',
            'admin_mobile' => '',
        ]);

        $response->assertRedirect('/admin/update-admin-details');
        $response->assertSessionHasErrors(['admin_mobile']);
    }

    /**
     * TC-UADM-06
     * Validate admin_mobile numeric.
     */
    public function test_admin_details_mobile_numeric(): void
    {
        $this->actingAsAdmin();

        $response = $this->from('/admin/update-admin-details')->post('/admin/update-admin-details', [
            'admin_name' => 'Valid Name',
            'admin_mobile' => 'abc',
        ]);

        $response->assertRedirect('/admin/update-admin-details');
        $response->assertSessionHasErrors(['admin_mobile']);
    }

    /**
     * TC-UADM-07
     * Update valid payload with no uploaded image and no current image.
     */
    public function test_admin_details_no_image(): void
    {
        $this->actingAsAdmin(['image' => null]);

        $response = $this->post('/admin/update-admin-details', [
            'admin_name' => 'Updated Admin Name',
            'admin_mobile' => '0999000111',
            'current_admin_image' => '',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success_message', 'Admin details updated successfully!');

        // CheckDB: name/mobile updated and image forced to empty string branch.
        $this->assertDatabaseHas('admins', [
            'email' => 'admin.profile@example.com',
            'name' => 'Updated Admin Name',
            'mobile' => '0999000111',
            'image' => '',
        ]);
    }

    /**
     * TC-UADM-08
     * Keep existing image when no new file is uploaded.
     */
    public function test_admin_details_keep_image(): void
    {
        $this->actingAsAdmin(['image' => 'existing_admin.jpg']);

        $response = $this->post('/admin/update-admin-details', [
            'admin_name' => 'Admin Keep Image',
            'admin_mobile' => '0911999888',
            'current_admin_image' => 'existing_admin.jpg',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success_message', 'Admin details updated successfully!');

        // CheckDB: image remains unchanged from current_admin_image branch.
        $this->assertDatabaseHas('admins', [
            'email' => 'admin.profile@example.com',
            'image' => 'existing_admin.jpg',
            'name' => 'Admin Keep Image',
        ]);
    }

    /**
     * TC-UADM-09
     * Upload a new valid image and confirm DB image field gets new filename.
     */
    public function test_admin_details_new_image(): void
    {
        $this->actingAsAdmin(['image' => 'old.jpg']);

        $newImage = UploadedFile::fake()->image('new_admin.png', 100, 100);

        $response = $this->post('/admin/update-admin-details', [
            'admin_name' => 'Admin New Image',
            'admin_mobile' => '0933444555',
            'current_admin_image' => 'old.jpg',
            'admin_image' => $newImage,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success_message', 'Admin details updated successfully!');

        $updatedAdmin = Admin::where('email', 'admin.profile@example.com')->firstOrFail();

        // CheckDB: image value must be changed to generated filename with png extension.
        $this->assertNotSame('old.jpg', $updatedAdmin->image);
        $this->assertStringEndsWith('.png', $updatedAdmin->image);
    }

    /**
     * TC-UADM-10
        * Handle invalid image upload gracefully.
     *
     * We emulate an uploaded file with an explicit upload error so hasFile=true
        * but isValid=false.
     */
    public function test_admin_details_invalid_file(): void
    {
        $this->actingAsAdmin(['image' => null]);

        $tmpPath = tempnam(sys_get_temp_dir(), 'bad_upload_');
        file_put_contents($tmpPath, 'not-an-image');

        $invalidUpload = new UploadedFile(
            $tmpPath,
            'broken.png',
            'image/png',
            UPLOAD_ERR_CANT_WRITE,
            true
        );

        $response = $this->post('/admin/update-admin-details', [
            'admin_name' => 'Admin Invalid File',
            'admin_mobile' => '0944555666',
            'admin_image' => $invalidUpload,
        ]);

        // Expected behavior: no crash, redirect back with validation-style feedback.
        $response->assertRedirect('/admin/update-admin-details');
        $response->assertSessionHasErrors(['admin_image']);
    }
}
