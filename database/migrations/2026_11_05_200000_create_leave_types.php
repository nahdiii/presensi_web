<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Jenis izin menjadi master data yang diatur Admin SDM (Pengaturan → Jenis
 * Izin), termasuk potongan per hari kerja. Nominal itu disalin ke setiap
 * pengajuan saat diajukan (leave_requests.deduction_per_day), jadi mengubah
 * master tidak mengubah potongan pengajuan yang sudah ada.
 *
 * Potongan izin di aturan potongan (leave_amounts) dipindah ke sini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name', 100);
            $table->string('attendance_status', 20)->default('leave');
            $table->unsignedInteger('deduction_per_day')->default(0);
            $table->unsignedSmallInteger('annual_quota_days')->nullable();
            $table->boolean('allows_group')->default(false);
            $table->boolean('requires_attachment')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
            $table->unique(['institution_id', 'code']);
        });

        $latestAmounts = fn (int $institutionId) => json_decode(DB::table('deduction_rule_sets')
            ->where('institution_id', $institutionId)->orderByDesc('effective_from')->orderByDesc('id')
            ->value('leave_amounts') ?? '[]', true) ?: [];

        foreach (DB::table('institutions')->pluck('id') as $institutionId) {
            $amounts = $latestAmounts($institutionId);
            foreach (self::defaults() as $i => $type) {
                DB::table('leave_types')->insert($type + [
                    'institution_id' => $institutionId,
                    'deduction_per_day' => (int) ($amounts[$type['code']] ?? 0),
                    'sort' => $i + 1,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        Schema::table('leave_requests', function (Blueprint $table) {
            $table->foreignId('leave_type_id')->nullable()->after('type')->constrained()->restrictOnDelete();
            $table->unsignedInteger('deduction_per_day')->default(0)->after('days');
        });

        // Pengajuan lama: hubungkan ke jenisnya dan salin nominal yang berlaku saat ini.
        foreach (DB::table('leave_types')->get() as $type) {
            DB::table('leave_requests')->where('institution_id', $type->institution_id)->where('type', $type->code)
                ->update(['leave_type_id' => $type->id, 'deduction_per_day' => $type->deduction_per_day]);
        }

        Schema::table('deduction_rule_sets', fn (Blueprint $table) => $table->dropColumn('leave_amounts'));
    }

    public function down(): void
    {
        Schema::table('deduction_rule_sets', function (Blueprint $table) {
            $table->json('leave_amounts')->nullable()->after('absent_amount');
        });
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('leave_type_id');
            $table->dropColumn('deduction_per_day');
        });
        Schema::dropIfExists('leave_types');
    }

    /** Jenis bawaan, sama dengan yang dipakai sebelum master data ini ada. */
    public static function defaults(): array
    {
        return [
            ['code' => 'izin', 'name' => 'Izin', 'attendance_status' => 'leave', 'annual_quota_days' => null, 'allows_group' => true, 'requires_attachment' => false],
            ['code' => 'sakit', 'name' => 'Sakit', 'attendance_status' => 'sick', 'annual_quota_days' => null, 'allows_group' => false, 'requires_attachment' => false],
            ['code' => 'cuti', 'name' => 'Cuti', 'attendance_status' => 'leave', 'annual_quota_days' => (int) config('attendance.leave.annual_quota_days'), 'allows_group' => false, 'requires_attachment' => false],
            ['code' => 'tugas_luar', 'name' => 'Tugas luar', 'attendance_status' => 'duty', 'annual_quota_days' => null, 'allows_group' => true, 'requires_attachment' => false],
        ];
    }
};
