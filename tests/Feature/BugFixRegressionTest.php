<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\AttendanceType;
use App\Enums\VerificationStatus;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\EmployeeSchedule;
use App\Models\Payroll;
use App\Models\PayrollDetail;
use App\Models\PayrollDetailChange;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\PayrollAttendanceDateBreakdown;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BugFixRegressionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Storage::fake('public');
        $this->travelTo(Carbon::parse('2026-09-28 10:00:00', 'Asia/Jakarta'));
        $this->seed(DatabaseSeeder::class);

        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
    }

    #[Test]
    public function regenerating_payroll_keeps_manual_items_and_items_hr_deleted(): void
    {
        $this->makeEmployee('Budi', 'budi@example.com');
        $payroll = $this->generatePayroll();

        $this->actingAs($this->admin)->post(route('payrolls.adjustments.store', $payroll), [
            'name' => 'SPPD Surabaya',
            'type' => 'allowance',
            'amount' => 750000,
        ])->assertSessionHasNoErrors();

        $mealAllowance = PayrollDetail::where('payroll_id', $payroll->id)->where('name', 'Uang Makan')->firstOrFail();
        $this->actingAs($this->admin)->delete(route('payroll-details.destroy', $mealAllowance));

        $this->generatePayroll();

        $this->assertTrue(PayrollDetail::where('payroll_id', $payroll->id)->where('name', 'SPPD Surabaya')->exists());
        $this->assertFalse(PayrollDetail::where('payroll_id', $payroll->id)->where('name', 'Uang Makan')->exists());
    }

    #[Test]
    public function payroll_absence_matches_the_per_date_breakdown(): void
    {
        $user = $this->makeEmployee('Satpam', 'satpam@example.com');
        $offSchedule = WorkSchedule::where('code', 'off')->value('id');

        // Off on a Sunday (already a holiday) must not reduce work days.
        EmployeeSchedule::create(['employee_id' => $user->employee->id, 'work_schedule_id' => $offSchedule, 'work_date' => '2026-09-06']);
        // Working on a Sunday must not hide a weekday alfa.
        $this->attend($user, '2026-09-13');
        $this->attend($user, '2026-09-14');

        $payroll = $this->generatePayroll();
        $breakdown = app(PayrollAttendanceDateBreakdown::class)->forPayroll($payroll);

        $this->assertSame(26, (int) $payroll->work_days);
        $this->assertSame(count($breakdown['absent']), (int) $payroll->absent_days);
        $this->assertSame(25, (int) $payroll->absent_days);
    }

    #[Test]
    public function deleting_an_employee_whose_account_edited_a_payroll_succeeds(): void
    {
        $this->makeEmployee('Budi', 'budi@example.com');
        $hr = $this->makeEmployee('Hana HR', 'hana@example.com', ['employee', 'hr']);
        $payroll = $this->generatePayroll();

        $detail = PayrollDetail::where('payroll_id', $payroll->id)->where('name', 'Uang Makan')->firstOrFail();
        $this->actingAs($hr)->patch(route('payroll-details.update', $detail), [
            'name' => 'Uang Makan',
            'amount' => 12345.67,
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->admin)
            ->delete(route('employees.destroy', $hr->employee))
            ->assertRedirect(route('employees.index'));

        $this->assertNull(User::find($hr->id));
        $this->assertNull(PayrollDetailChange::firstOrFail()->user_id);
        $this->assertEquals('12345.67', $detail->fresh()->amount);
    }

    #[Test]
    public function editing_an_employee_keeps_their_admin_role(): void
    {
        $user = $this->makeEmployee('Budi', 'budi@example.com', ['employee', 'admin']);

        $this->actingAs($this->admin)->put(route('employees.update', $user->employee), [
            'name' => 'Budi Baru',
            'email' => 'budi@example.com',
        ])->assertSessionHasNoErrors();

        $this->assertTrue($user->fresh()->hasRole('admin'));
    }

    #[Test]
    public function work_hours_can_be_saved_with_zero_tolerance_late_limit(): void
    {
        $schedules = WorkSchedule::all()->keyBy('code');
        $payload = [
            'office_start' => '09:00', 'late_limit' => '09:15', 'clock_out_start' => '18:00', 'clock_out_limit' => '18:15',
            'security_clock_in_start' => '07:00', 'security_late_limit' => '07:15', 'security_clock_out_start' => '07:00',
            'ob_clock_in_start' => '07:30', 'ob_late_limit' => '07:30', 'ob_clock_out_start' => '16:00',
            'engineering_clock_in_start' => '00:00', 'engineering_late_limit' => '00:15', 'engineering_clock_out_start' => '05:00',
        ];

        $this->assertTrue($schedules->has('ob'));
        $this->actingAs($this->admin)
            ->patch(route('settings.work-hours.update'), $payload)
            ->assertSessionHasNoErrors();
    }

    #[Test]
    public function dashboard_counts_alfa_rows_as_absent(): void
    {
        $user = $this->makeEmployee('Budi', 'budi@example.com');
        Attendance::create([
            'user_id' => $user->id,
            'date' => '2026-09-28',
            'type' => AttendanceType::Regular,
            'status' => AttendanceStatus::Alpha,
        ]);

        $response = $this->actingAs($this->admin)->get(route('dashboard'))->assertOk();

        $this->assertSame(1, $response->viewData('absentToday'));
    }

    #[Test]
    public function failed_clock_in_keeps_hr_rejection_of_todays_leave(): void
    {
        $user = $this->makeEmployee('Budi', 'budi@example.com');
        $rejected = Attendance::create([
            'user_id' => $user->id,
            'date' => '2026-09-28',
            'type' => AttendanceType::Permission,
            'status' => AttendanceStatus::Permission,
            'verification_status' => VerificationStatus::NoDone,
            'rejection_reason' => 'Bukti tidak jelas',
        ]);

        $this->actingAs($user)->postJson(route('attendance.clock-in'), [
            'clock_in_report' => 'Laporan kerja harian untuk pengujian.',
            'face_descriptor' => array_fill(0, 128, 0.1),
            'faces_detected' => 1,
            'verification_photo' => 'data:image/jpeg;base64,AAAA',
            'latitude' => -7.25,   // Surabaya: outside the office radius
            'longitude' => 112.75,
        ]);

        $this->assertNotNull($rejected->fresh());
    }

    #[Test]
    public function doctor_note_link_without_a_note_is_not_found(): void
    {
        $user = $this->makeEmployee('Budi', 'budi@example.com');
        $attendance = $this->attend($user, '2026-09-25');

        $this->actingAs($user)->get(route('attendance.note.show', $attendance))->assertNotFound();
    }

    #[Test]
    public function attendance_api_rejects_impossible_dates_and_reports_leave_as_absent(): void
    {
        config(['services.attendance_api.token' => 'secret-token']);
        $user = $this->makeEmployee('Budi', 'budi@example.com');
        Attendance::create([
            'user_id' => $user->id,
            'date' => '2026-09-28',
            'type' => AttendanceType::Sick,
            'status' => AttendanceStatus::Sick,
            'verification_status' => VerificationStatus::Done,
        ]);

        $this->withToken('secret-token')->get('/api/attendance?date=2026-02-30')->assertStatus(422);

        $this->withToken('secret-token')
            ->getJson('/api/attendance/employee/'.$user->employee->employee_code.'?date=2026-09-28')
            ->assertOk()
            ->assertJsonPath('data.present', false);
    }

    #[Test]
    public function employee_record_without_any_role_gets_forbidden_instead_of_a_redirect_loop(): void
    {
        $user = $this->makeEmployee('Tanpa Peran', 'tanpa-peran@example.com', roles: []);

        $this->actingAs($user)->get(route('attendance.index'))->assertForbidden();
        // Other pages still send the user to their home page.
        $this->actingAs($user)->get(route('employees.index'))->assertRedirect(route('attendance.index'));
    }

    /**
     * @param  list<string>  $roles
     */
    private function makeEmployee(string $name, string $email, array $roles = ['employee']): User
    {
        $user = User::factory()->create(['name' => $name, 'email' => $email]);
        $user->roles()->attach(Role::whereIn('name', $roles)->pluck('id'));

        Employee::create([
            'user_id' => $user->id,
            'employee_code' => 'ID-'.str_pad((string) $user->id, 3, '0', STR_PAD_LEFT),
            'name' => $name,
            'email' => $email,
            'employment_status' => 'active',
            'basic_salary' => 3_000_000,
            'default_work_schedule_id' => WorkSchedule::where('code', 'regular')->value('id'),
        ]);

        return $user->fresh(['employee', 'roles']);
    }

    private function attend(User $user, string $date): Attendance
    {
        return Attendance::create([
            'user_id' => $user->id,
            'date' => $date,
            'type' => AttendanceType::Regular,
            'clock_in_time' => '08:55:00',
            'clock_out_time' => '18:05:00',
            'status' => AttendanceStatus::OnTime,
        ]);
    }

    private function generatePayroll(): Payroll
    {
        $this->actingAs($this->admin)->post(route('payrolls.generate'), [
            'period_month' => 9,
            'period_year' => 2026,
        ])->assertSessionHasNoErrors();

        return Payroll::firstOrFail();
    }
}
