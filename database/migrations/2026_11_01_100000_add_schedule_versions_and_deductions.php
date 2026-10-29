<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Jadwal bertanggal berlaku dan potongan terlambat/pulang cepat.
 *
 * - work_pattern_versions: riwayat jam kerja. Setiap perubahan jam disimpan
 *   sebagai versi baru dengan tanggal mulai berlaku; versi lama tidak diubah.
 * - offices.work_pattern_id: jam kerja per lokasi kampus.
 * - deduction_rule_sets: aturan potongan (TL/PSW), juga bertanggal berlaku.
 * - attendances: menit pulang cepat dan hasil potongan per hari.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_pattern_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_pattern_id')->constrained()->cascadeOnDelete();
            $table->date('effective_from');
            $table->string('type');
            $table->unsignedSmallInteger('late_tolerance_min')->default(0);
            $table->unsignedSmallInteger('checkin_open_min')->default(60);
            $table->unsignedSmallInteger('min_daily_minutes')->nullable();
            $table->json('days'); // {"1": {start_time, end_time, break_start, break_end, is_day_off}, …}
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['work_pattern_id', 'effective_from']);
        });

        Schema::table('offices', function (Blueprint $table) {
            $table->foreignId('work_pattern_id')->nullable()->after('timezone')->constrained()->nullOnDelete();
        });

        Schema::create('deduction_rule_sets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained();
            $table->date('effective_from');
            $table->json('late_tiers'); // [{code, from, to, percent}] — to null = tanpa batas atas
            $table->json('early_leave_tiers');
            $table->decimal('no_checkout_percent', 5, 2)->default(0);
            $table->decimal('absent_percent', 5, 2)->default(0);
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['institution_id', 'effective_from']);
        });

        Schema::table('attendances', function (Blueprint $table) {
            $table->unsignedInteger('early_leave_minutes')->default(0)->after('is_early_leave');
            $table->string('late_code', 20)->nullable()->after('early_leave_minutes');
            $table->string('early_leave_code', 20)->nullable()->after('late_code');
            $table->decimal('deduction_percent', 5, 2)->default(0)->after('early_leave_code');
            $table->foreignId('deduction_rule_set_id')->nullable()->after('deduction_percent')
                ->constrained()->nullOnDelete();
        });

        // Jam yang sudah ada menjadi versi pertama, berlaku sejak awal.
        $days = DB::table('work_pattern_days')->get()->groupBy('work_pattern_id');
        foreach (DB::table('work_patterns')->get() as $p) {
            DB::table('work_pattern_versions')->insert([
                'work_pattern_id' => $p->id,
                'effective_from' => '2000-01-01',
                'type' => $p->type,
                'late_tolerance_min' => $p->late_tolerance_min,
                'checkin_open_min' => $p->checkin_open_min,
                'min_daily_minutes' => $p->min_daily_minutes,
                'days' => json_encode($days->get($p->id, collect())->mapWithKeys(fn ($d) => [$d->weekday => [
                    'start_time' => $d->start_time ? substr($d->start_time, 0, 5) : null,
                    'end_time' => $d->end_time ? substr($d->end_time, 0, 5) : null,
                    'break_start' => $d->break_start ? substr($d->break_start, 0, 5) : null,
                    'break_end' => $d->break_end ? substr($d->break_end, 0, 5) : null,
                    'is_day_off' => (bool) $d->is_day_off,
                ]])->all()),
                'note' => 'Jadwal awal',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deduction_rule_set_id');
            $table->dropColumn(['early_leave_minutes', 'late_code', 'early_leave_code', 'deduction_percent']);
        });
        Schema::dropIfExists('deduction_rule_sets');
        Schema::table('offices', fn (Blueprint $table) => $table->dropConstrainedForeignId('work_pattern_id'));
        Schema::dropIfExists('work_pattern_versions');
    }
};
