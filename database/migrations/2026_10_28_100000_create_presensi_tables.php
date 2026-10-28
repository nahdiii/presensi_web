<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Skema presensi kampus. Semua tabel utama sudah membawa institution_id
 * walau isinya baru satu kampus, supaya mudah dijadikan SaaS nanti.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('institutions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('timezone')->default('Asia/Makassar');
            $table->unsignedBigInteger('default_work_pattern_id')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('institution_id')->nullable()->after('id')->constrained();
            $table->boolean('is_active')->default(true)->after('password');
        });

        Schema::create('work_patterns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained();
            $table->string('name');
            $table->string('type'); // fixed / flexible
            $table->unsignedSmallInteger('late_tolerance_min')->default(0);
            $table->unsignedSmallInteger('checkin_open_min')->default(60);
            $table->unsignedSmallInteger('min_daily_minutes')->nullable();
            $table->timestamps();
        });

        Schema::table('institutions', function (Blueprint $table) {
            $table->foreign('default_work_pattern_id')->references('id')->on('work_patterns')->nullOnDelete();
        });

        Schema::create('work_pattern_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_pattern_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday'); // ISO: 1 = Senin … 7 = Minggu
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable(); // lebih kecil dari start_time = melewati tengah malam
            $table->time('break_start')->nullable();
            $table->time('break_end')->nullable();
            $table->boolean('is_day_off')->default(false);
            $table->unique(['work_pattern_id', 'weekday']);
        });

        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained();
            $table->foreignId('parent_id')->nullable()->constrained('units')->nullOnDelete();
            $table->string('name');
            $table->string('type'); // fakultas / prodi / biro / lembaga
            $table->unsignedBigInteger('head_employee_id')->nullable();
            $table->foreignId('work_pattern_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('offices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained();
            $table->string('name');
            $table->string('address')->nullable();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedInteger('radius_m')->default(100);
            $table->string('timezone')->default('Asia/Makassar');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('nip')->nullable()->unique();
            $table->string('nidn')->nullable();
            $table->string('phone')->nullable();
            $table->string('employee_type'); // dosen / tendik
            $table->string('attendance_type'); // fixed / flexible / session
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('work_pattern_id')->nullable()->constrained()->nullOnDelete();
            $table->string('photo_path')->nullable();
            $table->timestamps();
        });

        Schema::table('units', function (Blueprint $table) {
            $table->foreign('head_employee_id')->references('id')->on('employees')->nullOnDelete();
        });

        Schema::create('employee_offices', function (Blueprint $table) {
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->primary(['employee_id', 'office_id']);
        });

        Schema::create('schedule_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained();
            $table->string('scope_type'); // institution / office / unit / employee
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->foreignId('work_pattern_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_day_off')->default(false);
            $table->string('reason');
            $table->timestamps();
            $table->index(['scope_type', 'scope_id', 'start_date', 'end_date']);
        });

        Schema::create('calendar_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained();
            $table->date('date');
            $table->string('name');
            $table->string('type'); // libur_nasional / cuti_bersama / libur_kampus
            $table->timestamps();
            $table->unique(['institution_id', 'date']);
        });

        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('device_uid');
            $table->string('model')->nullable();
            $table->string('platform')->nullable();
            $table->string('fcm_token')->nullable();
            $table->timestampTz('bound_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'revoked_at']);
        });

        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('status');
            $table->json('schedule_snapshot')->nullable();
            $table->unsignedInteger('late_minutes')->default(0);
            $table->unsignedInteger('worked_minutes')->default(0);
            foreach (['check_in', 'check_out'] as $p) {
                $table->foreignId("{$p}_office_id")->nullable()->constrained('offices')->nullOnDelete();
                $table->timestampTz("{$p}_at")->nullable();
                $table->decimal("{$p}_lat", 10, 7)->nullable();
                $table->decimal("{$p}_lng", 10, 7)->nullable();
                $table->decimal("{$p}_accuracy_m", 8, 2)->nullable();
                $table->decimal("{$p}_distance_m", 10, 2)->nullable();
                $table->string("{$p}_photo_path")->nullable();
            }
            $table->boolean('is_early_leave')->default(false);
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();
            $table->unique(['employee_id', 'date']);
            $table->index(['date', 'status']);
        });

        Schema::create('attendance_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // in / out / check
            $table->string('result'); // accepted / rejected
            $table->string('reason')->nullable();
            $table->foreignId('office_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->decimal('accuracy_m', 8, 2)->nullable();
            $table->decimal('distance_m', 10, 2)->nullable();
            $table->boolean('is_mocked')->default(false);
            $table->boolean('is_rooted')->default(false);
            $table->string('device_uid')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestampTz('created_at');
            $table->index(['employee_id', 'created_at']);
        });

        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // izin / sakit / cuti / tugas_luar
            $table->date('start_date');
            $table->date('end_date');
            $table->text('reason');
            $table->string('attachment_path')->nullable();
            $table->string('status')->default('pending'); // pending / approved / rejected
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->string('review_note')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'start_date', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('attendance_attempts');
        Schema::dropIfExists('attendances');
        Schema::dropIfExists('devices');
        Schema::dropIfExists('calendar_events');
        Schema::dropIfExists('schedule_overrides');
        Schema::dropIfExists('employee_offices');
        Schema::table('units', fn (Blueprint $t) => $t->dropForeign(['head_employee_id']));
        Schema::dropIfExists('employees');
        Schema::dropIfExists('offices');
        Schema::dropIfExists('units');
        Schema::dropIfExists('work_pattern_days');
        Schema::table('institutions', fn (Blueprint $t) => $t->dropForeign(['default_work_pattern_id']));
        Schema::dropIfExists('work_patterns');
        Schema::table('users', function (Blueprint $t) {
            $t->dropConstrainedForeignId('institution_id');
            $t->dropColumn('is_active');
        });
        Schema::dropIfExists('institutions');
    }
};
