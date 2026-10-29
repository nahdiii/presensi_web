<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Jatah cuti tahunan per pegawai dan jumlah hari kerja tiap pengajuan izin. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // null = jatah bawaan dari config attendance.leave.annual_quota_days
            $table->unsignedSmallInteger('annual_leave_quota')->nullable()->after('work_pattern_id');
        });
        Schema::table('leave_requests', function (Blueprint $table) {
            // Hari kerja dalam rentang saat diajukan; dasar perhitungan sisa cuti.
            $table->unsignedSmallInteger('days')->nullable()->after('end_date');
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', fn (Blueprint $table) => $table->dropColumn('days'));
        Schema::table('employees', fn (Blueprint $table) => $table->dropColumn('annual_leave_quota'));
    }
};
