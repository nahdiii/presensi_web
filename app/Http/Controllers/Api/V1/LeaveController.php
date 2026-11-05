<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\LeaveType;
use App\Http\Controllers\Controller;
use App\Http\Resources\LeaveRequestResource;
use App\Models\DeductionRuleSet;
use App\Models\Employee;
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
     * beserta sisa cuti tahun ini dan potongan per hari kerja tiap jenis.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $employee = $request->user()->employee;
        $today = now(config('attendance.timezone'));
        $rules = DeductionRuleSet::forDate($employee->institution_id, $today->toDateString());

        return LeaveRequestResource::collection($employee->leaveRequests()
            ->with(['approver', 'submitter.user', 'groupMembers.employee.user'])->latest()->limit(100)->get())
            ->additional(['meta' => [
                'leave_quota' => app(LeaveService::class)->quota($employee, $today->year),
                'leave_deductions' => collect(LeaveType::cases())->mapWithKeys(fn (LeaveType $t) => [$t->value => $rules?->leaveAmount($t) ?? 0]),
                'group_types' => collect(LeaveType::cases())->filter->allowsGroup()->map->value->values(),
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
            'type' => ['required', Rule::enum(LeaveType::class)],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'reason' => ['required', 'string', 'max:1000'],
            'attachment' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:2048'],
            'member_ids' => ['nullable', 'array', 'max:30'],
            'member_ids.*' => ['integer', 'distinct', Rule::exists('employees', 'id')->where('institution_id', $employee->institution_id)],
        ], ['member_ids.*.exists' => 'Rekan tidak ditemukan.', 'member_ids.max' => 'Paling banyak 30 rekan dalam satu pengajuan.']);

        $members = Employee::with('user')->whereIn('id', $data['member_ids'] ?? [])->get();
        $leaves = app(LeaveService::class)->submit($employee, $data, $request->file('attachment'), $members);
        app(ApprovalNotifier::class)->leavesSubmitted($leaves);

        return new LeaveRequestResource($leaves->first()->load(['submitter.user', 'groupMembers.employee.user']));
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
