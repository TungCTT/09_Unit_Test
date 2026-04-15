<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\Product;
use App\Models\Rating;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ReviewTest - Feature tests for Admin\RatingController
 *
 * Covered controller methods:
 *  - ratings()
 *  - updateRatingStatus()
 *  - deleteRating()
 */
class ReviewTest extends TestCase
{
    protected static bool $reviewSchemaReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (!self::$reviewSchemaReady) {
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

            self::$reviewSchemaReady = true;
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

    private function makeUser(array $attrs = []): User
    {
        $user = new User();
        $user->name = $attrs['name'] ?? 'Test User';
        $user->email = $attrs['email'] ?? 'user_' . Str::random(8) . '@test.com';
        $user->password = bcrypt($attrs['password'] ?? 'password123');
        $user->mobile = $attrs['mobile'] ?? '9876543210';
        $user->address_delivery = $attrs['address_delivery'] ?? 'Test Address';
        $user->city = $attrs['city'] ?? 'Test City';
        $user->state = $attrs['state'] ?? 'Test State';
        $user->country = $attrs['country'] ?? 'Test Country';
        $user->pincode = $attrs['pincode'] ?? '123456';
        $user->status = $attrs['status'] ?? 1;
        $user->save();

        return $user;
    }

    private function makeProduct(array $attrs = []): Product
    {
        $product = new Product();
        $product->section_id = $attrs['section_id'] ?? 1;
        $product->category_id = $attrs['category_id'] ?? 1;
        $product->brand_id = $attrs['brand_id'] ?? 1;
        $product->vendor_id = $attrs['vendor_id'] ?? 0;
        $product->admin_id = $attrs['admin_id'] ?? 1;
        $product->admin_type = $attrs['admin_type'] ?? 'admin';
        $product->product_name = $attrs['product_name'] ?? 'Test Product';
        $product->product_code = $attrs['product_code'] ?? 'TP001';
        $product->product_color = $attrs['product_color'] ?? 'Black';
        $product->product_price = $attrs['product_price'] ?? 100;
        $product->product_discount = $attrs['product_discount'] ?? 0;
        $product->product_weight = $attrs['product_weight'] ?? 1;
        $product->is_featured = $attrs['is_featured'] ?? 'No';
        $product->status = $attrs['status'] ?? 1;
        $product->save();

        return $product;
    }

    private function makeRating(array $attrs = []): Rating
    {
        $user = $attrs['user'] ?? $this->makeUser();
        $product = $attrs['product'] ?? $this->makeProduct();

        $rating = new Rating();
        $rating->user_id = $attrs['user_id'] ?? $user->id;
        $rating->product_id = $attrs['product_id'] ?? $product->id;
        $rating->review = $attrs['review'] ?? 'Great product';
        $rating->rating = $attrs['rating'] ?? 5;
        $rating->status = $attrs['status'] ?? 1;
        $rating->save();

        return $rating;
    }

    private function loginAdmin(?Admin $admin = null): Admin
    {
        $admin = $admin ?? $this->makeAdmin();
        $this->actingAs($admin, 'admin');

        return $admin;
    }

    // =========================================================================
    // 1. ratings()
    // =========================================================================

    public function test_ratings_redirects_unauthenticated_admin(): void
    {
        $response = $this->get('/admin/ratings');

        $response->assertRedirect('/admin/login');
    }

    public function test_ratings_returns_view_with_ratings_for_authenticated_admin(): void
    {
        $this->loginAdmin();
        $this->makeRating();

        $response = $this->get('/admin/ratings');

        $response->assertStatus(200);
        $response->assertViewIs('admin.ratings.ratings');
        $response->assertViewHas('ratings');
        $this->assertIsArray($response->viewData('ratings'));
    }

    // =========================================================================
    // 2. updateRatingStatus()
    // =========================================================================

    public function test_updateRatingStatus_redirects_unauthenticated_admin(): void
    {
        $rating = $this->makeRating(['status' => 1]);

        $response = $this->post('/admin/update-rating-status', [
            'rating_id' => $rating->id,
            'status' => 'Active',
        ]);

        $response->assertRedirect('/admin/login');
    }

    public function test_updateRatingStatus_non_ajax_request_does_not_change_status(): void
    {
        $this->loginAdmin();
        $rating = $this->makeRating(['status' => 1]);

        $response = $this->post('/admin/update-rating-status', [
            'rating_id' => $rating->id,
            'status' => 'Active',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('ratings', [
            'id' => $rating->id,
            'status' => 1,
        ]);
    }

    public function test_updateRatingStatus_sets_status_to_inactive_when_current_is_active(): void
    {
        $this->loginAdmin();
        $rating = $this->makeRating(['status' => 1]);

        $response = $this->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->postJson('/admin/update-rating-status', [
                'rating_id' => $rating->id,
                'status' => 'Active',
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 0,
            'rating_id' => $rating->id,
        ]);

        $this->assertDatabaseHas('ratings', [
            'id' => $rating->id,
            'status' => 0,
        ]);
    }

    public function test_updateRatingStatus_sets_status_to_active_when_current_is_inactive(): void
    {
        $this->loginAdmin();
        $rating = $this->makeRating(['status' => 0]);

        $response = $this->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->postJson('/admin/update-rating-status', [
                'rating_id' => $rating->id,
                'status' => 'Inactive',
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 1,
            'rating_id' => $rating->id,
        ]);

        $this->assertDatabaseHas('ratings', [
            'id' => $rating->id,
            'status' => 1,
        ]);
    }

    // =========================================================================
    // 3. deleteRating()
    // =========================================================================

    public function test_deleteRating_redirects_unauthenticated_admin(): void
    {
        $rating = $this->makeRating();

        $response = $this->get('/admin/delete-rating/' . $rating->id);

        $response->assertRedirect('/admin/login');
    }

    public function test_deleteRating_deletes_rating_record(): void
    {
        $this->loginAdmin();
        $rating = $this->makeRating();

        $response = $this->get('/admin/delete-rating/' . $rating->id);

        $response->assertStatus(302);
        $response->assertSessionHas('success_message', 'Rating has been deleted successfully!');
        $this->assertDatabaseMissing('ratings', ['id' => $rating->id]);
    }
}
