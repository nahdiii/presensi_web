<?php

namespace App\Http\Requests;

/** Absen sesi mengajar: bukti yang sama dengan absen harian, ditambah pokok bahasan. */
class SessionPunchRequest extends PunchRequest
{
    public function rules(): array
    {
        return parent::rules() + ['topic' => ['nullable', 'string', 'max:255']];
    }

    public function attributes(): array
    {
        return parent::attributes() + ['topic' => 'pokok bahasan'];
    }
}
