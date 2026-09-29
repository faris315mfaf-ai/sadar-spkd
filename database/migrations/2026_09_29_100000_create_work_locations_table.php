<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Several attendance locations (office, hospital, ...) instead of the single office point in
 * settings. A location applies to every employee or only to the employees assigned to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_locations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('latitude', 10, 8);
            $table->decimal('longitude', 11, 8);
            $table->unsignedInteger('radius_meters');
            $table->boolean('applies_to_all')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('employee_work_location', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_location_id')->constrained()->cascadeOnDelete();
            $table->unique(['employee_id', 'work_location_id']);
        });

        Schema::table('attendances', function (Blueprint $table) {
            $table->foreignId('clock_in_work_location_id')->nullable()->constrained('work_locations')->nullOnDelete();
            $table->foreignId('clock_out_work_location_id')->nullable()->constrained('work_locations')->nullOnDelete();
        });

        // Carry the existing office point over so the geofence keeps working after the upgrade.
        $settings = DB::table('settings')->first();

        if ($settings && $settings->office_latitude !== null && $settings->office_longitude !== null
            && (int) $settings->attendance_radius_meters > 0) {
            DB::table('work_locations')->insert([
                'name' => 'Kantor',
                'latitude' => $settings->office_latitude,
                'longitude' => $settings->office_longitude,
                'radius_meters' => (int) $settings->attendance_radius_meters,
                'applies_to_all' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropForeign(['clock_in_work_location_id']);
            $table->dropForeign(['clock_out_work_location_id']);
            $table->dropColumn(['clock_in_work_location_id', 'clock_out_work_location_id']);
        });

        Schema::dropIfExists('employee_work_location');
        Schema::dropIfExists('work_locations');
    }
};
