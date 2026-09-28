<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WhatsappGroupsCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.wa_bot.url' => 'http://127.0.0.1:3000/wa',
            'services.wa_bot.token' => 'bot-token',
            'services.wa_bot.group_id' => '1203630@g.us',
        ]);
    }

    #[Test]
    public function it_lists_groups_and_sends_a_test_message(): void
    {
        Http::fake([
            '*/wa/groups' => Http::response([
                ['id' => '1203630@g.us', 'subject' => 'Absensi Kantor'],
                ['id' => '9999@g.us', 'subject' => 'Keluarga'],
            ]),
            '*/wa/send-group' => Http::response(['success' => true]),
        ]);

        $this->artisan('whatsapp:groups', ['--test' => true])
            ->expectsOutputToContain('Absensi Kantor')
            ->expectsOutputToContain('Pesan uji terkirim')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/wa/send-group')
            && $request['groupId'] === '1203630@g.us'
            && $request->hasHeader('Authorization', 'Bearer bot-token'));
    }

    #[Test]
    public function it_explains_when_whatsapp_is_not_linked_yet(): void
    {
        Http::fake(['*/wa/groups' => Http::response(['success' => false], 503)]);

        $this->artisan('whatsapp:groups')
            ->expectsOutputToContain('WhatsApp belum terhubung')
            ->assertFailed();
    }

    #[Test]
    public function it_flags_a_group_id_that_is_not_in_the_list(): void
    {
        Http::fake(['*/wa/groups' => Http::response([['id' => '9999@g.us', 'subject' => 'Keluarga']])]);

        $this->artisan('whatsapp:groups', ['--test' => true])
            ->expectsOutputToContain('tidak ada di daftar')
            ->assertFailed();

        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/wa/send-group'));
    }

    #[Test]
    public function it_requires_bot_settings(): void
    {
        config(['services.wa_bot.token' => null]);

        $this->artisan('whatsapp:groups')
            ->expectsOutputToContain('WA_BOT_URL dan WA_BOT_TOKEN')
            ->assertFailed();
    }
}
