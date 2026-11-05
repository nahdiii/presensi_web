<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Enums\LeaveStatus;
use App\Models\ActivityLog;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Notifications\Push\PushNotifier;
use App\Support\AttachmentFingerprint;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LeaveService
{
    public function __construct(
        private readonly ShiftResolver $shifts,
        private readonly PushNotifier $push,
    ) {}

    /**
     * Pengajuan baru dari pegawai, sendiri atau bersama rekan ($members, hanya
     * untuk jenis yang allowsGroup()). Setiap anggota mendapat pengajuan
     * sendiri (satu group_id) yang disetujui kepala unitnya masing-masing.
     * Semua aturan dicek untuk setiap anggota; satu saja gagal, tidak ada yang
     * tersimpan.
     *
     * Lampiran yang isinya sama persis dengan lampiran pengajuan lain yang
     * masih berlaku ditolak; foto yang sekadar mirip ditandai untuk penyetuju.
     *
     * Potongan per hari jenis izin disalin ke setiap pengajuan saat ini juga,
     * sehingga perubahan di master Jenis Izin tidak mengubah pengajuan lama.
     *
     * @param  array{type: string, start_date: string, end_date: string, reason: string}  $data  type = kode jenis
     * @param  Collection<int, Employee>|null  $members  rekan selain $employee
     * @return Collection<int, LeaveRequest> pengajuan $employee lebih dulu, lalu rekan
     */
    public function submit(Employee $employee, array $data, ?UploadedFile $attachment = null, ?Collection $members = null): Collection
    {
        $type = LeaveType::where('institution_id', $employee->institution_id)->where('code', $data['type'])->where('is_active', true)->first()
            ?? throw ValidationException::withMessages(['type' => 'Jenis izin tidak tersedia.']);
        if ($type->requires_attachment && ! $attachment) {
            throw ValidationException::withMessages(['attachment' => "Lampiran wajib untuk {$type->name}."]);
        }
        $start = CarbonImmutable::parse($data['start_date']);
        $end = CarbonImmutable::parse($data['end_date']);
        $members = ($members ?? collect())->reject(fn (Employee $m) => $m->id === $employee->id)->unique('id')->values();
        if ($members->isNotEmpty() && ! $type->allowsGroup()) {
            throw ValidationException::withMessages(['member_ids' => "{$type->name} tidak bisa diajukan bersama rekan; setiap orang mengajukan sendiri."]);
        }

        $people = collect([$employee])->concat($members);
        $days = $people->mapWithKeys(fn (Employee $e) => [$e->id => $this->check($e, $type, $start, $end, $e->id !== $employee->id)]);
        [$hash, $phash, $similar] = $attachment ? $this->fingerprint($employee, $attachment) : [null, null, null];

        $leaves = DB::transaction(function () use ($employee, $people, $days, $type, $start, $end, $data, $attachment, $hash, $phash, $similar) {
            $path = $attachment?->store("leaves/{$employee->id}", config('attendance.photo_disk'));
            $group = $people->count() > 1 ? (string) Str::uuid() : null;

            return $people->map(fn (Employee $e) => $e->leaveRequests()->create([
                'institution_id' => $e->institution_id,
                'group_id' => $group,
                'submitted_by' => $employee->id,
                'leave_type_id' => $type->id,
                'type' => $type->code,
                'deduction_per_day' => $type->deduction_per_day,
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'days' => $days[$e->id],
                'reason' => $data['reason'],
                'attachment_path' => $path,
                'attachment_hash' => $hash,
                'attachment_phash' => $phash,
                'similar_leave_request_id' => $similar?->id,
                'status' => LeaveStatus::Pending,
            ]));
        });

        $first = $leaves->first();
        ActivityLog::record($employee->user, 'request', "Mengajukan {$type->getLabel()} {$first->rangeLabel()}"
            .($members->isNotEmpty() ? ' bersama '.$members->map->name->implode(', ') : ''));
        foreach ($leaves->skip(1) as $leave) {
            $this->push->send($leave->employee, "{$type->getLabel()} untuk Anda",
                "{$employee->name} mengajukan {$type->getLabel()} bersama untuk {$leave->rangeLabel()}. Menunggu persetujuan.",
                ['type' => 'leave', 'id' => (string) $leave->id]);
        }

        return $leaves;
    }

    /**
     * Aturan pengajuan untuk satu orang; mengembalikan jumlah hari kerjanya.
     * Pesan untuk rekan diawali namanya supaya pengaju tahu siapa yang bermasalah.
     */
    private function check(Employee $e, LeaveType $type, CarbonImmutable $start, CarbonImmutable $end, bool $isMember): int
    {
        $fail = fn (string $field, string $message) => throw ValidationException::withMessages([
            $isMember ? 'member_ids' : $field => $isMember ? "{$e->name}: ".lcfirst($message) : $message,
        ]);

        if ($isMember && ! $e->user?->is_active) {
            $fail('member_ids', 'Akun tidak aktif.');
        }
        if ($start->lt($earliest = self::earliestStart())) {
            $fail('start_date', "Tanggal mulai paling awal {$earliest->translatedFormat('d M Y')}. Izin hanya bisa diajukan paling lama "
                .config('attendance.leave.backdate_window_days').' hari ke belakang, termasuk hari ini.');
        }
        $clash = $e->leaveRequests()
            ->whereIn('status', [LeaveStatus::Pending, LeaveStatus::Approved])
            ->whereDate('start_date', '<=', $end->toDateString())
            ->whereDate('end_date', '>=', $start->toDateString())
            ->first();
        if ($clash) {
            $fail('start_date', "Bertumpuk dengan pengajuan {$clash->typeLabel()} {$clash->rangeLabel()} ({$clash->status->getLabel()}).");
        }
        $perYear = $this->workdaysPerYear($e, $start, $end);
        $days = array_sum($perYear);
        if ($days === 0) {
            $fail('end_date', 'Rentang tanggal ini tidak berisi hari kerja.');
        }
        if ($type->hasQuota()) {
            foreach ($perYear as $year => $n) {
                $left = $this->quota($e, $year, $type)['remaining'];
                if ($n > $left) {
                    $fail('end_date', 'Sisa '.lcfirst($type->name)." tahun $year tinggal $left hari kerja; pengajuan ini $n hari.");
                }
            }
        }

        return $days;
    }

    /**
     * Sidik lampiran. File yang sama persis dengan lampiran pengajuan lain yang
     * menunggu/disetujui ditolak; gambar yang mirip dikembalikan sebagai
     * $similar untuk ditandai.
     *
     * @return array{0: string, 1: ?string, 2: ?LeaveRequest}
     */
    private function fingerprint(Employee $employee, UploadedFile $file): array
    {
        $bytes = (string) file_get_contents($file->getRealPath());
        $hash = AttachmentFingerprint::exact($bytes);
        $active = fn () => LeaveRequest::where('institution_id', $employee->institution_id)
            ->whereIn('status', [LeaveStatus::Pending, LeaveStatus::Approved]);

        // Bila file itu juga pernah dipakai sendiri (mis. di izin bersama), pesannya untuk pengajuan sendiri.
        $same = $active()->where('attachment_hash', $hash)
            ->orderByRaw('employee_id = ? desc', [$employee->id])->latest('id')->first();
        if ($same) {
            throw ValidationException::withMessages(['attachment' => $same->employee_id === $employee->id
                ? "Lampiran ini sudah Anda pakai untuk pengajuan {$same->typeLabel()} {$same->rangeLabel()}. Unggah dokumen untuk izin ini."
                : "Lampiran ini sudah dipakai di pengajuan pegawai lain ({$same->typeLabel()} {$same->rangeLabel()}). "
                    .'Bila izinnya bersama, cukup satu orang mengajukan dan menambahkan rekan.']);
        }

        $phash = AttachmentFingerprint::perceptual($bytes);
        $similar = null;
        if ($phash) {
            $best = AttachmentFingerprint::SIMILAR_MAX_DISTANCE + 1;
            $active()->whereNotNull('attachment_phash')->where('created_at', '>=', now()->subYear())
                ->select(['id', 'attachment_phash'])->orderByDesc('id')
                ->each(function (LeaveRequest $other) use ($phash, &$best, &$similar) {
                    $d = AttachmentFingerprint::distance($phash, $other->attachment_phash);
                    if ($d < $best) {
                        [$best, $similar] = [$d, $other];
                    }
                });
        }

        return [$hash, $phash, $similar];
    }

    /** Tanggal mulai paling awal yang boleh diajukan hari ini. */
    public static function earliestStart(): CarbonImmutable
    {
        return CarbonImmutable::now(config('attendance.timezone'))->startOfDay()
            ->subDays(max(1, config('attendance.leave.backdate_window_days')) - 1);
    }

    /**
     * Jatah per tahun dalam hari kerja untuk jenis ini (bawaan: cuti tahunan).
     * Pengajuan yang masih menunggu ikut mengurangi sisa supaya tidak bisa
     * mengajukan melebihi jatah.
     *
     * @return array{year: int, quota: int, used: int, pending: int, remaining: int}
     */
    public function quota(Employee $employee, int $year, ?LeaveType $type = null): array
    {
        $type ??= LeaveType::findCode($employee->institution_id, LeaveType::ANNUAL_LEAVE);
        $quota = ($type ? $type->quotaFor($employee) : null) ?? config('attendance.leave.annual_quota_days');
        $used = $pending = 0;
        $leaves = $employee->leaveRequests()
            ->where('leave_type_id', $type?->id)
            ->whereIn('status', [LeaveStatus::Pending, LeaveStatus::Approved])
            ->whereDate('start_date', '<=', "$year-12-31")
            ->whereDate('end_date', '>=', "$year-01-01")
            ->get();
        foreach ($leaves as $leave) {
            // days sudah dikurangi hari yang dibatalkan; untuk rentang lintas tahun dihitung ulang.
            $n = $leave->days !== null && $leave->start_date->year === $year && $leave->end_date->year === $year
                ? $leave->days
                : max(0, ($this->workdaysPerYear($employee, $leave->start_date->toImmutable(), $leave->end_date->toImmutable())[$year] ?? 0)
                    - collect($leave->cancelled_dates ?? [])->filter(fn (string $d) => str_starts_with($d, "$year-"))->count());
            $leave->status === LeaveStatus::Approved ? $used += $n : $pending += $n;
        }

        return ['year' => $year, 'quota' => $quota, 'used' => $used, 'pending' => $pending, 'remaining' => max(0, $quota - $used - $pending)];
    }

    /** Jumlah hari kerja pegawai dalam rentang (bukan libur menurut jadwal/kalender). */
    public function workdays(Employee $employee, CarbonImmutable $start, CarbonImmutable $end): int
    {
        return array_sum($this->workdaysPerYear($employee, $start, $end));
    }

    /** @return array<int, int> hari kerja (bukan libur menurut jadwal/kalender) per tahun */
    private function workdaysPerYear(Employee $employee, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $days = [];
        foreach (CarbonPeriod::create($start, $end) as $day) {
            $days[$day->year] ??= 0;
            if (! $this->shifts->resolve($employee, $day->toImmutable())->isDayOff) {
                $days[$day->year]++;
            }
        }

        return $days;
    }

    /**
     * Setujui izin dan tandai hari-hari kerja dalam rentangnya. Hari yang
     * sudah ada absen masuknya tidak ditimpa.
     */
    public function approve(LeaveRequest $leave, User $approver, ?string $note = null): void
    {
        DB::transaction(function () use ($leave, $approver, $note) {
            $leave->update([
                'status' => LeaveStatus::Approved,
                'approved_by' => $approver->id,
                'approved_at' => now(),
                'review_note' => $note,
            ]);

            $employee = $leave->employee;
            foreach (CarbonPeriod::create($leave->start_date, $leave->end_date) as $day) {
                $schedule = $this->shifts->resolve($employee, $day->toImmutable());
                if ($schedule->isDayOff) {
                    continue;
                }
                $row = Attendance::firstOrNew(['employee_id' => $employee->id, 'date' => $day->toDateString()]);
                if ($row->check_in_at) {
                    continue;
                }
                $row->fill([
                    'institution_id' => $employee->institution_id,
                    'leave_request_id' => $leave->id,
                    'status' => $leave->leaveType->attendanceStatus(),
                    'schedule_snapshot' => $schedule->toArray(),
                    'note' => $leave->typeLabel().': '.str($leave->reason)->limit(150),
                ])->save();
            }
            ActivityLog::record($approver, 'review', "Menyetujui {$leave->typeLabel()} {$employee->name} {$leave->rangeLabel()}");
        });

        $this->notify($leave, true);
    }

    /**
     * Batalkan satu hari dari izin yang sudah disetujui karena pegawai ternyata
     * masuk kerja. Pengajuan tetap berlaku untuk hari lain; jumlah hari kerja
     * berkurang satu sehingga jatah dan potongan hari itu kembali. Baris izin
     * hari itu dihapus (hari ini/ke depan) supaya pegawai bisa absen, atau
     * menjadi alpa bila tanggalnya sudah lewat dan tidak ada absen.
     *
     * Pegawai hanya boleh membatalkan hari ini (dicek di controller); Admin SDM
     * boleh hari mana pun dalam rentang izin.
     */
    public function cancelDay(LeaveRequest $leave, string $date, User $by, ?string $note = null): void
    {
        $employee = $leave->employee;
        $fail = fn (string $message) => throw ValidationException::withMessages(['date' => $message]);
        if ($leave->status !== LeaveStatus::Approved) {
            $fail('Hanya izin yang sudah disetujui yang bisa dibatalkan per hari.');
        }
        if ($date < $leave->start_date->toDateString() || $date > $leave->end_date->toDateString()) {
            $fail('Tanggal itu tidak termasuk dalam izin ini.');
        }
        if ($leave->isCancelledOn($date)) {
            $fail('Hari itu sudah dibatalkan.');
        }
        $schedule = $this->shifts->resolve($employee, $date);
        if ($schedule->isDayOff) {
            $fail('Tanggal itu bukan hari kerja.');
        }
        $label = CarbonImmutable::parse($date)->translatedFormat('d M Y');
        $byEmployee = $by->id === $employee->user_id;

        DB::transaction(function () use ($leave, $date, $by, $note, $employee, $schedule, $label, $byEmployee) {
            $leave->update([
                'cancelled_dates' => collect($leave->cancelled_dates ?? [])->push($date)->sort()->values()->all(),
                'days' => $leave->days !== null ? max(0, $leave->days - 1) : null,
            ]);
            $row = Attendance::where('employee_id', $employee->id)->whereDate('date', $date)
                ->where('leave_request_id', $leave->id)->whereNull('check_in_at')->first();
            $today = CarbonImmutable::now(config('attendance.timezone'))->toDateString();
            if ($row && $date >= $today) {
                $row->delete(); // Pegawai absen seperti biasa; tutup hari menandai alpa bila tidak.
            } elseif ($row) {
                $row->update(['leave_request_id' => null, 'status' => AttendanceStatus::Absent,
                    'schedule_snapshot' => $schedule->toArray(), 'note' => "{$leave->typeLabel()} dibatalkan"]);
            }
            ActivityLog::record($by, $byEmployee ? 'request' : 'review',
                "Membatalkan {$leave->typeLabel()} {$employee->name} untuk $label".($note ? ": $note" : ''));
        });

        if ($byEmployee) {
            app(ApprovalNotifier::class)->leaveDayCancelled($leave, $label);
        } else {
            $this->push->send($employee, "{$leave->typeLabel()} $label dibatalkan",
                "Admin SDM membatalkan izin Anda untuk $label".($note ? ": $note" : '').'. Silakan absen seperti biasa.',
                ['type' => 'leave', 'id' => (string) $leave->id]);
        }
    }

    public function reject(LeaveRequest $leave, User $approver, ?string $note = null): void
    {
        $leave->update([
            'status' => LeaveStatus::Rejected,
            'approved_by' => $approver->id,
            'approved_at' => now(),
            'review_note' => $note,
        ]);
        ActivityLog::record($approver, 'review', "Menolak {$leave->typeLabel()} {$leave->employee->name} {$leave->rangeLabel()}");
        $this->notify($leave, false);
    }

    private function notify(LeaveRequest $leave, bool $approved): void
    {
        $this->push->send($leave->employee, $leave->typeLabel().' '.($approved ? 'disetujui' : 'ditolak'),
            $leave->start_date->translatedFormat('d M').' – '.$leave->end_date->translatedFormat('d M Y').($leave->review_note ? ': '.$leave->review_note : ''),
            ['type' => 'leave', 'id' => (string) $leave->id]);
    }
}
