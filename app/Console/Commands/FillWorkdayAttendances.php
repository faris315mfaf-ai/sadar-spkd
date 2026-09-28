<?php

namespace App\Console\Commands;

use App\Enums\AttendanceStatus;
use App\Enums\AttendanceType;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\WorkCalendar;
use Carbon\Carbon;
use Illuminate\Console\Command;

class FillWorkdayAttendances extends Command
{
    protected $signature = 'attendances:fill-workdays {month} {year}';

    protected $description = 'Fill all active employees as present on workdays';

    public function handle(): int
    {
        $month = (int) $this->argument('month');
        $year = (int) $this->argument('year');

        $employees = Employee::where('employment_status', 'active')
            ->whereNotNull('user_id')
            ->get();

        $workDays = WorkCalendar::whereYear('date', $year)
            ->whereMonth('date', $month)
            ->whereIn('type', ['full_day', 'half_day'])
            ->orderBy('date')
            ->get();

        foreach ($employees as $employee) {
            $isSecurity = str_contains(strtolower($employee->position ?? ''), 'security')
                || str_contains(strtolower($employee->staff ?? ''), 'security');

            $employeeWorkDays = $isSecurity
                ? $workDays->take(15)
                : $workDays;

            foreach ($employeeWorkDays as $day) {
                // Only fill empty days: never overwrite real sick, izin or alfa records.
                Attendance::firstOrCreate(
                    [
                        'user_id' => $employee->user_id,
                        'date' => Carbon::parse($day->date)->startOfDay(),
                    ],
                    [
                        'type' => AttendanceType::Regular,
                        'clock_in_time' => '09:00:00',
                        'clock_out_time' => $day->type->value === 'half_day'
                            ? '14:00:00'
                            : '18:00:00',
                        'status' => AttendanceStatus::OnTime,
                    ]
                );
            }
        }

        $this->info('Attendance workdays filled successfully.');

        return self::SUCCESS;
    }
}
