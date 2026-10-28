<?php

namespace App\Notifications\Push;

use App\Models\Employee;

/** Mengirim notifikasi ke HP pegawai (perangkat aktifnya). */
interface PushNotifier
{
    /** @param array<string, string> $data dikirim ke aplikasi untuk menentukan layar tujuan */
    public function send(Employee $employee, string $title, string $body, array $data = []): void;
}
