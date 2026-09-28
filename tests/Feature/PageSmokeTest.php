<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Opens every page with realistic data so database-specific SQL
 * (e.g. MySQL-only functions that break on SQLite) fails loudly.
 */
class PageSmokeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        $this->travelTo(Carbon::parse('2026-09-28 10:00:00', 'Asia/Jakarta'));
        $this->seed(DatabaseSeeder::class);

        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();

        foreach (['regular', 'ob', 'security', 'engineering'] as $index => $code) {
            $this->actingAs($this->admin)->post(route('employees.store'), [
                'name' => 'Karyawan '.ucfirst($code),
                'email' => "{$code}@example.com",
                'staff' => ucfirst($code),
                'basic_salary' => 3000000,
                'default_work_schedule_id' => WorkSchedule::where('code', $code)->value('id'),
            ])->assertSessionHasNoErrors();
        }

        $employeeUser = User::where('email', 'regular@example.com')->firstOrFail();

        $this->actingAs($this->admin)->post(route('admin.attendance.store'), [
            'user_id' => $employeeUser->id,
            'clock_in_date' => '2026-09-25',
            'type' => 'regular',
            'clock_in_time' => '08:55',
            'clock_out_date' => '2026-09-25',
            'clock_out_time' => '18:05',
            'status' => 'on_time',
            'manual_reason' => 'Data uji smoke test',
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->post(route('payrolls.generate'), [
            'period_month' => 9,
            'period_year' => 2026,
        ])->assertSessionHasNoErrors();
    }

    #[Test]
    public function admin_pages_render_without_server_errors(): void
    {
        $employee = Employee::firstOrFail();
        $attendance = Attendance::firstOrFail();
        $payroll = Payroll::firstOrFail();

        $this->assertPagesLoad($this->admin, [
            '/dashboard',
            '/employees',
            "/employees/{$employee->id}",
            "/employees/{$employee->id}/edit",
            '/admin/attendance',
            '/admin/attendance?status=alpha',
            '/admin/attendance/create',
            "/admin/attendance/schedule-preview?user_id={$employee->user_id}&date=2026-09-25",
            "/admin/attendance/{$attendance->id}/edit",
            '/admin/alfa-izin',
            '/admin/alfa-izin/export/excel',
            '/leave-verification',
            '/work-calendars',
            '/activity-log',
            '/settings/work-hours',
            '/settings/location',
            '/settings/security-schedules',
            '/payrolls',
            '/payrolls?period_month=9&period_year=2026',
            '/payrolls/export?period_month=9&period_year=2026',
            "/payrolls/{$payroll->id}",
            '/profile',
        ]);
    }

    #[Test]
    public function employee_pages_render_without_server_errors(): void
    {
        $user = User::where('email', 'regular@example.com')->firstOrFail();
        $payroll = Payroll::where('employee_id', $user->employee->id)->firstOrFail();

        $this->assertPagesLoad($user, [
            '/dashboard',
            '/attendance',
            '/attendance/history',
            '/attendance/server-time',
            '/leave-verification/my-submissions',
            '/my-payrolls',
            "/my-payrolls/{$payroll->id}",
            '/profile',
        ]);
    }

    /**
     * @param  list<string>  $urls
     */
    private function assertPagesLoad(User $user, array $urls): void
    {
        $failures = [];

        foreach ($urls as $url) {
            $response = $this->actingAs($user)->get($url);

            if ($response->getStatusCode() >= 500) {
                $failures[] = $url.' => '.($response->exception?->getMessage() ?? $response->getStatusCode());
            }
        }

        $this->assertSame([], $failures);
    }
}
