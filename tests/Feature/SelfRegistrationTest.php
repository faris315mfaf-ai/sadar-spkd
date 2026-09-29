<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use App\Models\WorkSchedule;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SelfRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->seed(DatabaseSeeder::class);
    }

    #[Test]
    public function login_page_links_to_sign_up_and_sign_up_page_renders(): void
    {
        $this->get(route('login'))->assertOk()->assertSee(route('register'), false);
        $this->get(route('register'))->assertOk()->assertSee('Buat akun Anda');
    }

    #[Test]
    public function signing_up_logs_in_and_continues_to_biodata(): void
    {
        $this->post(route('register'), [
            'email' => 'budi@example.com',
            'password' => 'rahasia123',
            'password_confirmation' => 'rahasia123',
        ])->assertRedirect(route('onboarding.biodata'));

        $user = User::where('email', 'budi@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertTrue($user->hasRole('employee'));
        $this->assertFalse($user->isAdmin());
    }

    #[Test]
    public function sign_up_rejects_taken_email_and_weak_password(): void
    {
        $this->post(route('register'), [
            'email' => 'admin@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasErrors(['email', 'password']);

        $this->assertGuest();
    }

    #[Test]
    public function biodata_creates_the_employee_and_picks_the_division_schedule(): void
    {
        $user = $this->signUp();

        $this->actingAs($user)->post(route('onboarding.biodata.store'), [
            'name' => 'Budi Santoso',
            'staff' => 'Security',
            'nik' => '3174012345678901',
        ])->assertRedirect(route('onboarding.face'));

        $employee = $user->fresh()->employee;
        $this->assertSame('Budi Santoso', $employee->name);
        $this->assertSame('Budi Santoso', $user->fresh()->name);
        $this->assertSame('3174012345678901', $employee->nik);
        $this->assertSame('budi@example.com', $employee->email);
        $this->assertSame('active', $employee->employment_status);
        $this->assertSame(WorkSchedule::where('code', 'security')->value('id'), $employee->default_work_schedule_id);
        $this->assertMatchesRegularExpression('/^ID-\d{3}$/', $employee->employee_code);
    }

    #[Test]
    public function biodata_accepts_any_typed_division(): void
    {
        $user = $this->signUp();

        $this->actingAs($user)->post(route('onboarding.biodata.store'), [
            'name' => 'Siti Aminah',
            'staff' => 'Keuangan',
            'nik' => '3174012345678902',
        ])->assertRedirect(route('onboarding.face'));

        $employee = $user->fresh()->employee;
        $this->assertSame('Keuangan', $employee->staff);
        $this->assertSame(WorkSchedule::where('code', 'regular')->value('id'), $employee->default_work_schedule_id);
    }

    #[Test]
    public function biodata_validates_nik_and_division(): void
    {
        $user = $this->signUp();

        $this->actingAs($user)->post(route('onboarding.biodata.store'), [
            'name' => 'Budi',
            'staff' => '',
            'nik' => '12345',
        ])->assertSessionHasErrors(['staff', 'nik']);

        $other = $this->signUp('siti@example.com');
        Employee::create(['employee_code' => 'ID-900', 'name' => 'Lama', 'nik' => '3174012345678901']);

        $this->actingAs($other)->post(route('onboarding.biodata.store'), [
            'name' => 'Siti',
            'staff' => 'IT',
            'nik' => '3174012345678901',
        ])->assertSessionHasErrors(['nik']);

        $this->assertNull($user->fresh()->employee);
    }

    #[Test]
    public function face_registration_saves_photo_and_descriptor_only_once(): void
    {
        $user = $this->signUpWithBiodata();
        $payload = [
            'photo' => 'data:image/jpeg;base64,'.base64_encode($this->jpeg()),
            'face_descriptor' => array_fill(0, 128, 0.1),
            'faces_detected' => 1,
        ];

        $this->actingAs($user)->postJson(route('onboarding.face.store'), $payload)
            ->assertOk()
            ->assertJsonPath('redirect', route('onboarding.location'));

        $employee = $user->fresh()->employee;
        $this->assertTrue($employee->canVerifyFace());
        Storage::disk('public')->assertExists($employee->profile_photo);

        $this->actingAs($user)->postJson(route('onboarding.face.store'), $payload)->assertStatus(409);
    }

    #[Test]
    public function face_registration_rejects_a_non_image_and_two_faces(): void
    {
        $user = $this->signUpWithBiodata();

        $this->actingAs($user)->postJson(route('onboarding.face.store'), [
            'photo' => 'data:image/jpeg;base64,'.base64_encode('not an image'),
            'face_descriptor' => array_fill(0, 128, 0.1),
            'faces_detected' => 1,
        ])->assertStatus(422);

        $this->actingAs($user)->postJson(route('onboarding.face.store'), [
            'photo' => 'data:image/jpeg;base64,'.base64_encode($this->jpeg()),
            'face_descriptor' => array_fill(0, 128, 0.1),
            'faces_detected' => 2,
        ])->assertStatus(422)->assertJsonValidationErrors(['faces_detected']);

        $this->assertNull($user->fresh()->employee->profile_photo);
    }

    #[Test]
    public function unfinished_sign_ups_are_sent_back_to_their_step(): void
    {
        $user = $this->signUp();

        $this->actingAs($user)->get(route('attendance.index'))->assertRedirect(route('onboarding.biodata'));
        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('onboarding.biodata'));
        $this->actingAs($user)->get(route('onboarding.face'))->assertRedirect(route('onboarding.biodata'));

        $user = $this->signUpWithBiodata('siti@example.com');

        // Face registration is offered, not forced: the attendance page stays reachable (e.g. to report sick).
        $this->actingAs($user)->get(route('attendance.index'))->assertOk();
        $this->actingAs($user)->get(route('onboarding.biodata'))->assertRedirect(route('onboarding.face'));
        $this->actingAs($user)->get(route('onboarding.location'))->assertRedirect(route('onboarding.face'));
        $this->actingAs($user)->get(route('onboarding.face'))->assertOk()->assertSee('Aktifkan Kamera');
    }

    #[Test]
    public function finished_sign_up_reaches_location_check_and_attendance(): void
    {
        $user = $this->signUpWithBiodata();
        $user->employee->update(['profile_photo' => 'face-registration/user-'.$user->id.'.jpg', 'face_descriptor' => json_encode(array_fill(0, 128, 0.1))]);
        Storage::disk('public')->put($user->employee->profile_photo, $this->jpeg());

        $this->actingAs($user)->get(route('onboarding.face'))->assertRedirect(route('onboarding.location'));
        $this->actingAs($user)->get(route('onboarding.location'))->assertOk()->assertSee('Cek Lokasi Saya');
        $this->actingAs($user)->get(route('attendance.index'))->assertOk();
    }

    #[Test]
    public function logging_in_before_biodata_resumes_onboarding(): void
    {
        $this->signUp();
        auth()->logout();

        $this->post(route('login'), [
            'email' => 'budi@example.com',
            'password' => 'rahasia123',
        ])->assertRedirect(route('onboarding.biodata'));
    }

    private function signUp(string $email = 'budi@example.com'): User
    {
        // Sign-up is guest-only; drop any user an earlier step acted as.
        auth()->logout();

        $this->post(route('register'), [
            'email' => $email,
            'password' => 'rahasia123',
            'password_confirmation' => 'rahasia123',
        ]);
        auth()->logout();

        return User::where('email', $email)->firstOrFail();
    }

    private function signUpWithBiodata(string $email = 'budi@example.com'): User
    {
        $user = $this->signUp($email);

        $this->actingAs($user)->post(route('onboarding.biodata.store'), [
            'name' => 'Karyawan '.$user->id,
            'staff' => 'IT',
            'nik' => str_pad((string) $user->id, 16, '9', STR_PAD_LEFT),
        ])->assertSessionHasNoErrors();

        return $user->fresh('employee');
    }

    private function jpeg(): string
    {
        $image = imagecreatetruecolor(320, 240);
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }
}
