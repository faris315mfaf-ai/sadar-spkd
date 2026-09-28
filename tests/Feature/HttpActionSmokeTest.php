<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\EmployeeSchedule;
use App\Models\Payroll;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkCalendar;
use App\Models\WorkSchedule;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Drives the real HTTP endpoints (no service mocks) that other tests only
 * cover at the service level, asserting none of them crash.
 */
class HttpActionSmokeTest extends TestCase
{
    use RefreshDatabase;

    private const LAT = -6.2;

    private const LNG = 106.816666;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Storage::fake('public');
        Storage::fake('local');
        $this->travelTo($this->at('2026-09-28 08:00'));
        $this->seed(DatabaseSeeder::class);

        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
    }

    #[Test]
    public function regular_employee_can_clock_in_and_out_over_http(): void
    {
        $user = $this->makeEmployee('regular', 'Budi Reguler');

        $this->travelTo($this->at('2026-09-28 08:55'));
        $this->actingAs($user)
            ->postJson(route('attendance.clock-in'), $this->attendancePayload('clock_in_report'))
            ->assertOk();

        $this->travelTo($this->at('2026-09-28 18:05'));
        $this->actingAs($user)
            ->postJson(route('attendance.clock-out'), $this->attendancePayload('clock_out_report'))
            ->assertOk();

        $attendance = Attendance::where('user_id', $user->id)->firstOrFail();
        $this->assertNotNull($attendance->clock_in_time);
        $this->assertNotNull($attendance->clock_out_time);
    }

    #[Test]
    public function security_employee_can_clock_out_next_morning_over_http(): void
    {
        $user = $this->makeEmployee('security', 'Satpam Malam');
        EmployeeSchedule::create([
            'employee_id' => $user->employee->id,
            'work_schedule_id' => WorkSchedule::where('code', 'security')->value('id'),
            'work_date' => '2026-09-28',
        ]);

        $this->travelTo($this->at('2026-09-28 06:55'));
        $this->actingAs($user)
            ->postJson(route('attendance.clock-in'), $this->attendancePayload('clock_in_report'))
            ->assertOk();

        $this->travelTo($this->at('2026-09-29 07:05'));
        $this->actingAs($user)
            ->postJson(route('attendance.clock-out'), $this->attendancePayload('clock_out_report'))
            ->assertOk();

        $this->assertNotNull(Attendance::where('user_id', $user->id)->value('clock_out_time'));
    }

    #[Test]
    public function face_endpoints_respond_without_errors(): void
    {
        $user = $this->makeEmployee('regular', 'Budi Reguler');

        $this->actingAs($user)->postJson(route('attendance.face.verify'), [
            'face_descriptor' => $this->descriptor(),
            'faces_detected' => 1,
        ])->assertOk();

        $this->actingAs($user)->postJson(route('attendance.face.sync-descriptor'), [
            'face_descriptor' => $this->descriptor(),
            'faces_detected' => 1,
        ])->assertOk();
    }

    #[Test]
    public function admin_actions_respond_without_server_errors(): void
    {
        $user = $this->makeEmployee('regular', 'Budi Reguler');
        $employee = $user->employee;

        $this->actingAs($this->admin)->post(route('payrolls.generate'), [
            'period_month' => 9,
            'period_year' => 2026,
        ])->assertSessionHasNoErrors();
        $payroll = Payroll::firstOrFail();

        $calendarDay = WorkCalendar::whereDate('date', '2026-09-30')->firstOrFail();

        $responses = [
            'employees.update' => $this->actingAs($this->admin)->put(route('employees.update', $employee), [
                'name' => 'Budi Diperbarui',
                'email' => $user->email,
                'basic_salary' => 3500000,
            ]),
            'payrolls.mark-paid' => $this->actingAs($this->admin)->patch(route('payrolls.mark-paid', $payroll)),
            'payrolls.send-wa-report' => $this->actingAs($this->admin)->post(route('payrolls.send-wa-report'), [
                'phone' => '6281234567890',
                'period_month' => 9,
                'period_year' => 2026,
            ]),
            'attendances.send-whatsapp-report' => $this->actingAs($this->admin)->post(route('attendances.send-whatsapp-report'), [
                'date' => '2026-09-28',
                'type' => 'masuk',
            ]),
            'work-calendars.update' => $this->actingAs($this->admin)->patch(route('work-calendars.update', $calendarDay), [
                'type' => 'holiday',
                'name' => 'Libur uji',
            ]),
            'work-calendars.generate' => $this->actingAs($this->admin)->post(route('work-calendars.generate'), ['year' => 2027]),
            'work-calendars.sync-holidays' => $this->actingAs($this->admin)->post(route('work-calendars.sync-holidays'), ['year' => 2026]),
            'employees.destroy' => $this->actingAs($this->admin)->delete(route('employees.destroy', $employee)),
        ];

        $failures = [];
        foreach ($responses as $name => $response) {
            if ($response->getStatusCode() >= 500) {
                $failures[] = $name.' => '.($response->exception?->getMessage() ?? $response->getStatusCode());
            }
        }

        $this->assertSame([], $failures);
        $this->assertNull(Employee::find($employee->id));
    }

    private function makeEmployee(string $scheduleCode, string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        $user->roles()->attach(Role::where('name', 'employee')->firstOrFail());

        Storage::disk('public')->put('employee-photos/'.$user->id.'.jpg', $this->jpegBinary());

        Employee::create([
            'user_id' => $user->id,
            'employee_code' => 'ID-'.str_pad((string) $user->id, 3, '0', STR_PAD_LEFT),
            'name' => $name,
            'email' => $user->email,
            'staff' => ucfirst($scheduleCode),
            'profile_photo' => 'employee-photos/'.$user->id.'.jpg',
            'face_descriptor' => json_encode($this->descriptor()),
            'employment_status' => 'active',
            'basic_salary' => 3_000_000,
            'default_work_schedule_id' => WorkSchedule::where('code', $scheduleCode)->value('id'),
        ]);

        return $user->fresh(['employee', 'roles']);
    }

    private function attendancePayload(string $reportField): array
    {
        return [
            $reportField => 'Laporan kerja harian untuk pengujian.',
            'face_descriptor' => $this->descriptor(),
            'faces_detected' => 1,
            'verification_photo' => 'data:image/jpeg;base64,'.base64_encode($this->jpegBinary()),
            'latitude' => self::LAT,
            'longitude' => self::LNG,
            'accuracy' => 10,
            'device_type' => 'mobile',
            'client_time' => now()->timestamp,
        ];
    }

    private function descriptor(): array
    {
        return array_fill(0, 128, 0.1);
    }

    private function jpegBinary(): string
    {
        $image = imagecreatetruecolor(320, 240);
        imagefill($image, 0, 0, imagecolorallocate($image, 120, 160, 200));
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }

    private function at(string $time): Carbon
    {
        return Carbon::parse($time, 'Asia/Jakarta');
    }
}
