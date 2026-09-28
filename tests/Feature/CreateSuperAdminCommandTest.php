<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CreateSuperAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    #[Test]
    public function it_creates_user_with_admin_and_hr_roles(): void
    {
        $this->artisan('user:create-superadmin', [
            '--email' => 'boss@example.com',
            '--password' => 'rahasia-panjang',
        ])->assertSuccessful();

        $user = User::where('email', 'boss@example.com')->firstOrFail();

        $this->assertTrue(Hash::check('rahasia-panjang', $user->password));
        $this->assertTrue($user->hasRole('admin'));
        $this->assertTrue($user->hasRole('hr'));
        $this->assertNotNull($user->email_verified_at);
    }

    #[Test]
    public function it_generates_password_when_not_given(): void
    {
        $this->artisan('user:create-superadmin', ['--email' => 'boss@example.com'])
            ->expectsOutputToContain('Password :')
            ->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'boss@example.com', 'role' => 'admin']);
    }

    #[Test]
    public function it_updates_existing_user_without_duplicating_roles(): void
    {
        $this->artisan('user:create-superadmin', ['--email' => 'boss@example.com', '--password' => 'password-lama'])
            ->assertSuccessful();
        $this->artisan('user:create-superadmin', ['--email' => 'boss@example.com', '--password' => 'password-baru'])
            ->assertSuccessful();

        $user = User::where('email', 'boss@example.com')->firstOrFail();

        $this->assertSame(1, User::where('email', 'boss@example.com')->count());
        $this->assertCount(2, $user->roles);
        $this->assertTrue(Hash::check('password-baru', $user->password));
    }

    #[Test]
    public function it_rejects_short_password(): void
    {
        $this->artisan('user:create-superadmin', ['--email' => 'boss@example.com', '--password' => 'pendek'])
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'boss@example.com']);
    }
}
