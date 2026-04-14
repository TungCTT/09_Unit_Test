<?php

namespace Tests\Feature\Front;

use App\Events\MessageSent;
use App\Models\Admin;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * ChatTest — Unit tests cho chat giữa khách hàng (User) và admin
 *
 * Các method được test:
 *  - index()              : user mở trang chat
 *  - sendMessage()        : user & admin gửi tin nhắn
 *  - getMessages()        : lấy danh sách tin nhắn
 *  - adminIndex()         : admin mở trang quản lý chat
 *  - adminShowConversation: admin xem chi tiết 1 cuộc chat
 */
class ChatTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // Helpers
    // =========================================================================

    /** Tạo user thường đã kích hoạt */
    private function makeUser(array $attrs = []): User
    {
        return User::factory()->create(array_merge(['status' => 1], $attrs));
    }

    /** Tạo superadmin */
    private function makeSuperadmin(array $attrs = []): Admin
    {
        return Admin::factory()->superadmin()->create($attrs);
    }

    /** Tạo conversation giữa user và admin */
    private function makeConversation(User $user, Admin $admin): Conversation
    {
        return Conversation::factory()->forUserAndAdmin($user->id, $admin->id)->create();
    }

    /** Tạo message từ user trong conversation */
    private function makeUserMessage(Conversation $conv, User $user, string $text = 'Hello'): Message
    {
        return Message::factory()->fromUser($user->id)->create([
            'conversation_id' => $conv->id,
            'message'         => $text,
        ]);
    }

    /** Tạo message từ admin trong conversation */
    private function makeAdminMessage(Conversation $conv, Admin $admin, string $text = 'Hi'): Message
    {
        return Message::factory()->fromAdmin($admin->id)->create([
            'conversation_id' => $conv->id,
            'message'         => $text,
        ]);
    }

    // =========================================================================
    // 1. index() — User mở trang chat
    // =========================================================================

    /**
     * User chưa login → vẫn trả về view chat.index (với conversation=null)
     */
    public function test_index_returns_view_for_unauthenticated_user(): void
    {
        $response = $this->get('/chat');

        // Middleware auth redirect về login
        $response->assertRedirect();
    }

    /**
     * User đã login, không có superadmin → view với adminError=true
     */
    public function test_index_shows_admin_error_when_no_superadmin_exists(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        // Không tạo superadmin nào

        $response = $this->get('/chat');

        $response->assertStatus(200);
        $response->assertViewHas('adminError', true);
    }

    /**
     * User đã login, có superadmin → conversation được tạo (firstOrCreate),
     * view trả về với biến conversation và messages
     */
    public function test_index_creates_conversation_and_returns_view_for_authenticated_user(): void
    {
        Event::fake();

        $user  = $this->makeUser();
        $admin = $this->makeSuperadmin();

        $this->actingAs($user);

        $response = $this->get('/chat');

        $response->assertStatus(200);
        $response->assertViewHas('conversation');
        $response->assertViewHas('messages');

        // Conversation phải được tạo trong DB
        $this->assertDatabaseHas('conversations', [
            'user_id'  => $user->id,
            'admin_id' => $admin->id,
        ]);
    }

    /**
     * Gọi index() 2 lần → conversation KHÔNG bị tạo thêm (firstOrCreate)
     */
    public function test_index_does_not_duplicate_conversation(): void
    {
        Event::fake();

        $user  = $this->makeUser();
        $admin = $this->makeSuperadmin();

        $this->actingAs($user);

        $this->get('/chat');
        $this->get('/chat');

        $this->assertEquals(
            1,
            Conversation::where('user_id', $user->id)->count()
        );
    }

    /**
     * Khi mở chat, các tin nhắn từ admin phải được mark is_read=true.
     * Hiện tại BUG: Collection::update() không tồn tại → test này FAIL.
     * Test sẽ PASS khi bug được fix.
     */
    public function test_index_marks_admin_messages_as_read(): void
    {
        Event::fake();

        $user  = $this->makeUser();
        $admin = $this->makeSuperadmin();
        $conv  = $this->makeConversation($user, $admin);

        $msg = $this->makeAdminMessage($conv, $admin, 'Do you need help?');
        $msg->update(['is_read' => false]);

        $this->actingAs($user)->get('/chat');

        $this->assertDatabaseHas('messages', ['id' => $msg->id, 'is_read' => true]);
    }

    // =========================================================================
    // 2. sendMessage() — User gửi tin nhắn
    // =========================================================================

    /**
     * User chưa login gửi message → 401 Unauthorized
     */
    public function test_sendMessage_returns_401_when_user_not_authenticated(): void
    {
        $response = $this->postJson('/chat/send', [
            'message' => 'Hello there',
        ]);

        $response->assertStatus(401);
    }

    /**
     * User gửi message rỗng → 422 validation error
     */
    public function test_sendMessage_returns_422_when_message_is_empty(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $response = $this->postJson('/chat/send', [
            'message' => '',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['message']);
    }

    /**
     * User gửi message quá dài (> 1000 ký tự) → 422
     */
    public function test_sendMessage_returns_422_when_message_exceeds_max_length(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $response = $this->postJson('/chat/send', [
            'message' => str_repeat('a', 1001),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['message']);
    }

    /**
     * User gửi message lần đầu (chưa có conversation_id)
     * → tự động tạo conversation với superadmin, lưu message vào DB
     */
    public function test_sendMessage_user_creates_conversation_and_saves_message(): void
    {
        Event::fake();

        $user  = $this->makeUser();
        $admin = $this->makeSuperadmin();

        $this->actingAs($user);

        $response = $this->postJson('/chat/send', [
            'message' => 'Xin chào admin!',
        ]);

        $response->assertStatus(200);
        $response->assertJson(['status' => 'success']);

        // Conversation được tạo
        $this->assertDatabaseHas('conversations', [
            'user_id'  => $user->id,
            'admin_id' => $admin->id,
        ]);

        // Message được lưu
        $this->assertDatabaseHas('messages', [
            'sender_id'   => $user->id,
            'sender_type' => \App\Models\User::class,
            'message'     => 'Xin chào admin!',
            'is_read'     => false,
        ]);
    }

    /**
     * User gửi message với conversation_id đã có
     * → message được lưu vào đúng conversation
     */
    public function test_sendMessage_user_sends_to_existing_conversation(): void
    {
        Event::fake();

        $user  = $this->makeUser();
        $admin = $this->makeSuperadmin();
        $conv  = $this->makeConversation($user, $admin);

        $this->actingAs($user);

        $response = $this->postJson('/chat/send', [
            'conversation_id' => $conv->id,
            'message'         => 'Tôi cần hỗ trợ',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status'          => 'success',
            'conversation_id' => $conv->id,
        ]);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conv->id,
            'sender_id'       => $user->id,
            'message'         => 'Tôi cần hỗ trợ',
        ]);
    }

    /**
     * User cố gửi vào conversation của người khác → 403 Forbidden
     */
    public function test_sendMessage_returns_403_when_user_sends_to_others_conversation(): void
    {
        Event::fake();

        $user1 = $this->makeUser();
        $user2 = $this->makeUser();
        $admin  = $this->makeSuperadmin();

        // Conversation của user2, không phải user1
        $conv = $this->makeConversation($user2, $admin);

        $this->actingAs($user1);

        $response = $this->postJson('/chat/send', [
            'conversation_id' => $conv->id,
            'message'         => 'Hack attempt',
        ]);

        $response->assertStatus(403);
    }

    /**
     * User gửi message → MessageSent event được broadcast
     */
    public function test_sendMessage_broadcasts_MessageSent_event(): void
    {
        Event::fake();

        $user  = $this->makeUser();
        $admin = $this->makeSuperadmin();
        $conv  = $this->makeConversation($user, $admin);

        $this->actingAs($user);

        $this->postJson('/chat/send', [
            'conversation_id' => $conv->id,
            'message'         => 'Test broadcast',
        ]);

        Event::assertDispatched(MessageSent::class);
    }

    /**
     * User gửi message lần đầu mà không có admin nào → 500
     */
    public function test_sendMessage_returns_500_when_no_admin_exists(): void
    {
        Event::fake();

        $user = $this->makeUser();
        $this->actingAs($user);

        // Không tạo admin

        $response = $this->postJson('/chat/send', [
            'message' => 'Có ai ở đây không?',
        ]);

        $response->assertStatus(500);
    }

    // =========================================================================
    // 3. sendMessage() — Admin gửi tin nhắn
    // =========================================================================

    /**
     * Admin (session guard) chưa đăng nhập gửi request → redirect về trang login.
     * Route sử dụng middleware `admin` (session) nên không trả JSON 401.
     */
    public function test_sendMessage_admin_unauthenticated_returns_redirect(): void
    {
        $response = $this->postJson('/admin/chat/send', [
            'message' => 'Hello user',
        ]);

        // Middleware admin redirect về trang login, không trả JSON 401
        $response->assertStatus(302);
    }

    /**
     * Admin gửi message đến conversation của mình → thành công
     */
    public function test_sendMessage_admin_sends_to_own_conversation(): void
    {
        Event::fake();

        $user  = $this->makeUser();
        $admin = $this->makeSuperadmin();
        $conv  = $this->makeConversation($user, $admin);

        $this->actingAs($admin, 'admin');

        $response = $this->postJson('/admin/chat/send', [
            'conversation_id' => $conv->id,
            'message'         => 'Chào bạn, tôi có thể giúp gì?',
        ]);

        $response->assertStatus(200);
        $response->assertJson(['status' => 'success']);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conv->id,
            'sender_id'       => $admin->id,
            'sender_type'     => \App\Models\Admin::class,
            'message'         => 'Chào bạn, tôi có thể giúp gì?',
        ]);
    }

    /**
     * Admin gửi message mà không có conversation_id → 404
     */
    public function test_sendMessage_admin_without_conversation_id_returns_404(): void
    {
        Event::fake();

        $admin = $this->makeSuperadmin();
        $this->actingAs($admin, 'admin');

        $response = $this->postJson('/admin/chat/send', [
            'message' => 'Thiếu conversation_id',
        ]);

        $response->assertStatus(404);
    }

    /**
     * Admin cố gửi vào conversation của admin khác → 403
     */
    public function test_sendMessage_admin_returns_403_when_sending_to_others_conversation(): void
    {
        Event::fake();

        $user   = $this->makeUser();
        $admin1 = $this->makeSuperadmin();
        $admin2 = Admin::factory()->regularAdmin()->create();

        // Conversation thuộc admin1
        $conv = $this->makeConversation($user, $admin1);

        // admin2 cố gửi vào conversation của admin1
        $this->actingAs($admin2, 'admin');

        $response = $this->postJson('/admin/chat/send', [
            'conversation_id' => $conv->id,
            'message'         => 'Unauthorized attempt',
        ]);

        $response->assertStatus(403);
    }

    // =========================================================================
    // 4. getMessages() — Lấy tin nhắn
    // =========================================================================

    /**
     * User lấy messages của conversation của mình → phải trả về JSON array.
     * Hiện tại BUG: Collection::update() → 500. Test sẽ PASS khi bug được fix.
     */
    public function test_getMessages_returns_messages_for_authorized_user(): void
    {
        Event::fake();

        $user  = $this->makeUser();
        $admin = $this->makeSuperadmin();
        $conv  = $this->makeConversation($user, $admin);

        $this->makeUserMessage($conv, $user, 'Hello');
        $this->makeAdminMessage($conv, $admin, 'Hi there');

        $this->actingAs($user);

        $response = $this->getJson("/chat/messages/{$conv->id}");

        $response->assertStatus(200);
        $response->assertJsonCount(2);
    }

    /**
     * User lấy messages của conversation không thuộc về mình → 403
     */
    public function test_getMessages_returns_403_for_unauthorized_user(): void
    {
        $user1 = $this->makeUser();
        $user2 = $this->makeUser();
        $admin = $this->makeSuperadmin();
        $conv  = $this->makeConversation($user2, $admin);

        $this->actingAs($user1);

        $response = $this->getJson("/chat/messages/{$conv->id}");

        $response->assertStatus(403);
    }

    /**
     * Admin lấy messages của conversation của mình → phải trả về JSON.
     * Hiện tại BUG: Collection::update() → 500. Test sẽ PASS khi bug được fix.
     */
    public function test_getMessages_returns_messages_for_authorized_admin(): void
    {
        Event::fake();

        $user  = $this->makeUser();
        $admin = $this->makeSuperadmin();
        $conv  = $this->makeConversation($user, $admin);

        $this->makeUserMessage($conv, $user, 'Tôi cần help');

        $this->actingAs($admin, 'admin');

        $response = $this->getJson("/chat/messages/{$conv->id}");

        $response->assertStatus(200);
    }

    /**
     * getMessages với conversation_id không tồn tại → 500 (findOrFail catch)
     */
    public function test_getMessages_returns_500_for_nonexistent_conversation(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $response = $this->getJson('/chat/messages/99999');

        $response->assertStatus(500);
    }

    /**
     * Khi user gọi getMessages, các tin nhắn từ admin phải được mark is_read=true.
     * Hiện tại BUG: 500 → is_read không được cập nhật. Test sẽ PASS khi bug được fix.
     */
    public function test_getMessages_marks_admin_messages_as_read_for_user(): void
    {
        Event::fake();

        $user  = $this->makeUser();
        $admin = $this->makeSuperadmin();
        $conv  = $this->makeConversation($user, $admin);

        $msg = $this->makeAdminMessage($conv, $admin, 'Reply from admin');
        $msg->update(['is_read' => false]);

        $this->actingAs($user);
        $this->getJson("/chat/messages/{$conv->id}");

        $this->assertDatabaseHas('messages', ['id' => $msg->id, 'is_read' => true]);
    }

    // =========================================================================
    // 5. adminIndex() — Admin mở trang quản lý chat
    // =========================================================================

    /**
     * Admin chưa login → redirect về trang login admin
     */
    public function test_adminIndex_redirects_unauthenticated_admin(): void
    {
        $response = $this->get('/admin/chat');

        $response->assertRedirect();
    }

    /**
     * Admin đã login → thấy danh sách conversation của mình
     */
    public function test_adminIndex_returns_view_with_conversations(): void
    {
        $user  = $this->makeUser();
        $admin = $this->makeSuperadmin();
        $this->makeConversation($user, $admin);

        $this->actingAs($admin, 'admin');

        $response = $this->get('/admin/chat');

        $response->assertStatus(200);
        $response->assertViewHas('conversations');
        $response->assertViewHas('messages');
    }

    /**
     * Admin không thấy conversation của admin khác
     */
    public function test_adminIndex_only_shows_own_conversations(): void
    {
        $user   = $this->makeUser();
        $admin1 = $this->makeSuperadmin();
        $admin2 = Admin::factory()->regularAdmin()->create();

        // Conversation thuộc admin1
        $this->makeConversation($user, $admin1);

        // admin2 đăng nhập
        $this->actingAs($admin2, 'admin');
        $response = $this->get('/admin/chat');

        $response->assertStatus(200);
        $conversations = $response->viewData('conversations');
        $this->assertCount(0, $conversations);
    }

    // =========================================================================
    // 6. adminShowConversation() — Admin xem chi tiết cuộc chat
    // =========================================================================

    /**
     * Admin xem conversation của mình → phải trả về view với conversation và messages.
     * Hiện tại BUG: Collection::update() → 500. Test sẽ PASS khi bug được fix.
     */
    public function test_adminShowConversation_returns_detail_view(): void
    {
        Event::fake();

        $user  = $this->makeUser();
        $admin = $this->makeSuperadmin();
        $conv  = $this->makeConversation($user, $admin);

        $this->makeUserMessage($conv, $user, 'Hỏi gì đó');

        $this->actingAs($admin, 'admin');

        $response = $this->get("/admin/chat/conversation/{$conv->id}");

        $response->assertStatus(200);
        $response->assertViewHas('conversation');
        $response->assertViewHas('messages');
    }

    /**
     * Admin xem conversation của admin khác → 404
     */
    public function test_adminShowConversation_returns_404_for_others_conversation(): void
    {
        $user   = $this->makeUser();
        $admin1 = $this->makeSuperadmin();
        $admin2 = Admin::factory()->regularAdmin()->create();

        $conv = $this->makeConversation($user, $admin1);

        $this->actingAs($admin2, 'admin');

        $response = $this->get("/admin/chat/conversation/{$conv->id}");

        $response->assertStatus(404);
    }

    /**
     * Khi admin xem conversation, messages từ user phải được mark is_read=true.
     * Hiện tại BUG: 500 → is_read không được cập nhật. Test sẽ PASS khi bug được fix.
     */
    public function test_adminShowConversation_marks_user_messages_as_read(): void
    {
        Event::fake();

        $user  = $this->makeUser();
        $admin = $this->makeSuperadmin();
        $conv  = $this->makeConversation($user, $admin);

        $msg = $this->makeUserMessage($conv, $user, 'Cần giúp đỡ');
        $msg->update(['is_read' => false]);

        $this->actingAs($admin, 'admin');
        $this->get("/admin/chat/conversation/{$conv->id}");

        $this->assertDatabaseHas('messages', ['id' => $msg->id, 'is_read' => true]);
    }
}
