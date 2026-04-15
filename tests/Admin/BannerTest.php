<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\Banner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;
use Tests\TestCase;

/**
 * BannerTest - Feature tests for Admin\BannersController
 *
 * Covered controller methods:
 *  - banners()
 *  - updateBannerStatus()
 *  - deleteBanner()
 *  - addEditBanner()
 */
class BannerTest extends TestCase
{
    protected static bool $bannerSchemaReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (!self::$bannerSchemaReady) {
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

            self::$bannerSchemaReady = true;
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

    /** Create a banner record. */
    private function makeBanner(array $attrs = []): Banner
    {
        $banner = new Banner();
        $banner->image = $attrs['image'] ?? ('banner_' . Str::random(6) . '.jpg');
        $banner->type = $attrs['type'] ?? 'Slider';
        $banner->link = $attrs['link'] ?? 'https://example.com';
        $banner->title = $attrs['title'] ?? 'Test Banner';
        $banner->alt = $attrs['alt'] ?? 'Test Alt';
        $banner->status = $attrs['status'] ?? 1;
        $banner->save();

        return $banner;
    }

    /** Login as admin. */
    private function loginAdmin(?Admin $admin = null): Admin
    {
        $admin = $admin ?? $this->makeAdmin();
        $this->actingAs($admin, 'admin');

        return $admin;
    }

    // =========================================================================
    // 1. banners()
    // =========================================================================

    public function test_banners_redirects_unauthenticated_admin(): void
    {
        $response = $this->get('/admin/banners');

        $response->assertRedirect('/admin/login');
    }

    public function test_banners_returns_view_with_banners_for_authenticated_admin(): void
    {
        $this->loginAdmin();
        $this->makeBanner();

        $response = $this->get('/admin/banners');

        $response->assertStatus(200);
        $response->assertViewIs('admin.banners.banners');
        $response->assertViewHas('banners');

        // Controller uses ->toArray(), so this should be plain array data.
        $this->assertIsArray($response->viewData('banners'));
    }

    // =========================================================================
    // 2. updateBannerStatus()
    // =========================================================================

    public function test_updateBannerStatus_redirects_unauthenticated_admin(): void
    {
        $banner = $this->makeBanner(['status' => 1]);

        $response = $this->post('/admin/update-banner-status', [
            'banner_id' => $banner->id,
            'status' => 'Active',
        ]);

        $response->assertRedirect('/admin/login');
    }

    public function test_updateBannerStatus_non_ajax_request_does_not_change_status(): void
    {
        $this->loginAdmin();
        $banner = $this->makeBanner(['status' => 1]);

        $response = $this->post('/admin/update-banner-status', [
            'banner_id' => $banner->id,
            'status' => 'Active',
        ]);

        // Method only handles AJAX; non-AJAX falls through without JSON payload.
        $response->assertStatus(200);
        $this->assertDatabaseHas('banners', [
            'id' => $banner->id,
            'status' => 1,
        ]);
    }

    public function test_updateBannerStatus_sets_status_to_inactive_when_current_is_active(): void
    {
        $this->loginAdmin();
        $banner = $this->makeBanner(['status' => 1]);

        $response = $this->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->postJson('/admin/update-banner-status', [
                'banner_id' => $banner->id,
                'status' => 'Active',
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 0,
            'banner_id' => $banner->id,
        ]);

        $this->assertDatabaseHas('banners', [
            'id' => $banner->id,
            'status' => 0,
        ]);
    }

    public function test_updateBannerStatus_sets_status_to_active_when_current_is_inactive(): void
    {
        $this->loginAdmin();
        $banner = $this->makeBanner(['status' => 0]);

        $response = $this->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->postJson('/admin/update-banner-status', [
                'banner_id' => $banner->id,
                'status' => 'Inactive',
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 1,
            'banner_id' => $banner->id,
        ]);

        $this->assertDatabaseHas('banners', [
            'id' => $banner->id,
            'status' => 1,
        ]);
    }

    // =========================================================================
    // 3. deleteBanner()
    // =========================================================================

    public function test_deleteBanner_redirects_unauthenticated_admin(): void
    {
        $banner = $this->makeBanner();

        $response = $this->get('/admin/delete-banner/' . $banner->id);

        $response->assertRedirect('/admin/login');
    }

    public function test_deleteBanner_deletes_banner_record_and_file(): void
    {
        $this->loginAdmin();

        // Use the same relative path as the controller.
        $dir = 'front/images/banner_images';
        File::ensureDirectoryExists($dir);

        $fileName = 'delete_test_' . Str::random(6) . '.jpg';
        $fullPath = $dir . DIRECTORY_SEPARATOR . $fileName;
        File::put($fullPath, 'dummy-image-content');

        $banner = $this->makeBanner(['image' => $fileName]);

        $response = $this->get('/admin/delete-banner/' . $banner->id);

        $response->assertStatus(302);
        $response->assertSessionHas('success_message');

        $this->assertDatabaseMissing('banners', ['id' => $banner->id]);
        $this->assertFalse(File::exists($fullPath));
    }

    public function test_deleteBanner_deletes_record_even_when_file_does_not_exist(): void
    {
        $this->loginAdmin();

        $banner = $this->makeBanner(['image' => 'missing_file.jpg']);
        $response = $this->get('/admin/delete-banner/' . $banner->id);

        $response->assertStatus(302);
        $response->assertSessionHas('success_message', 'Banner deleted successfully!');
        $this->assertDatabaseMissing('banners', ['id' => $banner->id]);
    }

    // =========================================================================
    // 4. addEditBanner()
    // =========================================================================

    public function test_addEditBanner_get_redirects_unauthenticated_admin(): void
    {
        $response = $this->get('/admin/add-edit-banner');

        $response->assertRedirect('/admin/login');
    }

    public function test_addEditBanner_get_add_page_returns_view(): void
    {
        $this->loginAdmin();

        $response = $this->get('/admin/add-edit-banner');

        $response->assertStatus(200);
        $response->assertViewIs('admin.banners.add_edit_banner');
        $response->assertViewHas('banner');
        $response->assertViewHas('title', 'Add Banner Image');
    }

    public function test_addEditBanner_get_edit_page_returns_view(): void
    {
        $this->loginAdmin();
        $banner = $this->makeBanner();

        $response = $this->get('/admin/add-edit-banner/' . $banner->id);

        $response->assertStatus(200);
        $response->assertViewIs('admin.banners.add_edit_banner');
        $response->assertViewHas('title', 'Edit Banner Image');
    }

    public function test_addEditBanner_post_creates_banner_successfully(): void
    {
        $this->loginAdmin();

        // Controller allows add without image when hasFile('image') is false.
        $response = $this->post('/admin/add-edit-banner', [
            'type' => 'Slider',
            'link' => 'https://example.com/new',
            'title' => 'New Banner',
            'alt' => 'New Banner Alt',
        ]);

        $response->assertStatus(302);
        $response->assertRedirect('admin/banners');

        $this->assertDatabaseHas('banners', [
            'type' => 'Slider',
            'link' => 'https://example.com/new',
            'title' => 'New Banner',
            'alt' => 'New Banner Alt',
            'status' => 1,
        ]);
    }

    public function test_addEditBanner_post_creates_banner_with_slider_image_and_resizes_1920x720(): void
    {
        $this->loginAdmin();

        $fakeImage = UploadedFile::fake()->image('slider.jpg', 2000, 1000);

        Image::shouldReceive('make')->once()->andReturnSelf();
        Image::shouldReceive('resize')->once()->with('1920', '720')->andReturnSelf();
        Image::shouldReceive('save')->once()->andReturnTrue();

        $response = $this->post('/admin/add-edit-banner', [
            'type' => 'Slider',
            'link' => 'https://example.com/slider',
            'title' => 'Slider Banner',
            'alt' => 'Slider Alt',
            'image' => $fakeImage,
        ]);

        $response->assertStatus(302);
        $response->assertRedirect('admin/banners');

        $banner = Banner::latest('id')->first();
        $this->assertNotNull($banner);
        $this->assertSame('Slider', $banner->type);
        $this->assertSame('https://example.com/slider', $banner->link);
        $this->assertSame('Slider Banner', $banner->title);
        $this->assertSame('Slider Alt', $banner->alt);
        $this->assertSame(1, (int) $banner->status);
        $this->assertNotEmpty($banner->image);
        $this->assertStringEndsWith('.jpg', $banner->image);
    }

    public function test_addEditBanner_post_updates_existing_banner_successfully(): void
    {
        $this->loginAdmin();
        $banner = $this->makeBanner([
            'type' => 'Fix',
            'link' => 'https://example.com/old',
            'title' => 'Old Banner',
            'alt' => 'Old Alt',
            'image' => 'old.jpg',
        ]);

        $response = $this->post('/admin/add-edit-banner/' . $banner->id, [
            'type' => 'Fix',
            'link' => 'https://example.com/updated',
            'title' => 'Updated Banner',
            'alt' => 'Updated Alt',
        ]);

        $response->assertStatus(302);
        $response->assertRedirect('admin/banners');

        $this->assertDatabaseHas('banners', [
            'id' => $banner->id,
            'type' => 'Fix',
            'link' => 'https://example.com/updated',
            'title' => 'Updated Banner',
            'alt' => 'Updated Alt',
            'status' => 1,
        ]);
    }

    public function test_addEditBanner_post_updates_existing_banner_with_fix_image_and_resizes_1920x450(): void
    {
        $this->loginAdmin();
        $banner = $this->makeBanner([
            'type' => 'Fix',
            'link' => 'https://example.com/old-fix',
            'title' => 'Old Fix Banner',
            'alt' => 'Old Fix Alt',
            'image' => 'old_fix.jpg',
        ]);

        $fakeImage = UploadedFile::fake()->image('fix.jpg', 2000, 900);

        Image::shouldReceive('make')->once()->andReturnSelf();
        Image::shouldReceive('resize')->once()->with('1920', '450')->andReturnSelf();
        Image::shouldReceive('save')->once()->andReturnTrue();

        $response = $this->post('/admin/add-edit-banner/' . $banner->id, [
            'type' => 'Fix',
            'link' => 'https://example.com/fix-updated',
            'title' => 'Fix Updated Banner',
            'alt' => 'Fix Updated Alt',
            'image' => $fakeImage,
        ]);

        $response->assertStatus(302);
        $response->assertRedirect('admin/banners');

        $updated = Banner::findOrFail($banner->id);
        $this->assertSame('Fix', $updated->type);
        $this->assertSame('https://example.com/fix-updated', $updated->link);
        $this->assertSame('Fix Updated Banner', $updated->title);
        $this->assertSame('Fix Updated Alt', $updated->alt);
        $this->assertSame(1, (int) $updated->status);
        $this->assertNotEmpty($updated->image);
        $this->assertStringEndsWith('.jpg', $updated->image);
    }
}
