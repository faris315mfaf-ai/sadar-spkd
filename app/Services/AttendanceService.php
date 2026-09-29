<?php

namespace App\Services;

use App\DTOs\ShiftSchedule;
use App\DTOs\VerificationPhotoOverlay;
use App\Enums\AttendanceShift;
use App\Enums\AttendanceStatus;
use App\Enums\AttendanceType;
use App\Enums\VerificationStatus;
use App\Enums\WorkCalendarType;
use App\Models\Attendance;
use App\Models\User;
use App\Models\WorkLocation;
use App\Support\AppTime;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AttendanceService
{
    public function __construct(
        private readonly DoctorNoteService $doctorNotes,
        private readonly FaceVerificationService $faceVerification,
        private readonly AttendanceVerificationPhotoService $verificationPhotos,
        private readonly AttendanceLocationService $locations,
        private readonly WorkCalendarService $workCalendar,
        private readonly EmployeeScheduleService $employeeSchedules,
        private readonly MarkAbsentAlphaService $markAbsentAlpha,
        private readonly WorkLocationService $workLocations,
    ) {}

    public function todayFor(User $user): ?Attendance
    {
        return Attendance::with('verifiedBy')->forUserOnDate($user->id, AppTime::today())->first();
    }

    public function pendingClockOutFor(User $user): ?Attendance
    {
        $today = $this->todayFor($user);

        if ($today?->isRegular() && $today->clock_in_time && ! $today->clock_out_time) {
            return $today;
        }

        return $this->openSecurityAttendancePending($user);
    }

    public function clockOutTargetFor(User $user): ?Attendance
    {
        $pending = $this->pendingClockOutFor($user);

        if ($pending === null) {
            return null;
        }

        $schedule = $this->clockOutScheduleFor($pending);

        if ($schedule->isEarlyClockOut(AppTime::now(), $pending->date)) {
            return null;
        }

        return $pending;
    }

    public function clockOutContextFor(User $user): array
    {
        $attendance = $this->pendingClockOutFor($user);

        return [
            ...$this->clockOutContext($attendance),
            'attendance' => $attendance,
        ];
    }

    public function historyFor(User $user, int $limit = 7)
    {
        return Attendance::forUser($user->id)
            ->whereDate('date', '<', AppTime::today())
            ->orderByDesc('date')
            ->limit($limit)
            ->get();
    }

    public function paginatedHistoryFor(
        User $user,
        ?string $month = null,
        ?AttendanceType $type = null,
        ?AttendanceStatus $status = null,
        int $perPage = 15,
        ?string $date = null,
    ) {
        $query = Attendance::with('verifiedBy')->forUser($user->id)->orderByDesc('date');

        if ($date) {
            $query->whereDate('date', $date);
        } elseif ($month) {
            [$year, $monthNumber] = array_map('intval', explode('-', $month));
            $query->whereYear('date', $year)->whereMonth('date', $monthNumber);
        }

        if ($type) {
            $query->where('type', $type);
        }

        if ($status) {
            $query->where('status', $status);
        }

        return $query->paginate($perPage)->withQueryString();
    }

    public function monthlyStatisticsFor(
        User $user,
        ?string $month = null,
        ?AttendanceType $type = null,
        ?AttendanceStatus $status = null,
        ?string $date = null,
    ): array {
        $month = filled($month) ? $month : null;
        $date = filled($date) ? $date : null;

        $query = Attendance::forUser($user->id);

        if ($date) {
            $query->whereDate('date', $date);
        } elseif ($month) {
            [$year, $monthNumber] = array_map('intval', explode('-', $month));
            $query->whereYear('date', $year)->whereMonth('date', $monthNumber);
        }

        if ($type) {
            $query->where('type', $type);
        }

        if ($status) {
            $query->where('status', $status);
        }

        $attendances = $query->get();

        $statistics = [
            'hadir' => 0,
            'telat' => 0,
            'izin' => 0,
            'sakit' => 0,
            'alpha' => 0,
        ];

        foreach ($attendances as $attendance) {
            if ($attendance->isLeave()) {
                if ($attendance->isRejectedLeave()) {
                    match ($attendance->type) {
                        AttendanceType::Sick => $statistics['sakit']++,
                        AttendanceType::Permission => $statistics['alpha']++,
                        default => null,
                    };

                    continue;
                }

                if ($attendance->isPendingLeave()) {
                    continue;
                }

                match ($attendance->status) {
                    AttendanceStatus::Permission => $statistics['izin']++,
                    AttendanceStatus::Sick => $statistics['sakit']++,
                    default => null,
                };

                continue;
            }

            match ($attendance->status) {
                AttendanceStatus::OnTime, AttendanceStatus::LateOut => $statistics['hadir']++,
                AttendanceStatus::Late, AttendanceStatus::EarlyOut => $statistics['telat']++,
                AttendanceStatus::Permission => $statistics['izin']++,
                AttendanceStatus::Sick => $statistics['sakit']++,
                AttendanceStatus::Alpha => $statistics['alpha']++,
                default => null,
            };
        }

        return $statistics;
    }

    public function clockIn(
        User $user,
        string $report,
        array $faceDescriptor,
        int $facesDetected,
        string $verificationPhotoBase64,
        ?float $latitude = null,
        ?float $longitude = null,
        ?string $attendanceLocation = null,
        ?float $accuracy = null,
        ?string $deviceType = null,
        ?int $clientTime = null,
    ): ActionResult {
        $rejectedLeave = null;

        if ($existing = $this->todayFor($user)) {
            if ($existing->isRejectedLeave()) {
                // Removed only once clock-in succeeds, so a failed attempt keeps HR's rejection record.
                $rejectedLeave = $existing;
            } elseif ($existing->status === AttendanceStatus::Alpha) {
                return ActionResult::fail(
                    'Hari ini sudah tercatat alfa. Hubungi HR jika perlu koreksi.'
                );
            } elseif ($existing->isLeave()) {
                return ActionResult::fail(
                    'Anda sudah mengajukan absen izin/sakit hari ini.'
                );
            } else {
                return ActionResult::fail('Anda sudah melakukan absen masuk hari ini.');
            }
        }

        $employee = $user->employee;

        if (! $employee) {
            return ActionResult::fail('Data karyawan tidak ditemukan.');
        }

        $workSchedule = $this->employeeSchedules->getTodaySchedule($employee);

        if ($workSchedule->is_off) {
            return ActionResult::fail('Hari ini adalah hari libur Anda. Absensi tidak diperlukan.');
        }

        $now = AppTime::now();

        if (
            $now->format('H:i:s') >= '23:00:00'
            && $this->markAbsentAlpha->isRegularDaySchedule($employee, $workSchedule)
        ) {
            return ActionResult::fail('Absen masuk jam reguler ditutup setelah pukul 23:00.');
        }

        if ($faceError = $this->validateFace($user, $faceDescriptor, $facesDetected)) {
            return ActionResult::fail($faceError);
        }

        if ($locationError = $this->validateLocation($latitude, $longitude)) {
            return ActionResult::fail($locationError);
        }

        $place = $this->locateWorkplace($user, $latitude, $longitude);

        if ($geofenceError = $this->geofenceError($place)) {
            return ActionResult::fail($geofenceError);
        }

        $faceResult = $this->faceVerification->verifyForUser($user, $faceDescriptor, $facesDetected);

        if (! $faceResult['success']) {
            return ActionResult::fail($faceResult['message']);
        }

        $shift = AttendanceShift::Day;
        $schedule = $workSchedule->toShiftSchedule();
        $status = $schedule->resolveClockInStatus($now);
        $attendanceDate = AppTime::today();
        $resolvedLocation = $this->locations->resolveAddress($latitude, $longitude, $attendanceLocation);

        $photoPath = $this->verificationPhotos->storeBase64WithOverlay(
            $verificationPhotoBase64,
            'clock-in-'.$user->id,
            new VerificationPhotoOverlay(
                time: $now->format('H:i'),
                dateLabel: $attendanceDate->translatedFormat('d F Y'),
                dayLabel: $attendanceDate->translatedFormat('l'),
                address: $resolvedLocation ?? 'Lokasi tidak tersedia',
            ),
        );

        $anomaly = $this->resolveAnomalyStatus($place, $accuracy, $deviceType, $clientTime);

        try {
            DB::transaction(function () use (
                $rejectedLeave, $user, $attendanceDate, $shift, $workSchedule, $now, $report, $latitude, $place,
                $longitude, $resolvedLocation, $photoPath, $faceResult, $status, $accuracy, $deviceType, $anomaly,
            ): void {
                $rejectedLeave?->delete();

                Attendance::create([
                    'user_id' => $user->id,
                    'date' => $attendanceDate,
                    'type' => AttendanceType::Regular,
                    'shift' => $shift,
                    'work_schedule_id' => $workSchedule->id,
                    'clock_in_time' => $now->format('H:i:s'),
                    'clock_in_report' => $report,
                    'clock_in_latitude' => $latitude,
                    'clock_in_longitude' => $longitude,
                    'clock_in_location' => $resolvedLocation,
                    'clock_in_verification_photo' => $photoPath,
                    'clock_in_face_distance' => $faceResult['distance'] ?? null,
                    'clock_in_work_location_id' => $place['match']?->id,
                    'status' => $status,
                    'accuracy' => $accuracy,
                    'device_type' => $deviceType,
                    'validation_status' => $anomaly['status'],
                    'suspicious_reason' => $anomaly['reason'],
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // A second tap submitted while the first request was still processing.
            $this->verificationPhotos->deleteIfExists($photoPath);

            return ActionResult::fail('Anda sudah melakukan absen masuk hari ini.');
        }

        $shiftLabel = $shift->label();
        $statusNote = $status === AttendanceStatus::Late ? ' Status: Telat.' : '';

        return ActionResult::ok("Absen masuk berhasil ({$shiftLabel}).{$statusNote} Selamat bekerja!");
    }

    public function clockOut(
        User $user,
        string $report,
        array $faceDescriptor,
        int $facesDetected,
        string $verificationPhotoBase64,
        ?float $latitude = null,
        ?float $longitude = null,
        ?string $attendanceLocation = null,
        ?float $accuracy = null,
        ?string $deviceType = null,
        ?int $clientTime = null,
    ): ActionResult {
        $attendance = $this->clockOutTargetFor($user);

        if (! $attendance?->isRegular()) {
            return ActionResult::fail('Absen pulang hanya untuk absensi reguler hari ini.');
        }

        if (! $attendance->clock_in_time) {
            return ActionResult::fail('Anda belum melakukan absen masuk hari ini.');
        }

        if ($attendance->clock_out_time) {
            return ActionResult::fail('Anda sudah melakukan absen pulang hari ini.');
        }

        $employee = $user->employee;

        if (
            $employee
            && $attendance->date->isSameDay(AppTime::today())
            && $this->employeeSchedules->isOffDay($employee, AppTime::today())
        ) {
            return ActionResult::fail('Hari ini adalah hari libur Anda. Absensi pulang tidak diperlukan.');
        }

        if ($faceError = $this->validateFace($user, $faceDescriptor, $facesDetected)) {
            return ActionResult::fail($faceError);
        }

        if ($locationError = $this->validateLocation($latitude, $longitude)) {
            return ActionResult::fail($locationError);
        }

        $place = $this->locateWorkplace($user, $latitude, $longitude);

        if ($geofenceError = $this->geofenceError($place)) {
            return ActionResult::fail($geofenceError);
        }

        $faceResult = $this->faceVerification->verifyForUser($user, $faceDescriptor, $facesDetected);

        if (! $faceResult['success']) {
            return ActionResult::fail($faceResult['message']);
        }

        $now = AppTime::now();
        $schedule = $this->clockOutScheduleFor($attendance);

        if ($schedule->isEarlyClockOut($now, $attendance->date)) {
            return ActionResult::fail(
                'Belum waktunya absen pulang. Mulai jam '.$schedule->formattedClockOutStart().
                ' (rentang: '.$schedule->clockOutWindowDescription().').'
            );
        }

        $status = $schedule->resolveClockOutStatus($now, $attendance->status, $attendance->date);

        $overtimeHours = $this->overtimeHoursFor($attendance, $now);
        $resolvedLocation = $this->locations->resolveAddress($latitude, $longitude, $attendanceLocation);

        $photoPath = $this->verificationPhotos->storeBase64WithOverlay(
            $verificationPhotoBase64,
            'clock-out-'.$user->id,
            new VerificationPhotoOverlay(
                time: $now->format('H:i'),
                dateLabel: $now->translatedFormat('d F Y'),
                dayLabel: $now->translatedFormat('l'),
                address: $resolvedLocation ?? 'Lokasi tidak tersedia',
            ),
        );

        $anomaly = $this->resolveAnomalyStatus($place, $accuracy, $deviceType, $clientTime);

        $attendance->update([
            'clock_out_time' => $now->format('H:i:s'),
            'overtime_hours' => $overtimeHours,
            'clock_out_report' => $report,
            'clock_out_latitude' => $latitude,
            'clock_out_longitude' => $longitude,
            'clock_out_location' => $resolvedLocation,
            'clock_out_verification_photo' => $photoPath,
            'clock_out_face_distance' => $faceResult['distance'] ?? null,
            'clock_out_work_location_id' => $place['match']?->id,
            'status' => $status,
            'accuracy' => $accuracy,
            'device_type' => $deviceType,
            'validation_status' => $anomaly['status'],
            'suspicious_reason' => $anomaly['reason'],
        ]);

        return ActionResult::ok(
            $status === AttendanceStatus::LateOut
                ? 'Absen pulang berhasil. Status: Pulang Lewat.'
                : 'Absen pulang berhasil. Sampai jumpa!'
        );
    }

    public function submitLeave(
        User $user,
        AttendanceType $type,
        string $note,
        ?UploadedFile $doctorNote = null,
        ?float $latitude = null,
        ?float $longitude = null,
        ?float $accuracy = null,
    ): ActionResult {
        $existingToday = $this->todayFor($user);

        if ($existingToday && ! $existingToday->isRejectedLeave()) {
            return ActionResult::fail('Anda sudah memiliki catatan absensi hari ini.');
        }

        $lastSubmission = Attendance::where('user_id', $user->id)
            ->whereIn('type', [AttendanceType::Sick, AttendanceType::Permission])
            ->whereNotNull('last_submission_at')
            ->orderBy('last_submission_at', 'desc')
            ->first();

        if (
            ! $existingToday?->isRejectedLeave()
            && $lastSubmission?->last_submission_at?->isSameDay(AppTime::today())
        ) {
            $cooldownSeconds = 60;
            $secondsSinceLastSubmission = AppTime::now()->diffInSeconds($lastSubmission->last_submission_at);

            if ($secondsSinceLastSubmission < $cooldownSeconds) {
                $remainingSeconds = $cooldownSeconds - $secondsSinceLastSubmission;

                return ActionResult::fail("Harap tunggu {$remainingSeconds} detik sebelum mengajukan lagi.");
            }
        }

        if ($existingToday?->isRejectedLeave()) {
            $existingToday->update([
                'type' => $type,
                'leave_note' => $note,
                'doctor_note_path' => $this->doctorNotes->store($doctorNote) ?? $existingToday->doctor_note_path,
                'status' => AttendanceStatus::forLeave($type),
                'clock_in_latitude' => $latitude,
                'clock_in_longitude' => $longitude,
                'clock_in_location' => $this->locations->resolveAddress($latitude, $longitude),
                'accuracy' => $accuracy,
                'verification_status' => VerificationStatus::Pending,
                'verified_by' => null,
                'verified_at' => null,
                'last_submission_at' => now(),
            ]);

            return ActionResult::ok(
                "Pengajuan absen {$type->label()} berhasil dikirim ulang. Menunggu verifikasi HR."
            );
        }

        Attendance::create([
            'user_id' => $user->id,
            'date' => AppTime::today(),
            'type' => $type,
            'leave_note' => $note,
            'doctor_note_path' => $this->doctorNotes->store($doctorNote),
            'status' => AttendanceStatus::forLeave($type),
            'clock_in_latitude' => $latitude,
            'clock_in_longitude' => $longitude,
            'clock_in_location' => $this->locations->resolveAddress($latitude, $longitude),
            'accuracy' => $accuracy,
            'last_submission_at' => now(),
        ]);

        return ActionResult::ok(
            "Pengajuan absen {$type->label()} berhasil dikirim. Menunggu verifikasi HR."
        );
    }

    public function clockOutContext(?Attendance $attendance): array
    {
        if (! $attendance?->isRegular() || $attendance->clock_out_time) {
            return ['canClockOutNow' => false, 'clockOutOpensAt' => null, 'clockOutWindow' => null];
        }

        $schedule = $this->clockOutScheduleFor($attendance);
        $now = AppTime::now();

        return [
            'canClockOutNow' => $schedule->canClockOutNow($now, $attendance->date),
            'clockOutOpensAt' => $schedule->formattedClockOutStart(),
            'clockOutWindow' => $schedule->clockOutWindowDescription(),
        ];
    }

    /**
     * Clock-out window for validation/UI: stored shift + work-calendar floor for Day shift only.
     */
    public function overtimeHoursFor(Attendance $attendance, ?Carbon $now = null): float
    {
        $now ??= AppTime::now();

        return $this->clockOutScheduleFor($attendance)
            ->overtimeHoursFromClockOutOpen($attendance->date, $now);
    }

    public function clockInMomentFor(Attendance $attendance): ?Carbon
    {
        if (! $attendance->clock_in_time) {
            return null;
        }

        return $this->timeOnDutyDate($attendance, $attendance->clock_in_time);
    }

    public function clockOutMomentFor(Attendance $attendance, ?Carbon $explicitClockOutDate = null): ?Carbon
    {
        if (! $attendance->clock_out_time) {
            return null;
        }

        if ($explicitClockOutDate !== null) {
            return $this->timeOnDate($explicitClockOutDate, $attendance->clock_out_time);
        }

        $clockOut = $this->timeOnDutyDate($attendance, $attendance->clock_out_time);

        if (! $attendance->clock_in_time) {
            return $clockOut;
        }

        $clockIn = $this->clockInMomentFor($attendance);
        $schedule = $this->clockOutScheduleFor($attendance);

        if ($schedule->isNextDayClockOutPattern()) {
            return $clockOut->copy()->addDay();
        }

        if ($schedule->isOvernightClockOut()) {
            return $clockOut->lessThanOrEqualTo($clockIn)
                ? $clockOut->copy()->addDay()
                : $clockOut;
        }

        if ($clockOut->lessThan($clockIn)) {
            return $clockOut->copy()->addDay();
        }

        return $clockOut;
    }

    private function timeOnDutyDate(Attendance $attendance, string $time): Carbon
    {
        return $this->timeOnDate($attendance->date, $time);
    }

    private function timeOnDate(Carbon $date, string $time): Carbon
    {
        $normalized = substr((string) $time, 0, 8);

        return Carbon::parse(
            $date->toDateString().' '.$normalized,
            AppTime::timezone(),
        );
    }

    public function clockOutScheduleFor(Attendance $attendance): ShiftSchedule
    {
        $schedule = $attendance->shiftSchedule();

        if (($attendance->shift ?? AttendanceShift::Day) === AttendanceShift::Night) {
            return $schedule;
        }

        if (! $attendance->usesOfficeWorkCalendar()) {
            return $schedule;
        }

        $minimum = $this->workCalendar->dayShiftMinimumClockOutStart($attendance->date);

        if ($minimum === null) {
            return $schedule;
        }

        return match ($this->workCalendar->dayTypeForDate($attendance->date)) {
            WorkCalendarType::HalfDay => $schedule->withCalendarClockOutStart($minimum),
            default => $schedule->withMinimumClockOutStart($minimum),
        };
    }

    private function openSecurityAttendancePending(User $user): ?Attendance
    {
        $yesterday = AppTime::today()->subDay();

        return Attendance::query()
            ->forUser($user->id)
            ->whereDate('date', $yesterday)
            ->where('type', AttendanceType::Regular)
            ->whereNotNull('clock_in_time')
            ->whereNull('clock_out_time')
            ->with('workSchedule')
            ->orderByDesc('id')
            ->get()
            ->first(fn (Attendance $attendance) => $attendance->shiftSchedule()->opensClockOutOnNextDutyDay());
    }

    private function validateFace(User $user, array $faceDescriptor, int $facesDetected): ?string
    {
        if (! $user->hasFaceRegistered()) {
            return 'Foto profil belum siap untuk verifikasi wajah. Hubungi HR/Admin.';
        }

        if ($facesDetected !== 1) {
            return $facesDetected < 1
                ? 'Wajah tidak terdeteksi. Pastikan wajah terlihat jelas di kamera.'
                : 'Terdeteksi lebih dari satu wajah. Hanya satu wajah yang diperbolehkan.';
        }

        return $this->faceVerification->validateDescriptorPayload($faceDescriptor);
    }

    private function validateLocation(?float $latitude, ?float $longitude): ?string
    {
        if ($latitude === null || $longitude === null) {
            return 'Lokasi GPS wajib untuk absensi. Izinkan akses lokasi pada peramban Anda.';
        }

        return null;
    }

    /**
     * Attendance locations open to this employee (for everyone + assigned) and where the point is.
     *
     * @return array{locations: Collection<int, WorkLocation>, point: array{float, float}, match: ?WorkLocation, nearest: ?WorkLocation, distance: ?float}
     */
    private function locateWorkplace(User $user, float $latitude, float $longitude): array
    {
        $locations = $this->workLocations->availableTo($user->employee);

        return [
            'locations' => $locations,
            'point' => [$latitude, $longitude],
            ...$this->workLocations->locate($locations, $latitude, $longitude),
        ];
    }

    /**
     * No configured location means no geofence, as before; otherwise the point must be inside one.
     */
    private function geofenceError(array $place): ?string
    {
        if ($place['locations']->isEmpty() || $place['match'] !== null) {
            return null;
        }

        $nearest = $place['nearest'];
        $distance = $place['distance'] >= 1000
            ? number_format($place['distance'] / 1000, 2, ',', '.').' KM'
            : round($place['distance']).' meter';

        return "Anda berada di luar area absensi. Lokasi terdekat: {$nearest->name}, {$distance} dari titik lokasi (radius {$nearest->formattedRadius()}).";
    }

    private function resolveAnomalyStatus(
        array $place,
        ?float $accuracy,
        ?string $deviceType,
        ?int $clientTime = null
    ): array {
        if ($deviceType === 'desktop') {
            return ['status' => 'normal', 'reason' => 'Desktop mode: loose validation.'];
        }

        // Fake time detection: compare client time with server time
        if ($clientTime !== null) {
            $serverTime = AppTime::now()->timestamp;
            $timeDifference = abs($serverTime - $clientTime);

            if ($timeDifference > 300) { // 5 minutes = 300 seconds
                return ['status' => 'suspicious', 'reason' => 'Device time mismatch'];
            }
        }

        if ($accuracy !== null && $accuracy > 100) {
            // Poor accuracy right at the edge of the nearest location's radius is the riskiest case.
            $boundary = $place['match'] ?? $place['nearest'];
            $distanceToBoundary = $boundary
                ? abs($boundary->radius_meters - $boundary->distanceTo(...$place['point']))
                : null;

            if ($distanceToBoundary !== null && $distanceToBoundary <= 10) {
                return ['status' => 'high_risk', 'reason' => 'accuracy buruk + borderline radius'];
            }

            return ['status' => 'suspicious', 'reason' => 'accuracy tinggi'];
        }

        /*
        // TODO: Fitur dimatikan sementara
        if ($latitude !== null && $longitude !== null) {
            $timeLimit = AppTime::now()->subMinutes(5);

            $otherUsersAttendances = Attendance::whereDate('date', AppTime::today())
                ->where('user_id', '!=', $user->id)
                ->whereNotNull('clock_in_latitude')
                ->where('updated_at', '>=', $timeLimit)
                ->get();

            foreach ($otherUsersAttendances as $record) {
                $dist = \App\Support\GeoDistance::distanceMeters(
                    $latitude, $longitude,
                    $record->clock_in_latitude, $record->clock_in_longitude
                );
                if ($dist < 5) {
                    return ['status' => 'pattern_suspicious', 'reason' => 'koordinat terlalu identik'];
                }
            }
        }
        */

        return ['status' => 'normal', 'reason' => null];
    }
}
