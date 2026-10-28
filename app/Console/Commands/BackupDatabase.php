<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * pg_dump harian ke disk backup (lokal saat pengembangan, bucket R2/S3
 * terpisah di produksi) supaya data tidak bergantung pada satu penyedia.
 */
#[Signature('backup:database {--keep=14 : Jumlah berkas terbaru yang disimpan}')]
#[Description('Cadangkan database PostgreSQL ke disk backup')]
class BackupDatabase extends Command
{
    public function handle(): int
    {
        $db = config('database.connections.pgsql');
        $tmp = tempnam(sys_get_temp_dir(), 'pgdump');
        $result = Process::env(['PGPASSWORD' => $db['password']])->timeout(600)->run([
            'pg_dump', '-h', $db['host'], '-p', (string) $db['port'], '-U', $db['username'],
            '-d', $db['database'], '--format=custom', '--no-owner', '-f', $tmp,
        ]);
        if ($result->failed()) {
            $this->error('pg_dump gagal: '.$result->errorOutput());
            @unlink($tmp);

            return self::FAILURE;
        }

        $disk = Storage::disk(config('attendance.backup_disk'));
        $name = 'db/'.now()->format('Y-m-d_His').'.dump';
        $disk->put($name, fopen($tmp, 'r'));
        @unlink($tmp);

        $old = collect($disk->files('db'))->sort()->reverse()->slice((int) $this->option('keep'));
        $disk->delete($old->all());
        $this->info("Backup tersimpan: $name ({$old->count()} berkas lama dihapus)");

        return self::SUCCESS;
    }
}
