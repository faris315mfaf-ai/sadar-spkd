<?php

namespace App\Models;

use App\DTOs\ShiftSchedule;
use Illuminate\Database\Eloquent\Model;

class WorkSchedule extends Model
{
    protected $fillable = [
        'name',
        'code',
        'clock_in_start',
        'late_limit',
        'clock_out_start',
        'clock_out_limit',
        'is_off',
    ];

    protected $casts = [
        'is_off' => 'boolean',
    ];

    /**
     * Default schedule for a division/position: OB, Security and Engineering have their own
     * shifts; everyone else uses $preferredId when given, otherwise the regular schedule.
     */
    public static function defaultIdFor(?string $staff, ?string $position = null, ?int $preferredId = null): ?int
    {
        $staff = strtoupper(trim((string) $staff));
        $position = strtoupper(trim((string) $position));

        $code = match (true) {
            $staff === 'OB' || $position === 'OB' => 'ob',
            $staff === 'SECURITY' || $position === 'SECURITY' => 'security',
            $staff === 'ENGINEERING' || str_contains($position, 'ENGINEERING') => 'engineering',
            default => null,
        };

        if ($code === null && $preferredId) {
            return $preferredId;
        }

        return static::query()->where('code', $code ?? 'regular')->value('id');
    }

    public function employeeSchedules()
    {
        return $this->hasMany(EmployeeSchedule::class);
    }

    public function defaultEmployees()
    {
        return $this->hasMany(Employee::class, 'default_work_schedule_id');
    }

    public function attendances()
    {
        return $this->hasMany(Attendance::class);
    }

    public function toShiftSchedule(): ShiftSchedule
    {
        return new ShiftSchedule(
            officeStart: (string) $this->clock_in_start,
            lateLimit: (string) $this->late_limit,
            clockOutStart: (string) $this->clock_out_start,
            clockOutLimit: $this->clock_out_limit !== null ? (string) $this->clock_out_limit : null,
        );
    }

    public function formattedClockInWindow(): ?string
    {
        if ($this->is_off || ! $this->clock_in_start || ! $this->late_limit) {
            return null;
        }

        return $this->toShiftSchedule()->clockInWindowDescription();
    }

    public function formattedClockOutWindow(): ?string
    {
        if ($this->is_off || ! $this->clock_out_start) {
            return null;
        }

        return $this->toShiftSchedule()->clockOutWindowDescription();
    }

    public function opensClockOutOnNextDutyDay(): bool
    {
        if ($this->is_off || ! $this->clock_out_start) {
            return false;
        }

        return $this->toShiftSchedule()->opensClockOutOnNextDutyDay();
    }
}
