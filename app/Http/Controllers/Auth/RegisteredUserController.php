<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    /**
     * Creates an employee login, then continues to the biodata step of onboarding.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email', 'unique:employees,email'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ], [
            'email.unique' => 'Email ini sudah terdaftar. Silakan masuk atau gunakan email lain.',
            'password.confirmed' => 'Konfirmasi kata sandi tidak cocok.',
        ]);

        $user = DB::transaction(function () use ($validated) {
            $user = User::create([
                // Replaced by the full name on the biodata step.
                'name' => Str::before($validated['email'], '@'),
                'email' => $validated['email'],
                'password' => $validated['password'],
                'role' => 'employee',
            ]);

            $user->roles()->attach(Role::where('name', 'employee')->pluck('id'));

            return $user;
        });

        Auth::login($user);
        $request->session()->regenerate();

        ActivityLogService::log($user, 'create', "Mendaftar akun baru: {$user->email}", $user);

        return redirect()->route('onboarding.biodata');
    }
}
