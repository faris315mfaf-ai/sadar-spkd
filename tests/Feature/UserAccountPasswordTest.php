<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UserAccountPasswordTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
    }

    #[Test]
    public function superadmin_sets_a_new_password_and_signs_the_account_out_everywhere(): void
    {
        config(['session.driver' => 'database']);
        $employee = $this->makeUser('Budi', 'budi@example.com', ['employee'], withEmployee: true);
        $employee->forceFill(['remember_token' => 'old-remember-token'])->save();
        DB::table('sessions')->insert([
            'id' => 'budi-old-session',
            'user_id' => $employee->id,
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.accounts.password.edit', $employee))
            ->assertOk()
            ->assertSee('budi@example.com');

        $this->actingAs($this->admin)
            ->put(route('admin.accounts.password.update', $employee), [
                'password' => 'Baru2026x',
                'password_confirmation' => 'Baru2026x',
            ])
            ->assertRedirect(route('admin.accounts.index'))
            ->assertSessionHas('success');

        $employee->refresh();
        $this->assertTrue(Hash::check('Baru2026x', $employee->password));
        $this->assertNotSame('old-remember-token', $employee->remember_token);
        $this->assertDatabaseMissing('sessions', ['id' => 'budi-old-session']);
        $this->assertTrue(ActivityLog::where('user_id', $this->admin->id)
            ->where('description', 'like', '%Mengganti password akun Budi%')
            ->exists());
    }

    #[Test]
    public function the_list_shows_every_account_and_can_be_searched(): void
    {
        $this->makeUser('Budi', 'budi@example.com', ['employee'], withEmployee: true);
        $this->makeUser('Sari HR', 'sari@example.com', ['hr']);

        $this->actingAs($this->admin)->get(route('admin.accounts.index'))
            ->assertOk()
            ->assertSee('budi@example.com')
            ->assertSee('sari@example.com');

        $this->actingAs($this->admin)->get(route('admin.accounts.index', ['search' => 'sari']))
            ->assertOk()
            ->assertSee('sari@example.com')
            ->assertDontSee('budi@example.com');
    }

    #[Test]
    public function hr_and_employees_cannot_change_other_passwords(): void
    {
        $hr = $this->makeUser('Sari HR', 'sari@example.com', ['hr']);
        $employee = $this->makeUser('Budi', 'budi@example.com', ['employee'], withEmployee: true);
        $payload = ['password' => 'Ambil2026x', 'password_confirmation' => 'Ambil2026x'];

        foreach ([$hr, $employee] as $user) {
            $this->actingAs($user)->get(route('admin.accounts.index'))->assertRedirect();
            $this->actingAs($user)->put(route('admin.accounts.password.update', $this->admin), $payload)->assertRedirect();
        }

        $this->assertFalse(Hash::check('Ambil2026x', $this->admin->fresh()->password));
    }

    #[Test]
    public function weak_or_mismatched_passwords_are_rejected(): void
    {
        $employee = $this->makeUser('Budi', 'budi@example.com', ['employee'], withEmployee: true);
        $before = $employee->password;

        $this->actingAs($this->admin)
            ->put(route('admin.accounts.password.update', $employee), [
                'password' => 'pendek',
                'password_confirmation' => 'pendek',
            ])
            ->assertSessionHasErrors('password');

        $this->actingAs($this->admin)
            ->put(route('admin.accounts.password.update', $employee), [
                'password' => 'Baru2026x',
                'password_confirmation' => 'Lain2026x',
            ])
            ->assertSessionHasErrors('password');

        $this->assertSame($before, $employee->fresh()->password);
    }

    #[Test]
    public function own_password_is_changed_on_the_profile_page_instead(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.accounts.password.update', $this->admin), [
                'password' => 'Baru2026x',
                'password_confirmation' => 'Baru2026x',
            ])
            ->assertRedirect(route('profile.edit'));

        $this->assertFalse(Hash::check('Baru2026x', $this->admin->fresh()->password));
    }

    /**
     * @param  list<string>  $roles
     */
    private function makeUser(string $name, string $email, array $roles, bool $withEmployee = false): User
    {
        $user = User::factory()->create(['name' => $name, 'email' => $email]);
        $user->roles()->attach(Role::whereIn('name', $roles)->pluck('id'));

        if ($withEmployee) {
            Employee::create([
                'user_id' => $user->id,
                'employee_code' => 'ID-'.str_pad((string) $user->id, 3, '0', STR_PAD_LEFT),
                'name' => $name,
                'email' => $email,
                'employment_status' => 'active',
            ]);
        }

        return $user->fresh();
    }
}
