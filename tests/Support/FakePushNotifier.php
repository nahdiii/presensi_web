<?php

namespace Tests\Support;

use App\Models\Employee;
use App\Notifications\Push\PushNotifier;

class FakePushNotifier implements PushNotifier
{
    /** @var array<int, array{employee_id: int, title: string, body: string, data: array}> */
    public array $sent = [];

    public function send(Employee $employee, string $title, string $body, array $data = []): void
    {
        $this->sent[] = ['employee_id' => $employee->id, 'title' => $title, 'body' => $body, 'data' => $data];
    }
}
