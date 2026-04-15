<?php

namespace Tests\Feature\Front;

use App\Models\Cart;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

class UserAuthTest extends TestCase
{
    use RefreshDatabase;

    private array $ajax = ['X-Requested-With' => 'XMLHttpRequest'];

    // =========================================================================
    // userRegister — Tests 10.x
    // =========================================================================

    /**
     * Test 10.1 — Non-AJAX request: không xử lý
     */
    public function test_register_ignores_non_ajax_request(): void
    {
        $response = $this->post('/user/register', [
            'name'     => 'Test User',
            'email'    => 'test@example.com',
            'mobile'   => '0987654321',
            'password' => 'secret123',
            'accept'   => 'on',
        ]);

        // Non-AJAX => không có JSON response, không tạo user
        $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);
    }

    /**
     * Test 10.2 — Dữ liệu hợp lệ => tạo user với status=0, trả JSON success
     */
    public function test_register_valid_data_creates_user_with_status_zero(): void
    {
        Mail::fake();

        $response = $this->withHeaders($this->ajax)
            ->post('/user/register', [
                'name'     => 'Nguyen Van A',
                'email'    => 'newuser@example.com',
                'mobile'   => '0987654321',
                'password' => 'secret123',
                'accept'   => 'on',
            ]);

        $response->assertJson(['type' => 'success']);
        $this->assertDatabaseHas('users', [
            'email'  => 'newuser@example.com',
            'status' => 0,
        ]);
    }

    /**
     * Test 10.3 — Thiếu name => validation error
     */
    public function test_register_fails_without_name(): void
    {
        $response = $this->withHeaders($this->ajax)
            ->post('/user/register', [
                'email'    => 'user2@example.com',
                'mobile'   => '0987654321',
                'password' => 'secret123',
                'accept'   => 'on',
            ]);

        $response->assertJson(['type' => 'error']);
        $this->assertArrayHasKey('name', $response->json('errors'));
    }

    /**
     * Test 10.4 — Mobile không phải 10 chữ số => error
     */
    public function test_register_fails_with_invalid_mobile(): void
    {
        $response = $this->withHeaders($this->ajax)
            ->post('/user/register', [
                'name'     => 'Nguyen Van B',
                'email'    => 'user3@example.com',
                'mobile'   => '123',
                'password' => 'secret123',
                'accept'   => 'on',
            ]);

        $response->assertJson(['type' => 'error']);
        $this->assertArrayHasKey('mobile', $response->json('errors'));
    }

    /**
     * Test 10.5 — Email format sai => error
     */
    public function test_register_fails_with_invalid_email_format(): void
    {
        $response = $this->withHeaders($this->ajax)
            ->post('/user/register', [
                'name'     => 'Nguyen Van C',
                'email'    => 'not-an-email',
                'mobile'   => '0987654321',
                'password' => 'secret123',
                'accept'   => 'on',
            ]);

        $response->assertJson(['type' => 'error']);
        $this->assertArrayHasKey('email', $response->json('errors'));
    }

    /**
     * Test 10.6 — Email đã tồn tại (unique) => error
     */
    public function test_register_fails_when_email_already_exists(): void
    {
        User::factory()->create(['email' => 'existing@example.com', 'status' => 1]);

        $response = $this->withHeaders($this->ajax)
            ->post('/user/register', [
                'name'     => 'Nguyen Van D',
                'email'    => 'existing@example.com',
                'mobile'   => '0987654321',
                'password' => 'secret123',
                'accept'   => 'on',
            ]);

        $response->assertJson(['type' => 'error']);
        $this->assertArrayHasKey('email', $response->json('errors'));
    }

    /**
     * Test 10.7 — Password < 6 ký tự => error
     */
    public function test_register_fails_with_short_password(): void
    {
        $response = $this->withHeaders($this->ajax)
            ->post('/user/register', [
                'name'     => 'Nguyen Van E',
                'email'    => 'user4@example.com',
                'mobile'   => '0987654321',
                'password' => '123',
                'accept'   => 'on',
            ]);

        $response->assertJson(['type' => 'error']);
        $this->assertArrayHasKey('password', $response->json('errors'));
    }

    /**
     * Test 10.8 — Thiếu accept (T&C) => error
     */
    public function test_register_fails_without_accept_terms(): void
    {
        $response = $this->withHeaders($this->ajax)
            ->post('/user/register', [
                'name'     => 'Nguyen Van F',
                'email'    => 'user5@example.com',
                'mobile'   => '0987654321',
                'password' => 'secret123',
            ]);

        $response->assertJson(['type' => 'error']);
        $this->assertArrayHasKey('accept', $response->json('errors'));
    }

    // =========================================================================
    // userLogin — Tests 11.x
    // =========================================================================

    /**
     * Test 11.1 — Email không hợp lệ => validation error
     */
    public function test_login_fails_with_invalid_email_format(): void
    {
        $response = $this->withHeaders($this->ajax)
            ->post('/user/login', [
                'email'    => 'not-an-email',
                'password' => 'secret123',
            ]);

        $response->assertJson(['type' => 'error']);
    }

    /**
     * Test 11.2 — Email không tồn tại trong DB => validation error
     */
    public function test_login_fails_when_email_not_in_database(): void
    {
        $response = $this->withHeaders($this->ajax)
            ->post('/user/login', [
                'email'    => 'nobody@example.com',
                'password' => 'secret123',
            ]);

        $response->assertJson(['type' => 'error']);
    }

    /**
     * Test 11.3 — Email đúng nhưng password sai => 'incorrect'
     */
    public function test_login_fails_with_wrong_password(): void
    {
        User::factory()->create([
            'email'    => 'user@example.com',
            'password' => bcrypt('correctpassword'),
            'status'   => 1,
        ]);

        $response = $this->withHeaders($this->ajax)
            ->post('/user/login', [
                'email'    => 'user@example.com',
                'password' => 'wrongpassword',
            ]);

        $response->assertJson(['type' => 'incorrect']);
    }

    /**
     * Test 11.4 — Login đúng nhưng status = 0 => 'inactive', auto logout
     */
    public function test_login_returns_inactive_when_account_not_activated(): void
    {
        User::factory()->create([
            'email'    => 'inactive@example.com',
            'password' => bcrypt('secret123'),
            'status'   => 0,
        ]);

        $response = $this->withHeaders($this->ajax)
            ->post('/user/login', [
                'email'    => 'inactive@example.com',
                'password' => 'secret123',
            ]);

        $response->assertJson(['type' => 'inactive']);
        $this->assertGuest();
    }

    /**
     * Test 11.5 — Login đúng + session_id có sẵn => cart được merge sang user_id
     */
    public function test_login_merges_guest_cart_when_session_id_exists(): void
    {
        $user      = User::factory()->create(['password' => bcrypt('pass123'), 'status' => 1]);
        $sessionId = 'pre-login-session';

        // Giả lập guest cart
        \App\Models\Category::factory()->create(['parent_id' => 0, 'status' => 1]);
        $product = \App\Models\Product::factory()->create(['category_id' => 1, 'section_id' => 1]);
        Cart::factory()->create([
            'session_id' => $sessionId,
            'user_id'    => null,
            'product_id' => $product->id,
        ]);

        $response = $this->withSession(['session_id' => $sessionId])
            ->withHeaders($this->ajax)
            ->post('/user/login', [
                'email'    => $user->email,
                'password' => 'pass123',
            ]);

        $response->assertJson(['type' => 'success']);
        $this->assertDatabaseHas('carts', [
            'session_id' => $sessionId,
            'user_id'    => $user->id,
        ]);
    }

    /**
     * Test 11.6 — Login đúng + KHÔNG có session_id => không merge cart, vẫn success
     */
    public function test_login_succeeds_without_session_cart_merge(): void
    {
        $user = User::factory()->create(['password' => bcrypt('pass123'), 'status' => 1]);

        // Không set session_id
        $response = $this->withHeaders($this->ajax)
            ->post('/user/login', [
                'email'    => $user->email,
                'password' => 'pass123',
            ]);

        $response->assertJson(['type' => 'success']);
        $this->assertDatabaseMissing('carts', ['user_id' => $user->id]);
    }

    /**
     * Test 11.7 — Rate Limiting (chống spam login thủ công)
     */
    public function test_login_blocks_user_after_too_many_attempts(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 6; $i++) {
            $response = $this->post('/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ]);
        }

        $this->assertGuest();
        $response->assertSessionHasErrors('email');
    }

    // =========================================================================
    // confirmAccount — Tests 12.x
    // =========================================================================

    /**
     * Test 12.1 — Code không hợp lệ (email không tồn tại) => 404
     */
    public function test_confirmAccount_returns_404_for_invalid_code(): void
    {
        $code     = base64_encode('nobody@example.com');
        $response = $this->get('/user/confirm/' . $code);

        $response->assertStatus(404);
    }

    /**
     * Test 12.2 — Email tồn tại nhưng đã active (status=1) => error message
     */
    public function test_confirmAccount_shows_error_if_already_activated(): void
    {
        $user = User::factory()->create(['status' => 1]);
        $code = base64_encode($user->email);

        $response = $this->get('/user/confirm/' . $code);

        $response->assertRedirect();
        $response->assertSessionHas('error_message');
    }

    /**
     * Test 12.3 — Email tồn tại, chưa active (status=0) => kích hoạt thành công
     */
    public function test_confirmAccount_activates_user_successfully(): void
    {
        Mail::fake();

        $user = User::factory()->create(['status' => 0]);
        $code = base64_encode($user->email);

        $response = $this->get('/user/confirm/' . $code);

        $response->assertRedirect();
        $response->assertSessionHas('success_message');
        $this->assertDatabaseHas('users', ['email' => $user->email, 'status' => 1]);
    }

    // =========================================================================
    // userUpdatePassword — Tests 13.x
    // =========================================================================

    /**
     * Test 13.1 — new_password < 6 ký tự => error
     */
    public function test_updatePassword_fails_when_new_password_too_short(): void
    {
        $user = User::factory()->create(['password' => bcrypt('oldpass'), 'status' => 1]);
        $this->actingAs($user);

        $response = $this->withHeaders($this->ajax)
            ->post('/user/update-password', [
                'current_password'  => 'oldpass',
                'new_password'      => '123',
                'confirm_password'  => '123',
            ]);

        $response->assertJson(['type' => 'error']);
    }

    /**
     * Test 13.2 — confirm_password != new_password => error
     */
    public function test_updatePassword_fails_when_confirm_does_not_match(): void
    {
        $user = User::factory()->create(['password' => bcrypt('oldpass'), 'status' => 1]);
        $this->actingAs($user);

        $response = $this->withHeaders($this->ajax)
            ->post('/user/update-password', [
                'current_password'  => 'oldpass',
                'new_password'      => 'newpass1',
                'confirm_password'  => 'differentpass',
            ]);

        $response->assertJson(['type' => 'error']);
    }

    /**
     * Test 13.3 — current_password sai => 'incorrect'
     */
    public function test_updatePassword_returns_incorrect_when_current_password_wrong(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correctold'), 'status' => 1]);
        $this->actingAs($user);

        $response = $this->withHeaders($this->ajax)
            ->post('/user/update-password', [
                'current_password'  => 'wrongold',
                'new_password'      => 'newpass1',
                'confirm_password'  => 'newpass1',
            ]);

        $response->assertJson(['type' => 'incorrect']);
    }

    /**
     * Test 13.4 — Đủ điều kiện => password được update, success
     */
    public function test_updatePassword_succeeds_with_correct_data(): void
    {
        $user = User::factory()->create(['password' => bcrypt('oldpass1'), 'status' => 1]);
        $this->actingAs($user);

        $response = $this->withHeaders($this->ajax)
            ->post('/user/update-password', [
                'current_password'  => 'oldpass1',
                'new_password'      => 'newpass22',
                'confirm_password'  => 'newpass22',
            ]);

        $response->assertJson(['type' => 'success']);
    }

    // =========================================================================
    // forgotPassword — Tests 14.x
    // =========================================================================

    /**
     * Test 14.1 — Email không tồn tại => error
     */
    public function test_forgotPassword_fails_for_nonexistent_email(): void
    {
        $response = $this->withHeaders($this->ajax)
            ->post('/user/forgot-password', [
                'email' => 'nobody@example.com',
            ]);

        $response->assertJson(['type' => 'error']);
    }

    /**
     * Test 14.2 — Email tồn tại => password mới set, success
     */
    public function test_forgotPassword_resets_password_for_existing_email(): void
    {
        Mail::fake();

        $user        = User::factory()->create(['email' => 'forgot@example.com', 'status' => 1]);
        $oldPassword = $user->password;

        $response = $this->withHeaders($this->ajax)
            ->post('/user/forgot-password', [
                'email' => 'forgot@example.com',
            ]);

        $response->assertJson(['type' => 'success']);

        // Password trong DB phải đã thay đổi
        $newHash = User::where('email', 'forgot@example.com')->value('password');
        $this->assertNotEquals($oldPassword, $newHash);
    }
}
