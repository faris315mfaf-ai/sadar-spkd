<?php

namespace App\Services;

use App\Models\CompanyProfile;
use App\Models\Employee;
use Illuminate\Support\Carbon;

class WhatsappAttendanceReportService
{
    public function generate(?Carbon $date = null, string $type = 'masuk'): string
    {
        $date ??= today();

        $employees = Employee::with([
            'user.attendances' => fn ($query) => $query->whereDate('date', $date),
            'schedules.workSchedule' => fn ($query) => $query,
        ])
            ->where('employment_status', '!=', 'resigned')
            ->orderBy('employee_code')
            ->get();

        $titleType = $type === 'pulang' ? 'PULANG' : 'MASUK';
        $companyName = CompanyProfile::getProfile()?->name ?: config('app.name');

        $message = "📋 *ABSENSI {$titleType} KARYAWAN*\n";
        $message .= '*'.mb_strtoupper($companyName)."*\n";
        $message .= 'Hari/Tanggal: '.$date->translatedFormat('l, d-m-Y')."\n\n";
        $message .= "*Keterangan:*\n";
        $message .= "H = Hadir | I = Izin | S = Sakit | A = Alpha | T = Telat\n\n";

        $counter = 1;

        // Staff is free text: match case-insensitively and put anything unknown in the catch-all group,
        // so no employee is left out of the report.
        $groups = config('divisions.groups', []);
        $fallbackGroup = config('divisions.fallback_group', 'LAINNYA');
        $hidePositionGroups = config('divisions.hide_position_groups', []);

        $divisionByStaff = [];
        foreach ($groups as $title => $staffTypes) {
            foreach ($staffTypes as $staffType) {
                $divisionByStaff[mb_strtolower(trim((string) $staffType))] = $title;
            }
        }

        $employeesByDivision = $employees->groupBy(function ($employee) use ($groups, $divisionByStaff, $fallbackGroup) {
            $staff = trim((string) $employee->staff);

            // No groups configured: each division, as typed on the biodata form, is its own group.
            if ($groups === []) {
                return $staff !== '' ? mb_strtoupper($staff) : $fallbackGroup;
            }

            return $divisionByStaff[mb_strtolower($staff)] ?? $fallbackGroup;
        });

        $groupTitles = $groups === []
            ? $employeesByDivision->keys()->reject(fn ($title) => $title === $fallbackGroup)->sort()->values()->all()
            : array_keys($groups);

        if (! in_array($fallbackGroup, $groupTitles, true)) {
            $groupTitles[] = $fallbackGroup;
        }

        foreach ($groupTitles as $title) {
            $divisionEmployees = $employeesByDivision->get($title, collect())->values();

            if ($divisionEmployees->isEmpty()) {
                continue;
            }

            $message .= "*{$title}*\n";

            foreach ($divisionEmployees as $employee) {
                $attendance = $employee->user?->attendances->first();

                $status = $this->attendanceCode($employee, $attendance, $type, $date);

                $message .= $counter.'. '.$employee->name;

                if (
                    ! in_array($title, $hidePositionGroups, true)
                    && filled($employee->position)
                ) {
                    $message .= ' - '.$employee->position;
                }

                $message .= ' ('.$status.")\n";

                $counter++;
            }

            $message .= "---\n\n";
        }

        return trim($message);
    }

    private function attendanceCode(Employee $employee, $attendance, string $type, Carbon $date): string
    {
        $schedule = $employee->schedules
            ->first(fn ($schedule) => $schedule->work_date->isSameDay($date));

        if ($schedule?->workSchedule?->is_off) {
            return 'OFF';
        }

        if (! $attendance) {
            if ($type === 'masuk') {
                return '';
            }

            return now()->hour >= 14 ? 'A' : '';
        }

        $status = $attendance->status instanceof \BackedEnum
            ? $attendance->status->value
            : $attendance->status;

        if ($status === 'permission') {
            return 'I';
        }

        if ($status === 'sick') {
            return 'S';
        }

        if ($type === 'masuk') {
            if (! $attendance->clock_in_time) {
                return '';
            }

            return $status === 'late' ? 'T' : 'H';
        }

        if ($type === 'pulang') {
            if ($attendance->clock_out_time) {
                return $status === 'early_out' ? 'T' : 'H';
            }

            if ($attendance->clock_in_time) {
                return 'H';
            }

            return 'A';
        }

        return '';
    }
}
