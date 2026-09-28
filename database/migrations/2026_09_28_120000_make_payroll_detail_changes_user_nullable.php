<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deleting an employee whose account ever edited a payroll failed on this foreign key.
 * Keep the change log, just forget who made the change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_detail_changes', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::table('payroll_detail_changes', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payroll_detail_changes', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::table('payroll_detail_changes', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable(false)->change();
            $table->foreign('user_id')->references('id')->on('users');
        });
    }
};
