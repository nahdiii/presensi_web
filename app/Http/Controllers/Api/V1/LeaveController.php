<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\LeaveRequestResource;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Services\ApprovalNotifier;
use App\Services\LeaveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class LeaveController extends Controller
{
    /**
     * Pengajuan izin milik sendiri (termasuk yang diajukan rekan untuk Anda),
     * beserta jenis izin yang bisa diajukan (dari master Jenis Izin), sisa
     * jatah, dan potongan per hari kerja tiap jenis.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $employee = $request->user()->employee;
        $today = now(config('attendance.timezone'));
        $service = app(LeaveService::class);
        $types = LeaveType::where('institution_id', $employee->institution_id)->active()->get();

        return LeaveRequestResource::collection($employee->leaveRequests()
            ->with(['approver', 'submitter.user', 'groupMembers.employee.user', 'groupMembers.employee.unit', 'attendances'])
            ->latest()->limit(100)->get())
            ->additional(['meta' => [
                'leave_quota' => $service->quota($employee, $today->year),
                'leave_types' => $types->map(fn (LeaveType $t) => [
                    'code' => $t->code,
                    'name' => $t->name,
                    'deduction_per_day' => $t->deduction_per_day,
                    'allows_group' => $t->allows_group,
                    'requires_attachment' => $t->requires_attachment,
                    'quota_remaining' => $t->hasQuota() ? $service->quota($employee, $today->year, $t)['remaining'] : null,
                ])->values(),
                // Bentuk lama, untuk aplikasi versi sebelumnya.
                'leave_deductions' => $types->mapWithKeys(fn (LeaveType $t) => [$t->code => $t->deduction_per_day]),
                'group_types' => $types->where('allows_group', true)->pluck('code')->values(),
                'earliest_start_date' => LeaveService::earliestStart()->toDateString(),
                'backdate_window_days' => config('attendance.leave.backdate_window_days'),
            ]]);
    }

    /**
     * Ajukan izin, sakit, cuti, atau tugas luar; disetujui kepala unit.
     * Izin dan tugas luar boleh diajukan bersama rekan (`member_ids`); tiap
     * rekan mendapat pengajuan sendiri yang disetujui kepala unitnya.
     * Ditolak (422) bila bertumpuk, terlalu lama ke belakang, tanpa hari
     * kerja, cuti melebihi sisa jatah, atau lampirannya sudah dipakai
     * pengajuan lain.
     */
    public function store(Request $request): LeaveRequestResource
    {
        $employee = $request->user()->employee;
        $data = $request->validate([
            'type' => ['required', 'string', Rule::exists('leave_types', 'code')
                ->where('institution_id', $employee->institution_id)->where('is_active', true)],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'reason' => ['required', 'string', 'max:1000'],
            'attachment' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:2048'],
            'member_ids' => ['nullable', 'array', 'max:30'],
            'member_ids.*' => ['integer', 'distinct', Rule::exists('employees', 'id')->where('institution_id', $employee->institution_id)],
        ], ['type.exists' => 'Jenis izin tidak tersedia.', 'member_ids.*.exists' => 'Rekan tidak ditemukan.', 'member_ids.max' => 'Paling banyak 30 rekan dalam satu pengajuan.']);

        $members = Employee::with('user')->whereIn('id', $data['member_ids'] ?? [])->get();
        $leaves = app(LeaveService::class)->submit($employee, $data, $request->file('attachment'), $members);
        app(ApprovalNotifier::class)->leavesSubmitted($leaves);

        return new LeaveRequestResource($leaves->first()->load(['submitter.user', 'groupMembers.employee.user', 'groupMembers.employee.unit']));
    }

    /** Cari rekan untuk izin bersama: nama atau NIP, paling sedikit 2 huruf. */
    public function colleagues(Request $request): JsonResponse
    {
        $q = trim((string) $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']])['q']);
        $q = addcslashes($q, '\\%_'); // Huruf % dan _ dicari apa adanya, bukan sebagai pola.
        $me = $request->user()->employee;

        $found = Employee::with(['user', 'unit'])
            ->where('institution_id', $me->institution_id)->whereKeyNot($me->id)
            ->whereHas('user', fn ($user) => $user->where('is_active', true))
            ->where(fn ($query) => $query->where('nip', 'like', "$q%")
                ->orWhereHas('user', fn ($user) => $user->where('name', 'ilike', "%$q%")))
            ->limit(20)->get()
            ->sortBy(fn (Employee $e) => $e->name)->values();

        return response()->json(['data' => $found->map(fn (Employee $e) => [
            'id' => $e->id, 'name' => $e->name, 'nip' => $e->nip, 'unit_name' => $e->unit?->name,
        ])]);
    }
}
