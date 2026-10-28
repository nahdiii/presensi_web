<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\LeaveStatus;
use App\Enums\LeaveType;
use App\Http\Controllers\Controller;
use App\Http\Resources\LeaveRequestResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class LeaveController extends Controller
{
    /** Pengajuan izin milik sendiri. */
    public function index(Request $request): AnonymousResourceCollection
    {
        return LeaveRequestResource::collection(
            $request->user()->employee->leaveRequests()->with('approver')->latest()->limit(100)->get()
        );
    }

    /** Ajukan izin, sakit, cuti, atau tugas luar; disetujui kepala unit. */
    public function store(Request $request): LeaveRequestResource
    {
        $data = $request->validate([
            'type' => ['required', Rule::enum(LeaveType::class)],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'reason' => ['required', 'string', 'max:1000'],
            'attachment' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:2048'],
        ]);
        $employee = $request->user()->employee;
        $path = $request->file('attachment')?->store("leaves/{$employee->id}", config('attendance.photo_disk'));

        $leave = $employee->leaveRequests()->create([
            'institution_id' => $employee->institution_id,
            'type' => $data['type'],
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'reason' => $data['reason'],
            'attachment_path' => $path,
            'status' => LeaveStatus::Pending,
        ]);

        return new LeaveRequestResource($leave);
    }
}
