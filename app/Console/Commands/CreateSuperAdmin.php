<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CreateSuperAdmin extends Command
{
    protected $signature = 'user:create-superadmin
                            {--email= : Email untuk login}
                            {--name=Super Admin : Nama yang tampil di aplikasi}
                            {--password= : Kosongkan untuk dibuatkan password acak}';

    protected $description = 'Buat atau perbarui akun dengan akses penuh (role admin + hr)';

    public function handle(): int
    {
        $email = (string) ($this->option('email') ?: $this->ask('Email'));
        $name = (string) $this->option('name');
        $password = (string) ($this->option('password') ?: Str::password(16, symbols: false));
        $generated = ! $this->option('password');

        $validator = Validator::make(
            ['email' => $email, 'name' => $name, 'password' => $password],
            [
                'email' => ['required', 'email'],
                'name' => ['required', 'string', 'max:255'],
                'password' => ['required', 'string', 'min:8'],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $roleIds = Role::query()->whereIn('name', ['admin', 'hr'])->pluck('id');

        if ($roleIds->count() < 2) {
            $this->error('Role admin/hr belum ada. Jalankan: php artisan db:seed --class=RoleSeeder');

            return self::FAILURE;
        }

        $user = User::firstOrNew(['email' => $email]);
        $isNew = ! $user->exists;

        $user->fill([
            'name' => $name,
            'password' => $password,
            'role' => 'admin',
        ]);
        $user->email_verified_at ??= now();
        $user->save();

        $user->roles()->syncWithoutDetaching($roleIds);

        $this->info($isNew ? 'Akun superadmin dibuat.' : 'Akun superadmin diperbarui.');
        $this->line("Email    : {$email}");

        if ($generated) {
            $this->line("Password : {$password}");
            $this->warn('Simpan password ini, lalu ganti lewat menu Profile setelah login.');
        }

        return self::SUCCESS;
    }
}
