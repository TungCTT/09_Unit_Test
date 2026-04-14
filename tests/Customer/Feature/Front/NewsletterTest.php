<?php

namespace Tests\Feature\Front;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NewsletterTest extends TestCase
{
    use RefreshDatabase;

    private array $ajax = ['X-Requested-With' => 'XMLHttpRequest'];

    /**
     * Test 24.1 — count > 0: email đã tồn tại => "Email already exists"
     */
    public function test_addSubscriber_returns_error_when_email_already_exists(): void
    {
        DB::table('newsletter_subscribers')->insert([
            'email' => 'existing@example.com',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withHeaders($this->ajax)
            ->post('/add-subscriber-email', [
                'subscriber_email' => 'existing@example.com',
            ]);

        $response->assertSeeText('Email already exists');
        // Không tạo duplicate
        $this->assertEquals(1, DB::table('newsletter_subscribers')->where('email', 'existing@example.com')->count());
    }

    /**
     * Test 24.2 — count = 0: email mới => lưu vào DB status=1, "Email saved"
     */
    public function test_addSubscriber_saves_new_email_with_active_status(): void
    {
        $response = $this->withHeaders($this->ajax)
            ->post('/add-subscriber-email', [
                'subscriber_email' => 'newsubscriber@example.com',
            ]);

        $response->assertSeeText('Email saved');
        $this->assertDatabaseHas('newsletter_subscribers', [
            'email'  => 'newsubscriber@example.com',
            'status' => 1,
        ]);
    }
}
