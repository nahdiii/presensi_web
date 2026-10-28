<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Fase 2: izin keluar sementara, cek kehadiran acak, dan koreksi lupa absen. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exit_permits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attendance_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason');
            $table->timestampTz('out_at');
            $table->timestampTz('back_at')->nullable();
            $table->decimal('back_distance_m', 10, 2)->nullable();
            $table->string('status')->default('pending'); // pending / approved / rejected
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->string('review_note')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'out_at']);
        });

        Schema::create('presence_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attendance_id')->nullable()->constrained()->nullOnDelete();
            // scheduled → pending (sudah dikirim) → ok / out_of_radius / no_response; atau skipped / cancelled
            $table->string('result')->default('scheduled');
            $table->timestampTz('scheduled_at');
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('deadline_at')->nullable();
            $table->timestampTz('responded_at')->nullable();
            $table->foreignId('office_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->decimal('accuracy_m', 8, 2)->nullable();
            $table->decimal('distance_m', 10, 2)->nullable();
            $table->string('photo_path')->nullable();
            $table->timestamps();
            $table->index(['result', 'scheduled_at']);
            $table->index(['employee_id', 'scheduled_at']);
        });

        Schema::create('attendance_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->time('check_in_time')->nullable();
            $table->time('check_out_time')->nullable();
            $table->text('reason');
            $table->string('attachment_path')->nullable();
            $table->string('status')->default('pending');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->string('review_note')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_corrections');
        Schema::dropIfExists('presence_checks');
        Schema::dropIfExists('exit_permits');
    }
};
