<?php

use App\Support\AttachmentFingerprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * - Potongan izin/sakit/cuti/tugas luar: nominal rupiah per hari kerja per
 *   jenis di aturan potongan (kosong = tidak dipotong).
 * - Baris presensi mencatat pengajuan izin asalnya, karena status "izin"
 *   dipakai bersama oleh Izin dan Cuti yang potongannya bisa berbeda.
 * - Izin bersama: satu pegawai mengajukan untuk dirinya dan rekan; tiap
 *   anggota tetap punya pengajuan sendiri yang terhubung lewat group_id.
 * - Sidik lampiran untuk menolak file identik dan menandai foto yang mirip.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deduction_rule_sets', function (Blueprint $table) {
            $table->json('leave_amounts')->nullable()->after('absent_amount');
        });

        Schema::table('leave_requests', function (Blueprint $table) {
            $table->uuid('group_id')->nullable()->index()->after('employee_id');
            $table->foreignId('submitted_by')->nullable()->after('group_id')->constrained('employees')->nullOnDelete();
            $table->char('attachment_hash', 64)->nullable()->index()->after('attachment_path');
            $table->char('attachment_phash', 16)->nullable()->after('attachment_hash');
            $table->foreignId('similar_leave_request_id')->nullable()->after('attachment_phash')
                ->constrained('leave_requests')->nullOnDelete();
        });

        Schema::table('attendances', function (Blueprint $table) {
            $table->foreignId('leave_request_id')->nullable()->after('employee_id')->constrained('leave_requests')->nullOnDelete();
        });

        // Hubungkan baris izin/sakit/tugas luar lama ke pengajuan yang disetujui.
        DB::statement(<<<'SQL'
            UPDATE attendances a SET leave_request_id = (
                SELECT l.id FROM leave_requests l
                WHERE l.employee_id = a.employee_id AND l.status = 'approved'
                  AND l.start_date <= a.date AND l.end_date >= a.date
                ORDER BY l.id DESC LIMIT 1
            )
            WHERE a.status IN ('leave', 'sick', 'duty') AND a.leave_request_id IS NULL
            SQL);

        // Sidik lampiran lama; file yang tidak terbaca dilewati.
        $disk = Storage::disk(config('attendance.photo_disk'));
        DB::table('leave_requests')->whereNotNull('attachment_path')->orderBy('id')->each(function ($row) use ($disk) {
            try {
                $bytes = $disk->get($row->attachment_path);
            } catch (Throwable) {
                $bytes = null;
            }
            if ($bytes) {
                DB::table('leave_requests')->where('id', $row->id)->update([
                    'attachment_hash' => hash('sha256', $bytes),
                    'attachment_phash' => AttachmentFingerprint::perceptual($bytes),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('attendances', fn (Blueprint $table) => $table->dropConstrainedForeignId('leave_request_id'));
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('similar_leave_request_id');
            $table->dropConstrainedForeignId('submitted_by');
            $table->dropColumn(['group_id', 'attachment_hash', 'attachment_phash']);
        });
        Schema::table('deduction_rule_sets', fn (Blueprint $table) => $table->dropColumn('leave_amounts'));
    }
};
