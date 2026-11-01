<?php

namespace App\Console\Commands;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Hanya Super Admin yang bisa memberi peran ini dari panel, jadi akun pertama
 * dibuat di sini. Password diminta di layar supaya tidak tertinggal di riwayat shell.
 */
#[Signature('super-admin:create {email} {--name=Super Admin : Nama untuk akun baru}')]
#[Description('Buat akun Super Admin, atau jadikan akun yang sudah ada Super Admin')]
class CreateSuperAdmin extends Command
{
    public function handle(): int
    {
        $institutionId = Institution::query()->value('id');
        if (! $institutionId) {
            $this->error('Belum ada institusi.');

            return self::FAILURE;
        }

        $password = (string) $this->secret('Password (minimal 8 karakter)');
        if (mb_strlen($password) < 8) {
            $this->error('Password minimal 8 karakter.');

            return self::FAILURE;
        }

        $user = User::firstOrNew(['email' => $this->argument('email')]);
        $user->fill(['password' => $password, 'is_active' => true]);
        if (! $user->exists) {
            $user->fill(['institution_id' => $institutionId, 'name' => $this->option('name')]);
        }
        $user->save();
        $user->assignRole(User::ROLE_SUPER);

        $this->info("{$user->email} sekarang Super Admin.");

        return self::SUCCESS;
    }
}
