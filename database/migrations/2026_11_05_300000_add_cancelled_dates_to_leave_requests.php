<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hari dalam izin/cuti yang dibatalkan karena pegawai ternyata masuk kerja
 * (oleh pegawai sendiri untuk hari ini, atau oleh Admin SDM). Pengajuan
 * tetap disetujui untuk hari-hari lainnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->jsonb('cancelled_dates')->nullable()->after('days');
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', fn (Blueprint $table) => $table->dropColumn('cancelled_dates'));
    }
};
