<?php

namespace App\Console\Commands;

use App\Services\WhatsappService;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class WhatsappGroups extends Command
{
    protected $signature = 'whatsapp:groups
                            {--test : Kirim pesan uji ke grup yang diisi di WA_GROUP_ID}';

    protected $description = 'Cek koneksi bot WhatsApp dan tampilkan ID grup untuk WA_GROUP_ID';

    public function handle(WhatsappService $whatsapp): int
    {
        $baseUrl = (string) config('services.wa_bot.url');
        $token = config('services.wa_bot.token');
        $groupId = config('services.wa_bot.group_id');

        if (! str_starts_with($baseUrl, 'http') || blank($token)) {
            $this->error('WA_BOT_URL dan WA_BOT_TOKEN di .env belum diisi.');

            return self::FAILURE;
        }

        try {
            $response = Http::timeout(10)->withToken($token)->get($baseUrl.'/groups');
        } catch (ConnectionException) {
            $this->error('Bot WhatsApp belum berjalan di '.$baseUrl.'.');
            $this->line('Jalankan di terminal lain: cd node-service lalu npm run dev');

            return self::FAILURE;
        }

        if ($response->status() === 401) {
            $this->error('Token ditolak: WA_BOT_TOKEN (.env) harus sama dengan BOT_TOKEN (node-service/.env).');

            return self::FAILURE;
        }

        if ($response->status() === 503) {
            $this->error('Bot berjalan, tetapi WhatsApp belum terhubung.');
            $this->line('Scan QR di terminal bot: WhatsApp > Perangkat tertaut > Tautkan perangkat.');

            return self::FAILURE;
        }

        if (! $response->successful()) {
            $this->error('Bot membalas status '.$response->status().': '.$response->body());

            return self::FAILURE;
        }

        $groups = collect($response->json())
            ->map(fn (array $group) => [
                $group['id'] === $groupId ? '✓' : '',
                $group['subject'] ?? '-',
                $group['id'],
            ])
            ->sortBy(1)
            ->values();

        if ($groups->isEmpty()) {
            $this->warn('Nomor ini belum tergabung di grup mana pun. Masukkan nomor ke grup tujuan dulu.');

            return self::FAILURE;
        }

        $this->info('WhatsApp terhubung. Grup yang diikuti nomor ini:');
        $this->table(['', 'Nama Grup', 'ID (isi ke WA_GROUP_ID)'], $groups->all());

        if (blank($groupId)) {
            $this->warn('WA_GROUP_ID belum diisi. Salin ID grup tujuan ke .env, lalu jalankan: php artisan config:clear');

            return self::SUCCESS;
        }

        if (! $groups->contains(fn (array $row) => $row[2] === $groupId)) {
            $this->warn("WA_GROUP_ID ({$groupId}) tidak ada di daftar ini. Periksa lagi ID-nya.");

            return self::FAILURE;
        }

        if ($this->option('test')) {
            $sent = $whatsapp->sendGroupMessage(
                '✅ Uji koneksi '.config('app.name').': bot absensi sudah terhubung ke grup ini.'
            );

            $sent
                ? $this->info('Pesan uji terkirim. Cek grup WhatsApp Anda.')
                : $this->error('Pesan uji gagal dikirim. Lihat storage/logs/laravel.log.');

            return $sent ? self::SUCCESS : self::FAILURE;
        }

        return self::SUCCESS;
    }
}
