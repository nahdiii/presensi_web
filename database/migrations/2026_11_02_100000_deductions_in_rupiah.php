<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Potongan memakai nominal rupiah, bukan persen tunjangan. Setiap tingkat
 * TL/PSW, tidak absen pulang, dan alpa punya nominal tetap per kejadian.
 *
 * Persen di aturan lama tidak bisa diubah ke rupiah, jadi nominalnya diisi 0:
 * admin perlu membuat aturan baru di menu Aturan Potongan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deduction_rule_sets', function (Blueprint $table) {
            $table->dropColumn(['no_checkout_percent', 'absent_percent']);
        });
        Schema::table('deduction_rule_sets', function (Blueprint $table) {
            $table->unsignedInteger('no_checkout_amount')->default(0)->after('early_leave_tiers');
            $table->unsignedInteger('absent_amount')->default(0)->after('no_checkout_amount');
        });
        $toAmount = fn (?string $json) => json_encode(collect(json_decode($json ?? '[]', true))
            ->map(fn (array $t) => collect($t)->except('percent')->put('amount', 0)->all())->values()->all());
        foreach (DB::table('deduction_rule_sets')->get() as $set) {
            DB::table('deduction_rule_sets')->where('id', $set->id)->update([
                'late_tiers' => $toAmount($set->late_tiers),
                'early_leave_tiers' => $toAmount($set->early_leave_tiers),
                'note' => trim(($set->note ?? '').' (aturan persen lama, nominal belum diisi)'),
            ]);
        }

        Schema::table('attendances', fn (Blueprint $table) => $table->dropColumn('deduction_percent'));
        Schema::table('attendances', function (Blueprint $table) {
            $table->unsignedInteger('deduction_amount')->default(0)->after('early_leave_code');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', fn (Blueprint $table) => $table->dropColumn('deduction_amount'));
        Schema::table('attendances', function (Blueprint $table) {
            $table->decimal('deduction_percent', 5, 2)->default(0)->after('early_leave_code');
        });
        Schema::table('deduction_rule_sets', fn (Blueprint $table) => $table->dropColumn(['no_checkout_amount', 'absent_amount']));
        Schema::table('deduction_rule_sets', function (Blueprint $table) {
            $table->decimal('no_checkout_percent', 5, 2)->default(0);
            $table->decimal('absent_percent', 5, 2)->default(0);
        });
    }
};
