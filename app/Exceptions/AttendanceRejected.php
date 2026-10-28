<?php

namespace App\Exceptions;

use App\Enums\RejectReason;
use Exception;
use Illuminate\Http\JsonResponse;

/** Absen ditolak oleh salah satu cek di AttendanceService. */
class AttendanceRejected extends Exception
{
    public function __construct(public readonly RejectReason $reason, string $message)
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(
            ['error' => ['code' => $this->reason->value, 'message' => $this->getMessage()]],
            $this->reason->httpStatus(),
        );
    }
}
