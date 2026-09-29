<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\WorkSchedule;
use App\Services\ActivityLogService;
use App\Services\AttendanceVerificationPhotoService;
use App\Services\EmployeeFaceDescriptorService;
use App\Services\FaceVerificationService;
use App\Services\WorkLocationService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * First-run steps after sign-up: biodata → face registration → location check.
 */
class OnboardingController extends Controller
{
    public function __construct(
        private readonly AttendanceVerificationPhotoService $photos,
        private readonly EmployeeFaceDescriptorService $faceDescriptors,
        private readonly FaceVerificationService $faceVerification,
        private readonly WorkLocationService $workLocations,
    ) {}

    public function biodata(Request $request): View|RedirectResponse
    {
        if ($request->user()->employee) {
            return redirect()->route('onboarding.face');
        }

        return view('onboarding.biodata');
    }

    public function storeBiodata(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->employee) {
            return redirect()->route('onboarding.face');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // Typed by hand for now; there is no fixed division list.
            'staff' => ['required', 'string', 'max:100'],
            'nik' => ['required', 'digits:16', 'unique:employees,nik'],
        ], [
            'name.required' => 'Nama lengkap wajib diisi.',
            'staff.required' => 'Divisi wajib diisi.',
            'nik.required' => 'NIK wajib diisi.',
            'nik.digits' => 'NIK harus 16 digit angka sesuai KTP.',
            'nik.unique' => 'NIK ini sudah terdaftar. Hubungi HR jika ini data Anda.',
        ]);

        try {
            $employee = DB::transaction(function () use ($user, $validated) {
                $user->update(['name' => $validated['name']]);

                return Employee::create([
                    'user_id' => $user->id,
                    'employee_code' => Employee::generateCode(),
                    'name' => $validated['name'],
                    'nik' => $validated['nik'],
                    'staff' => $validated['staff'],
                    'email' => $user->email,
                    'employment_status' => 'active',
                    'default_work_schedule_id' => WorkSchedule::defaultIdFor($validated['staff']),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // Two submits at once, or another sign-up took the same employee code.
            return back()->withInput()->with('error', 'Biodata gagal disimpan. Silakan coba lagi.');
        }

        ActivityLogService::log($user, 'create', "Mengisi biodata karyawan baru: {$employee->name} ({$employee->staff})", $employee);

        return redirect()->route('onboarding.face');
    }

    public function face(Request $request): View|RedirectResponse
    {
        $employee = $request->user()->employee;

        if (! $employee) {
            return redirect()->route('onboarding.biodata');
        }

        if ($employee->hasProfilePhoto()) {
            return redirect()->route('onboarding.location');
        }

        return view('onboarding.face', ['employee' => $employee]);
    }

    public function storeFace(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;

        if (! $employee) {
            return response()->json(['message' => 'Isi biodata terlebih dahulu.'], 409);
        }

        // Registered once; a new face afterwards is HR's call (Data Karyawan → ganti foto).
        if ($employee->hasProfilePhoto()) {
            return response()->json([
                'message' => 'Wajah Anda sudah terdaftar. Hubungi HR untuk menggantinya.',
                'redirect' => route('onboarding.location'),
            ], 409);
        }

        $validated = $request->validate([
            'photo' => ['required', 'string', 'max:8000000'],
            'face_descriptor' => ['required', 'array', 'size:'.$this->faceVerification->descriptorLength()],
            'face_descriptor.*' => ['numeric'],
            'faces_detected' => ['required', 'integer', 'in:1'],
        ], [
            'faces_detected.in' => 'Pastikan hanya satu wajah yang terlihat di kamera.',
        ]);

        $path = $this->photos->storeFaceRegistrationBase64($validated['photo'], $request->user()->id);

        if ($path === null) {
            return response()->json(['message' => 'Foto tidak valid. Silakan ambil ulang foto wajah Anda.'], 422);
        }

        try {
            DB::transaction(function () use ($employee, $path, $validated) {
                $employee->update(['profile_photo' => $path]);
                $this->faceDescriptors->save($employee, $validated['face_descriptor']);
            });
        } catch (\InvalidArgumentException $exception) {
            $this->photos->deleteIfExists($path);

            return response()->json(['message' => $exception->getMessage()], 422);
        }

        ActivityLogService::log($request->user(), 'update', "Mendaftarkan wajah: {$employee->name}", $employee);

        return response()->json([
            'message' => 'Wajah berhasil didaftarkan.',
            'redirect' => route('onboarding.location'),
        ]);
    }

    public function location(Request $request): View|RedirectResponse
    {
        $employee = $request->user()->employee;

        if (! $employee) {
            return redirect()->route('onboarding.biodata');
        }

        if (! $employee->hasProfilePhoto()) {
            return redirect()->route('onboarding.face');
        }

        $locations = $this->workLocations->availableTo($employee);

        return view('onboarding.location', [
            'locations' => $locations,
            'places' => $this->workLocations->mapPoints($locations),
        ]);
    }
}
