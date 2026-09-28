<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Jadwal otomatis. Di Docker dijalankan oleh container sadar-scheduler (php artisan schedule:work).
// Tanpa Docker: jalankan "php artisan schedule:run" tiap menit lewat cron.

Schedule::command('attendance:mark-alpha')
    ->dailyAt('23:00')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping();

Schedule::command('attendance:expire-pending-leaves')
    ->dailyAt('00:00')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping();

// Rekap absen masuk ke grup WhatsApp (aktifkan kalau diperlukan).
// Schedule::command('attendance:send-whatsapp-report masuk')
//     ->cron('0 11 * * 1-6')
//     ->timezone('Asia/Jakarta');
