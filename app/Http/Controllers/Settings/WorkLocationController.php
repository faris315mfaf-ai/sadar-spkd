<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\WorkLocation;
use App\Services\ActivityLogService;
use App\Services\WorkLocationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Attendance locations (office, hospital, ...). A location applies to every employee or only to
 * the employees picked for it.
 */
class WorkLocationController extends Controller
{
    public function index(WorkLocationService $workLocations): View
    {
        $locations = WorkLocation::query()
            ->withCount('employees')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        return view('settings.locations.index', [
            'locations' => $locations,
            'mapPoints' => $workLocations->mapPoints($locations->where('is_active', true)),
        ]);
    }

    public function create(): View
    {
        $reference = WorkLocation::query()->orderBy('id')->first();

        return $this->form(new WorkLocation([
            'latitude' => $reference?->latitude ?? -6.2,
            'longitude' => $reference?->longitude ?? 106.816666,
            'radius_meters' => 200,
            'applies_to_all' => false,
            'is_active' => true,
        ]));
    }

    public function store(Request $request): RedirectResponse
    {
        [$data, $employeeIds] = $this->validated($request);

        $location = DB::transaction(function () use ($data, $employeeIds) {
            $location = WorkLocation::create($data);
            $location->employees()->sync($employeeIds);

            return $location;
        });

        ActivityLogService::log($request->user(), 'create', "Menambahkan lokasi absensi: {$location->name}", $location);

        return redirect()->route('settings.locations.index')
            ->with('success', "Lokasi \"{$location->name}\" berhasil ditambahkan.");
    }

    public function edit(WorkLocation $workLocation): View
    {
        return $this->form($workLocation);
    }

    public function update(Request $request, WorkLocation $workLocation): RedirectResponse
    {
        [$data, $employeeIds] = $this->validated($request);

        DB::transaction(function () use ($workLocation, $data, $employeeIds) {
            $workLocation->update($data);
            $workLocation->employees()->sync($employeeIds);
        });

        ActivityLogService::log($request->user(), 'update', "Memperbarui lokasi absensi: {$workLocation->name}", $workLocation);

        return redirect()->route('settings.locations.index')
            ->with('success', "Lokasi \"{$workLocation->name}\" berhasil diperbarui.");
    }

    public function destroy(Request $request, WorkLocation $workLocation): RedirectResponse
    {
        $name = $workLocation->name;
        $workLocation->delete();

        ActivityLogService::log($request->user(), 'delete', "Menghapus lokasi absensi: {$name}");

        return redirect()->route('settings.locations.index')
            ->with('success', "Lokasi \"{$name}\" berhasil dihapus.");
    }

    private function form(WorkLocation $location): View
    {
        return view('settings.locations.form', [
            'location' => $location,
            'employees' => Employee::query()
                ->where('employment_status', 'active')
                ->orderBy('name')
                ->get(['id', 'employee_code', 'name', 'staff']),
            'assignedIds' => $location->exists ? $location->employees()->pluck('employees.id')->all() : [],
        ]);
    }

    /**
     * @return array{0: array<string, mixed>, 1: list<int>}
     */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'radius_meters' => ['required', 'integer', 'min:10', 'max:50000'],
            'applies_to_all' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
            'employee_ids' => ['exclude_if:applies_to_all,1', 'required', 'array'],
            'employee_ids.*' => ['integer', 'exists:employees,id'],
        ], [
            'name.required' => 'Nama lokasi wajib diisi.',
            'radius_meters.min' => 'Radius minimal 10 meter.',
            'employee_ids.required' => 'Pilih minimal satu karyawan, atau ubah menjadi "Semua karyawan".',
        ]);

        $employeeIds = $validated['applies_to_all'] ? [] : array_map('intval', $validated['employee_ids']);
        unset($validated['employee_ids']);

        return [$validated, $employeeIds];
    }
}
