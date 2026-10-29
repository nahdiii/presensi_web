<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\LeaveStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\CorrectionResource;
use App\Models\Attendance;
use App\Services\ApprovalNotifier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/** Koreksi lupa absen, disetujui pimpinan unit lewat panel admin. */
class CorrectionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return CorrectionResource::collection(
            $request->user()->employee->corrections()->with('approver')->latest()->limit(50)->get()
        );
    }

    public function store(Request $request): CorrectionResource
    {
        $tz = config('attendance.timezone');
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d',
                'before_or_equal:'.now($tz)->toDateString(),
                'after_or_equal:'.now($tz)->subDays(config('attendance.correction_max_days'))->toDateString()],
            'check_in_time' => ['nullable', 'date_format:H:i', 'required_without:check_out_time'],
            'check_out_time' => ['nullable', 'date_format:H:i'],
            'reason' => ['required', 'string', 'max:1000'],
            'attachment' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:2048'],
        ]);
        $employee = $request->user()->employee;

        $existing = Attendance::where('employee_id', $employee->id)->whereDate('date', $data['date'])->first();
        if (empty($data['check_in_time']) && ! $existing?->check_in_at) {
            throw ValidationException::withMessages(['check_in_time' => 'Hari itu belum ada absen masuk; isi juga jam masuk.']);
        }
        if ($employee->corrections()->whereDate('date', $data['date'])->where('status', LeaveStatus::Pending)->exists()) {
            throw ValidationException::withMessages(['date' => 'Sudah ada koreksi yang menunggu untuk tanggal ini.']);
        }

        $path = $request->file('attachment')?->store("corrections/{$employee->id}", config('attendance.photo_disk'));

        $correction = $employee->corrections()->create([
            'institution_id' => $employee->institution_id,
            'date' => $data['date'],
            'check_in_time' => $data['check_in_time'] ?? null,
            'check_out_time' => $data['check_out_time'] ?? null,
            'reason' => $data['reason'],
            'attachment_path' => $path,
            'status' => LeaveStatus::Pending,
        ]);
        app(ApprovalNotifier::class)->correctionSubmitted($correction);

        return new CorrectionResource($correction);
    }
}
