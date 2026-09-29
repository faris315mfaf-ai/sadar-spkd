<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Services\WhatsappAttendanceReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WhatsappAttendanceReportGroupingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function without_configured_groups_each_typed_division_is_its_own_section(): void
    {
        config(['divisions.groups' => [], 'divisions.fallback_group' => 'LAINNYA']);

        $this->employee('ID-001', 'Budi', 'IT');
        $this->employee('ID-002', 'Siti', ' keuangan ');
        $this->employee('ID-003', 'Andi', 'Keuangan');
        $this->employee('ID-004', 'Rudi', null);

        $report = app(WhatsappAttendanceReportService::class)->generate(Carbon::parse('2026-09-28'));

        $this->assertStringContainsString("*IT*\n1. Budi", $report);
        $this->assertStringContainsString("*KEUANGAN*\n2. Siti", $report);
        $this->assertStringContainsString('3. Andi', $report);
        $this->assertStringContainsString("*LAINNYA*\n4. Rudi", $report);
        $this->assertLessThan(strpos($report, '*LAINNYA*'), strpos($report, '*KEUANGAN*'));
    }

    #[Test]
    public function configured_groups_still_merge_divisions(): void
    {
        config([
            'divisions.groups' => ['STAFF KANTOR' => ['IT', 'Keuangan']],
            'divisions.fallback_group' => 'LAINNYA',
        ]);

        $this->employee('ID-001', 'Budi', 'it');
        $this->employee('ID-002', 'Siti', 'Keuangan');
        $this->employee('ID-003', 'Rudi', 'Gudang');

        $report = app(WhatsappAttendanceReportService::class)->generate(Carbon::parse('2026-09-28'));

        $this->assertStringContainsString("*STAFF KANTOR*\n1. Budi", $report);
        $this->assertStringContainsString('2. Siti', $report);
        $this->assertStringContainsString("*LAINNYA*\n3. Rudi", $report);
    }

    private function employee(string $code, string $name, ?string $staff): void
    {
        Employee::create([
            'employee_code' => $code,
            'name' => $name,
            'staff' => $staff,
            'employment_status' => 'active',
        ]);
    }
}
