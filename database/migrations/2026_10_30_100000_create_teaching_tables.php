<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3: presensi per sesi mengajar. Jadwal diisi manual atau impor CSV;
 * kolom kode mata kuliah disiapkan untuk sinkronisasi SIAKAD nanti.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained();
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete(); // prodi pengampu
            $table->string('code');
            $table->string('name');
            $table->unsignedTinyInteger('credits')->default(2); // SKS
            $table->timestamps();
            $table->unique(['institution_id', 'code']);
        });

        Schema::create('teaching_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('class_name'); // mis. "A", "TI-3B"
            $table->unsignedTinyInteger('weekday'); // ISO: 1 = Senin … 7 = Minggu
            $table->time('start_time');
            $table->time('end_time');
            $table->foreignId('office_id')->nullable()->constrained()->nullOnDelete(); // null = lokasi absen pegawai mana pun
            $table->string('room')->nullable();
            $table->date('valid_from'); // rentang semester
            $table->date('valid_until');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['employee_id', 'weekday']);
        });

        Schema::create('teaching_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('teaching_schedule_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');
            $table->string('status'); // nilai AttendanceStatus kecuali incomplete
            // Salinan jadwal saat sesi dicatat, supaya riwayat tidak berubah bila jadwal diedit.
            $table->string('course_code');
            $table->string('course_name');
            $table->string('class_name');
            $table->string('room')->nullable();
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedInteger('late_minutes')->default(0);
            $table->timestampTz('checked_in_at')->nullable();
            $table->foreignId('office_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->decimal('accuracy_m', 8, 2)->nullable();
            $table->decimal('distance_m', 10, 2)->nullable();
            $table->string('photo_path')->nullable();
            $table->string('topic')->nullable(); // pokok bahasan
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['teaching_schedule_id', 'date']);
            $table->index(['employee_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teaching_sessions');
        Schema::dropIfExists('teaching_schedules');
        Schema::dropIfExists('courses');
    }
};
