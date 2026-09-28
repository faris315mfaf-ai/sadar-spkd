<?php

namespace Tests\Unit;

use App\DTOs\ShiftSchedule;
use App\Enums\AttendanceStatus;
use App\Support\RichTextSanitizer;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class ShiftScheduleEdgeCasesTest extends TestCase
{
    private function at(string $time): Carbon
    {
        return Carbon::parse($time, 'Asia/Jakarta');
    }

    public function test_late_clock_in_stays_late_after_late_clock_out(): void
    {
        $schedule = new ShiftSchedule('09:00:00', '09:15:00', '18:00:00', '18:15:00');

        $this->assertSame(
            AttendanceStatus::Late,
            $schedule->resolveClockOutStatus($this->at('2026-09-28 18:30'), AttendanceStatus::Late),
        );
        $this->assertSame(
            AttendanceStatus::LateOut,
            $schedule->resolveClockOutStatus($this->at('2026-09-28 18:30'), AttendanceStatus::OnTime),
        );
    }

    public function test_blank_clock_out_limit_means_same_day_without_limit(): void
    {
        // OB with "Batas Pulang Lewat" left empty.
        $schedule = new ShiftSchedule('07:30:00', '07:30:00', '16:00:00', null);
        $dutyDate = $this->at('2026-09-28');

        $this->assertFalse($schedule->opensClockOutOnNextDutyDay());
        $this->assertTrue($schedule->isEarlyClockOut($this->at('2026-09-28 15:59'), $dutyDate));
        $this->assertFalse($schedule->isEarlyClockOut($this->at('2026-09-28 16:05'), $dutyDate));
        $this->assertSame('mulai 16:00 (tanpa batas)', $schedule->clockOutWindowDescription());
    }

    public function test_security_24_hour_shift_still_clocks_out_next_morning(): void
    {
        $schedule = new ShiftSchedule('07:00:00', '07:15:00', '07:00:00', null);
        $dutyDate = $this->at('2026-09-28');

        $this->assertTrue($schedule->opensClockOutOnNextDutyDay());
        $this->assertTrue($schedule->isEarlyClockOut($this->at('2026-09-28 20:00'), $dutyDate));
        $this->assertFalse($schedule->isEarlyClockOut($this->at('2026-09-29 07:05'), $dutyDate));
    }

    public function test_overnight_window_allows_late_clock_out_next_morning(): void
    {
        $schedule = new ShiftSchedule('17:00:00', '22:00:00', '22:00:00', '07:00:00');
        $dutyDate = $this->at('2026-09-28');

        $this->assertTrue($schedule->isEarlyClockOut($this->at('2026-09-28 21:00'), $dutyDate));
        $this->assertFalse($schedule->isEarlyClockOut($this->at('2026-09-29 02:00'), $dutyDate));
        $this->assertFalse($schedule->isEarlyClockOut($this->at('2026-09-29 07:30'), $dutyDate));
        $this->assertFalse($schedule->isLateClockOut($this->at('2026-09-29 06:30'), $dutyDate));
        $this->assertTrue($schedule->isLateClockOut($this->at('2026-09-29 07:30'), $dutyDate));
    }

    public function test_sanitizer_drops_scripts_and_attributes_but_keeps_formatting(): void
    {
        $html = '<p onclick="x()"><strong>Rapat</strong> tim</p><img src=x onerror="alert(1)"><script>alert(2)</script>';

        $this->assertSame('<p><strong>Rapat</strong> tim</p>', RichTextSanitizer::sanitizeHtml($html));
    }

    public function test_sanitizer_keeps_typed_angle_brackets_as_text(): void
    {
        $html = '<p>&lt;img src=x onerror=alert(1)&gt;</p>';

        $this->assertSame($html, RichTextSanitizer::sanitizeHtml($html));
    }
}
