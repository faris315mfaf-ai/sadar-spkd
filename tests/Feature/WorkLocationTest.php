<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\EmployeeSalaryComponent;
use App\Models\Role;
use App\Models\SalaryComponent;
use App\Models\User;
use App\Models\WorkLocation;
use App\Models\WorkSchedule;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WorkLocationTest extends TestCase
{
    use RefreshDatabase;

    private const OFFICE = [-6.2000, 106.8166];

    private const HOSPITAL = [-6.2600, 106.8100];   // about 6.7 km from the office

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Storage::fake('public');
        $this->travelTo(Carbon::parse('2026-09-28 08:55:00', 'Asia/Jakarta'));
        $this->seed(DatabaseSeeder::class);

        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
    }

    #[Test]
    public function admin_creates_a_location_for_chosen_employees(): void
    {
        $nurse = $this->makeEmployee('Suster Ani');

        $this->actingAs($this->admin)->post(route('settings.locations.store'), [
            'name' => 'RS Harapan',
            'latitude' => self::HOSPITAL[0],
            'longitude' => self::HOSPITAL[1],
            'radius_meters' => 300,
            'applies_to_all' => '0',
            'is_active' => '1',
            'employee_ids' => [$nurse->employee->id],
        ])->assertRedirect(route('settings.locations.index'));

        $location = WorkLocation::where('name', 'RS Harapan')->firstOrFail();
        $this->assertFalse($location->applies_to_all);
        $this->assertSame([$nurse->employee->id], $location->employees()->pluck('employees.id')->all());

        $this->actingAs($this->admin)->get(route('settings.locations.index'))
            ->assertOk()
            ->assertSee('RS Harapan')
            ->assertSee('1 karyawan tertentu');
    }

    #[Test]
    public function a_location_for_specific_employees_needs_at_least_one_employee(): void
    {
        $this->actingAs($this->admin)->post(route('settings.locations.store'), [
            'name' => 'RS Kosong',
            'latitude' => self::HOSPITAL[0],
            'longitude' => self::HOSPITAL[1],
            'radius_meters' => 300,
            'applies_to_all' => '0',
            'is_active' => '1',
        ])->assertSessionHasErrors('employee_ids');
    }

    #[Test]
    public function assigned_employees_can_clock_in_at_the_hospital_and_others_cannot(): void
    {
        $this->location('Kantor', self::OFFICE, 200, appliesToAll: true);
        $hospital = $this->location('RS Harapan', self::HOSPITAL, 300, appliesToAll: false);

        $nurse = $this->makeEmployee('Suster Ani');
        $hospital->employees()->attach($nurse->employee->id);
        $clerk = $this->makeEmployee('Staf Kantor');

        $this->actingAs($nurse)->postJson(route('attendance.clock-in'), $this->payload(self::HOSPITAL))->assertOk();
        $this->assertSame($hospital->id, Attendance::where('user_id', $nurse->id)->value('clock_in_work_location_id'));

        $this->actingAs($clerk)->postJson(route('attendance.clock-in'), $this->payload(self::HOSPITAL))
            ->assertStatus(422)
            ->assertJsonFragment(['success' => false]);
        $this->assertFalse(Attendance::where('user_id', $clerk->id)->exists());

        $this->actingAs($clerk)->postJson(route('attendance.clock-in'), $this->payload(self::OFFICE))->assertOk();
    }

    #[Test]
    public function inactive_locations_are_ignored(): void
    {
        $this->location('Kantor', self::OFFICE, 200, appliesToAll: true);
        $this->location('Gudang Lama', self::HOSPITAL, 300, appliesToAll: true, active: false);
        $clerk = $this->makeEmployee('Staf Kantor');

        $this->actingAs($clerk)->postJson(route('attendance.clock-in'), $this->payload(self::HOSPITAL))->assertStatus(422);
    }

    #[Test]
    public function the_old_single_location_page_redirects_to_the_list(): void
    {
        $this->actingAs($this->admin)->get('/settings/location')->assertRedirect('/settings/locations');
    }

    #[Test]
    public function payroll_is_hidden_when_the_feature_is_off(): void
    {
        config(['features.payroll' => false]);
        $employee = $this->makeEmployee('Staf Kantor');

        $this->actingAs($this->admin)->get(route('payrolls.index'))->assertNotFound();
        $this->actingAs($employee)->get(route('my-payrolls.index'))->assertNotFound();

        $this->actingAs($this->admin)->get(route('employees.index'))
            ->assertOk()
            ->assertDontSee('Penggajian')
            ->assertDontSee('Gaji gross');
    }

    #[Test]
    public function editing_an_employee_with_payroll_hidden_keeps_salary_data(): void
    {
        config(['features.payroll' => false]);
        $user = $this->makeEmployee('Staf Kantor');
        $employee = $user->employee;
        $employee->update(['basic_salary' => 4_500_000]);
        $component = SalaryComponent::firstOrFail();
        EmployeeSalaryComponent::create([
            'employee_id' => $employee->id,
            'salary_component_id' => $component->id,
            'amount' => 250_000,
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)->put(route('employees.update', $employee), [
            'name' => 'Staf Kantor Baru',
            'email' => $user->email,
        ])->assertSessionHasNoErrors();

        $this->assertEquals(4_500_000, $employee->fresh()->basic_salary);
        $this->assertSame(1, EmployeeSalaryComponent::where('employee_id', $employee->id)->count());
    }

    private function location(string $name, array $point, int $radius, bool $appliesToAll, bool $active = true): WorkLocation
    {
        return WorkLocation::create([
            'name' => $name,
            'latitude' => $point[0],
            'longitude' => $point[1],
            'radius_meters' => $radius,
            'applies_to_all' => $appliesToAll,
            'is_active' => $active,
        ]);
    }

    private function makeEmployee(string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        $user->roles()->attach(Role::where('name', 'employee')->firstOrFail());

        Storage::disk('public')->put("employee-photos/{$user->id}.jpg", $this->jpeg());

        Employee::create([
            'user_id' => $user->id,
            'employee_code' => 'ID-'.str_pad((string) $user->id, 3, '0', STR_PAD_LEFT),
            'name' => $name,
            'email' => $user->email,
            'profile_photo' => "employee-photos/{$user->id}.jpg",
            'face_descriptor' => json_encode(array_fill(0, 128, 0.1)),
            'employment_status' => 'active',
            'default_work_schedule_id' => WorkSchedule::where('code', 'regular')->value('id'),
        ]);

        return $user->fresh(['employee', 'roles']);
    }

    private function payload(array $point): array
    {
        return [
            'clock_in_report' => 'Rencana kerja hari ini untuk pengujian.',
            'face_descriptor' => array_fill(0, 128, 0.1),
            'faces_detected' => 1,
            'verification_photo' => 'data:image/jpeg;base64,'.base64_encode($this->jpeg()),
            'latitude' => $point[0],
            'longitude' => $point[1],
            'accuracy' => 10,
            'device_type' => 'mobile',
            'client_time' => now()->timestamp,
        ];
    }

    private function jpeg(): string
    {
        $image = imagecreatetruecolor(320, 240);
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }
}
