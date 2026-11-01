<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;

/**
 * Peran lain dibuat lewat seeder yang hanya dijalankan sekali, jadi peran
 * baru untuk database yang sudah berjalan ditambahkan di sini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Role::findOrCreate(User::ROLE_SUPER, 'web');
    }

    public function down(): void
    {
        Role::where('name', User::ROLE_SUPER)->delete();
    }
};
