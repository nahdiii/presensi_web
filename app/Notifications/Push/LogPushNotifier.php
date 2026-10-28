<?php

namespace App\Notifications\Push;

use App\Models\Employee;
use Illuminate\Support\Facades\Log;

/** Dipakai bila Firebase belum dikonfigurasi: notifikasi hanya dicatat di log. */
class LogPushNotifier implements PushNotifier
{
    public function send(Employee $employee, string $title, string $body, array $data = []): void
    {
        Log::info('push (FCM belum dikonfigurasi)', [
            'employee_id' => $employee->id, 'title' => $title, 'body' => $body, 'data' => $data,
        ]);
    }
}
