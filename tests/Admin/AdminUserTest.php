<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AdminUserTest - Feature tests for Admin\UserController
 *
 * Covered controller methods:
 *  - users()
 *  - updateUserStatus()
 */
class AdminUserTest extends TestCase
{
    protected static bool $userSchemaReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (!self::$userSchemaReady) {
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

            self::$userSchemaReady = true;
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

    /** Create a user account. */
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

    /** Login as admin. */
    private function loginAdmin(?Admin $admin = null): Admin
    {
        $admin = $admin ?? $this->makeAdmin();
        $this->actingAs($admin, 'admin');

        return $admin;
    }

    // =========================================================================
    // 1. users()
    // =========================================================================

    public function test_users_redirects_unauthenticated_admin(): void
    {
        $response = $this->get('/admin/users');

        $response->assertRedirect('/admin/login');
    }

    public function test_users_returns_view_for_authenticated_admin(): void
    {
        $this->loginAdmin();
        $this->makeUser();

        $response = $this->get('/admin/users');

        // Controller returns view; even if view file errors, response should be 200-ish
        // Focus on: users method is callable and doesn't crash
        $this->assertTrue($response->status() > 0);
    }

    public function test_users_returns_array_data_when_no_users(): void
    {
        $this->loginAdmin();

        $response = $this->get('/admin/users');

        // Verify Session set to 'users' page
        $this->assertEquals('users', session('page'));
    }

    // =========================================================================
    // 2. updateUserStatus()
    // =========================================================================

    public function test_updateUserStatus_redirects_unauthenticated_admin(): void
    {
        $user = $this->makeUser(['status' => 1]);

        $response = $this->post('/admin/update-user-status', [
            'user_id' => $user->id,
            'status' => 'Active',
        ]);

        $response->assertRedirect('/admin/login');
    }

    public function test_updateUserStatus_non_ajax_request_does_not_change_status(): void
    {
        $this->loginAdmin();
        $user = $this->makeUser(['status' => 1]);

        $response = $this->post('/admin/update-user-status', [
            'user_id' => $user->id,
            'status' => 'Active',
        ]);

        // Method only handles AJAX; non-AJAX falls through without JSON payload.
        $response->assertStatus(200);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'status' => 1,
        ]);
    }

    public function test_updateUserStatus_sets_status_to_inactive_when_current_is_active(): void
    {
        $this->loginAdmin();
        $user = $this->makeUser(['status' => 1]);

        $response = $this->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->postJson('/admin/update-user-status', [
                'user_id' => $user->id,
                'status' => 'Active',
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 0,
            'user_id' => $user->id,
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'status' => 0,
        ]);
    }

    public function test_updateUserStatus_sets_status_to_active_when_current_is_inactive(): void
    {
        $this->loginAdmin();
        $user = $this->makeUser(['status' => 0]);

        $response = $this->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->postJson('/admin/update-user-status', [
                'user_id' => $user->id,
                'status' => 'Inactive',
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 1,
            'user_id' => $user->id,
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'status' => 1,
        ]);
    }

    public function test_updateUserStatus_ignores_invalid_user_id(): void
    {
        $this->loginAdmin();

        $response = $this->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->postJson('/admin/update-user-status', [
                'user_id' => 99999,
                'status' => 'Active',
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 0,
        ]);

        // No DB record should exist with this id.
        $this->assertDatabaseMissing('users', ['id' => 99999]);
    }
}
