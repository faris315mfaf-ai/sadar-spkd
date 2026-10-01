<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Superadmin (admin role) sets a new password for any other account,
 * e.g. when an employee forgot theirs or still uses the default one.
 * HR has no access: it could otherwise take over the admin account.
 */
class UserAccountController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->input('search', ''));

        $users = User::query()
            ->with(['roles', 'employee'])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhereHas('employee', fn ($employee) => $employee->where('employee_code', 'like', "%{$search}%"));
                });
            })
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('admin.accounts.index', [
            'users' => $users,
            'search' => $search,
        ]);
    }

    public function editPassword(Request $request, User $user): View|RedirectResponse
    {
        if ($user->is($request->user())) {
            return $this->redirectToOwnProfile();
        }

        return view('admin.accounts.password', [
            'account' => $user->load(['roles', 'employee']),
        ]);
    }

    public function updatePassword(Request $request, User $user): RedirectResponse
    {
        if ($user->is($request->user())) {
            return $this->redirectToOwnProfile();
        }

        $validated = $request->validate([
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ], [
            'password.required' => 'Password baru wajib diisi.',
            'password.confirmed' => 'Konfirmasi password tidak sama.',
            'password.min' => 'Password minimal 8 karakter.',
            'password.letters' => 'Password harus mengandung huruf.',
            'password.numbers' => 'Password harus mengandung angka.',
        ]);

        // A new remember token also invalidates "ingat saya" cookies on the user's devices.
        $user->forceFill([
            'password' => Hash::make($validated['password']),
            'remember_token' => Str::random(60),
        ])->save();

        $this->endSessionsOf($user);

        ActivityLogService::log(
            $request->user(),
            'update',
            "Mengganti password akun {$user->name} ({$user->email})",
            $user,
        );

        return redirect()
            ->route('admin.accounts.index')
            ->with('success', "Password {$user->name} berhasil diganti. Akun tersebut sudah dikeluarkan dari semua perangkat.");
    }

    /**
     * Someone who knew the old password may still be logged in; sign every device out.
     */
    private function endSessionsOf(User $user): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->delete();
    }

    private function redirectToOwnProfile(): RedirectResponse
    {
        return redirect()
            ->route('profile.edit')
            ->with('error', 'Password akun Anda sendiri diganti di halaman Profil (perlu password lama).');
    }
}
