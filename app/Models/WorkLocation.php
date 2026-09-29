<?php

namespace App\Models;

use App\Support\GeoDistance;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A place where attendance may be recorded (office, hospital, ...): a point plus a radius.
 * It applies to every employee, or only to the employees assigned to it.
 */
class WorkLocation extends Model
{
    protected $fillable = [
        'name',
        'latitude',
        'longitude',
        'radius_meters',
        'applies_to_all',
        'is_active',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'radius_meters' => 'integer',
        'applies_to_all' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Active locations the employee may clock in at: those for everyone plus those assigned to them.
     */
    public function scopeAvailableTo(Builder $query, ?Employee $employee): Builder
    {
        return $query->active()->where(function (Builder $inner) use ($employee) {
            $inner->where('applies_to_all', true);

            if ($employee) {
                $inner->orWhereHas('employees', fn (Builder $assigned) => $assigned->whereKey($employee->id));
            }
        });
    }

    public function distanceTo(float $latitude, float $longitude): float
    {
        return GeoDistance::distanceMeters($latitude, $longitude, $this->latitude, $this->longitude);
    }

    public function contains(float $latitude, float $longitude): bool
    {
        return $this->distanceTo($latitude, $longitude) <= $this->radius_meters;
    }

    public function formattedRadius(): string
    {
        $meters = $this->radius_meters;

        if ($meters >= 1000) {
            return rtrim(rtrim(number_format($meters / 1000, 2, ',', '.'), '0'), ',').' KM';
        }

        return $meters.' meter';
    }

    /**
     * @return array{id: int, name: string, lat: float, lng: float, radius: int, radiusLabel: string}
     */
    public function toMapPoint(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'lat' => $this->latitude,
            'lng' => $this->longitude,
            'radius' => $this->radius_meters,
            'radiusLabel' => $this->formattedRadius(),
        ];
    }
}
