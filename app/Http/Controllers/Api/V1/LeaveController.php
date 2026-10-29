<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\LeaveType;
use App\Http\Controllers\Controller;
use App\Http\Resources\LeaveRequestResource;
use App\Services\ApprovalNotifier;
use App\Services\LeaveService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LeaveController extends Controller
{
    /** Pengajuan izin milik sendiri, beserta sisa cuti tahun ini. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $employee = $request->user()->employee;

        return LeaveRequestResource::collection($employee->leaveRequests()->with('approver')->latest()->limit(100)->get())
            ->additional(['meta' => ['leave_quota' => app(LeaveService::class)->quota($employee, now(config('attendance.timezone'))->year)]]);
    }

    /**
     * Ajukan izin, sakit, cuti, atau tugas luar; disetujui kepala unit.
     * Ditolak (422) bila bertumpuk, terlalu lama ke belakang, tanpa hari
     * kerja, atau cuti melebihi sisa jatah.
     */
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
        $service = app(LeaveService::class);
        // Cek aturan dulu supaya lampiran tidak tersimpan untuk pengajuan yang ditolak.
        $leave = DB::transaction(function () use ($request, $service, $employee, $data) {
            $leave = $service->submit($employee, $data);
            if ($file = $request->file('attachment')) {
                $leave->update(['attachment_path' => $file->store("leaves/{$employee->id}", config('attendance.photo_disk'))]);
            }

            return $leave;
        });
        app(ApprovalNotifier::class)->leaveSubmitted($leave);

        return new LeaveRequestResource($leave);
    }
}
