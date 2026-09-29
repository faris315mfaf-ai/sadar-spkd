<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\WorkLocation;
use Illuminate\Support\Collection;

class WorkLocationService
{
    /**
     * @return Collection<int, WorkLocation>
     */
    public function availableTo(?Employee $employee): Collection
    {
        return WorkLocation::query()->availableTo($employee)->orderBy('name')->get();
    }

    /**
     * The location containing the point (the nearest one when several overlap) and the nearest
     * location overall, with its distance. Both are null when there are no locations.
     *
     * @param  Collection<int, WorkLocation>  $locations
     * @return array{match: ?WorkLocation, nearest: ?WorkLocation, distance: ?float}
     */
    public function locate(Collection $locations, float $latitude, float $longitude): array
    {
        $ranked = $locations
            ->map(fn (WorkLocation $location) => [
                'location' => $location,
                'distance' => $location->distanceTo($latitude, $longitude),
            ])
            ->sortBy('distance')
            ->values();

        $nearest = $ranked->first();
        $match = $ranked->first(fn (array $row) => $row['distance'] <= $row['location']->radius_meters);

        return [
            'match' => $match['location'] ?? null,
            'nearest' => $nearest['location'] ?? null,
            'distance' => $nearest['distance'] ?? null,
        ];
    }

    /**
     * @param  Collection<int, WorkLocation>  $locations
     * @return list<array{id: int, name: string, lat: float, lng: float, radius: int, radiusLabel: string}>
     */
    public function mapPoints(Collection $locations): array
    {
        return $locations->map(fn (WorkLocation $location) => $location->toMapPoint())->values()->all();
    }
}
