<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\LeaveStatus;
use App\Enums\WorkPatternType;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\ExitPermit;
use App\Models\PresenceCheck;
use App\Models\User;
use App\Notifications\Push\PushNotifier;
use App\Services\CorrectionService;
use App\Services\PresenceCheckService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsCampus;
use Tests\Support\FakePushNotifier;
use Tests\TestCase;

/** Fase 2: cek kehadiran acak, izin keluar, koreksi lupa absen, pengingat. */
class PhaseTwoTest extends TestCase
{
    use BuildsCampus, RefreshDatabase;

    private FakePushNotifier $push;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('attendance');
        Queue::fake();
        $this->push = new FakePushNotifier;
        $this->app->instance(PushNotifier::class, $this->push);
        $this->buildCampus();
    }

    private function api(\App\Models\Employee $e)
    {
        Sanctum::actingAs($e->user);

        return $this->withHeader('X-Device-UID', $this->deviceUids[$e->id])->withHeader('Accept', 'application/json');
    }

    // ---------- Cek kehadiran acak ----------

    public function test_check_in_plans_one_to_three_checks_inside_working_hours_outside_break(): void
    {
        foreach (range(1, 5) as $_) {
            $e = $this->makeEmployee();
            $this->at('2026-10-28 07:20');
            $this->punch($e)->assertCreated();

            $checks = PresenceCheck::where('employee_id', $e->id)->get();
            $this->assertGreaterThanOrEqual(1, $checks->count());
            $this->assertLessThanOrEqual(3, $checks->count());
            foreach ($checks as $c) {
                $t = $c->scheduled_at->setTimezone('Asia/Makassar')->format('H:i');
                $this->assertTrue($t >= '08:30' && $t <= '15:00', "jam cek $t di luar jendela");
                $this->assertFalse($t >= '11:45' && $t <= '13:15', "jam cek $t saat istirahat");
            }
        }
    }

    public function test_flexible_lecturers_get_no_random_checks(): void
    {
        $flex = $this->makePattern('Dosen Fleksibel', WorkPatternType::Flexible, array_fill(1, 5, ['06:00', '21:00']), minDaily: 360);
        $e = $this->makeEmployee(['work_pattern_id' => $flex->id, 'attendance_type' => 'flexible']);
        $this->at('2026-10-28 08:00');
        $this->punch($e)->assertCreated();

        $this->assertSame(0, PresenceCheck::count());
    }

    public function test_due_checks_are_sent_expired_ones_marked_no_response(): void
    {
        $e = $this->makeEmployee();
        $this->at('2026-10-28 07:20');
        $this->punch($e)->assertCreated();
        $first = PresenceCheck::where('employee_id', $e->id)->orderBy('scheduled_at')->first();

        $this->at($first->scheduled_at->setTimezone('Asia/Makassar')->format('Y-m-d H:i'));
        app(PresenceCheckService::class)->dispatchDue();
        $this->assertSame(PresenceCheck::PENDING, $first->fresh()->result);
        $this->assertSame('presence_check', $this->push->sent[0]['data']['type']);

        $this->api($e)->getJson('/api/v1/attendance/today')
            ->assertJsonPath('data.presence_check.id', $first->id)
            ->assertJsonPath('data.presence_check.seconds_left', 900);

        CarbonImmutable::setTestNow(now()->addMinutes(16));
        app(PresenceCheckService::class)->dispatchDue();
        $this->assertSame(PresenceCheck::NO_RESPONSE, $first->fresh()->result);
    }

    public function test_responding_inside_radius_is_ok_and_outside_is_reported(): void
    {
        $a = $this->makeEmployee();
        $b = $this->makeEmployee();
        $this->at('2026-10-28 07:20');
        $this->punch($a)->assertCreated();
        $this->punch($b)->assertCreated();
        PresenceCheck::query()->update(['scheduled_at' => now()->subMinute()]);
        $this->at('2026-10-28 10:00');
        app(PresenceCheckService::class)->dispatchDue();

        $checkA = PresenceCheck::where('employee_id', $a->id)->where('result', 'pending')->first();
        $this->api($a)->post("/api/v1/presence-checks/{$checkA->id}/respond", $this->evidence())
            ->assertOk()->assertJsonPath('data.result', 'ok');

        $checkB = PresenceCheck::where('employee_id', $b->id)->where('result', 'pending')->first();
        $this->api($b)->post("/api/v1/presence-checks/{$checkB->id}/respond", $this->evidence(['latitude' => self::LAT + 0.01]))
            ->assertStatus(422)->assertJsonPath('error.code', 'out_of_radius');
        $this->assertSame(PresenceCheck::OUT_OF_RADIUS, $checkB->fresh()->result);

        // Cek milik orang lain atau yang sudah dijawab tidak bisa dipakai.
        $this->api($b)->post("/api/v1/presence-checks/{$checkA->id}/respond", $this->evidence())
            ->assertStatus(422)->assertJsonPath('error.code', 'check_expired');
    }

    public function test_checks_are_skipped_during_exit_permit_and_cancelled_after_check_out(): void
    {
        $e = $this->makeEmployee();
        $this->at('2026-10-28 07:20');
        $this->punch($e)->assertCreated();
        $this->api($e)->postJson('/api/v1/exit-permits', ['reason' => 'Ke bank'])->assertCreated();

        PresenceCheck::query()->update(['scheduled_at' => now()->subMinute()]);
        app(PresenceCheckService::class)->dispatchDue();
        $this->assertSame([PresenceCheck::SKIPPED], PresenceCheck::pluck('result')->unique()->values()->all());

        $e2 = $this->makeEmployee();
        $this->punch($e2)->assertCreated();
        $this->at('2026-10-28 16:05');
        $this->punch($e2, 'out')->assertOk();
        $this->assertSame([PresenceCheck::CANCELLED], PresenceCheck::where('employee_id', $e2->id)->pluck('result')->unique()->values()->all());
    }

    // ---------- Izin keluar ----------

    public function test_exit_permit_flow(): void
    {
        $e = $this->makeEmployee();
        $this->at('2026-10-28 07:20');
        $this->api($e)->postJson('/api/v1/exit-permits', ['reason' => 'Ke bank'])
            ->assertStatus(422)->assertJsonPath('error.code', 'not_checked_in');

        $this->punch($e)->assertCreated();
        $this->at('2026-10-28 10:00');
        $id = $this->api($e)->postJson('/api/v1/exit-permits', ['reason' => 'Ke bank'])->assertCreated()->json('data.id');
        $this->api($e)->postJson('/api/v1/exit-permits', ['reason' => 'Lagi'])
            ->assertStatus(422)->assertJsonPath('error.code', 'exit_permit_active');
        $this->api($e)->getJson('/api/v1/attendance/today')
            ->assertJsonPath('data.exit_permit.id', $id)->assertJsonPath('data.can_exit', true);

        $loc = ['latitude' => self::LAT, 'longitude' => self::LNG, 'accuracy' => 10, 'is_mocked' => 0];
        $this->api($e)->postJson("/api/v1/exit-permits/$id/return", ['latitude' => self::LAT + 0.01] + $loc)
            ->assertStatus(422)->assertJsonPath('error.code', 'out_of_radius');

        $this->at('2026-10-28 10:45');
        $this->api($e)->postJson("/api/v1/exit-permits/$id/return", $loc)->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.back_at', '2026-10-28T10:45:00+08:00');
    }

    public function test_check_out_closes_an_open_exit_permit(): void
    {
        $e = $this->makeEmployee();
        $this->at('2026-10-28 07:20');
        $this->punch($e)->assertCreated();
        $this->at('2026-10-28 15:00');
        $this->api($e)->postJson('/api/v1/exit-permits', ['reason' => 'Rapat di luar'])->assertCreated();
        $this->at('2026-10-28 16:05');
        $this->punch($e, 'out')->assertOk();

        $this->assertNotNull(ExitPermit::sole()->back_at);
    }

    // ---------- Koreksi lupa absen ----------

    public function test_correction_validation(): void
    {
        $e = $this->makeEmployee();
        $this->at('2026-10-28 09:00');

        $this->api($e)->postJson('/api/v1/corrections', ['date' => '2026-10-29', 'check_in_time' => '07:30', 'reason' => 'x'])
            ->assertStatus(422);
        $this->api($e)->postJson('/api/v1/corrections', ['date' => '2026-10-27', 'check_out_time' => '16:00', 'reason' => 'Lupa'])
            ->assertStatus(422)->assertJsonPath('error.message', 'Hari itu belum ada absen masuk; isi juga jam masuk.');
        $this->api($e)->postJson('/api/v1/corrections', ['date' => '2026-10-27', 'check_in_time' => '07:35', 'check_out_time' => '16:10', 'reason' => 'HP mati'])
            ->assertCreated();
        $this->api($e)->postJson('/api/v1/corrections', ['date' => '2026-10-27', 'check_in_time' => '07:35', 'reason' => 'dobel'])
            ->assertStatus(422);
    }

    public function test_approved_correction_is_applied_with_recomputed_status(): void
    {
        $e = $this->makeEmployee();
        $head = User::create(['name' => 'Kepala', 'email' => 'kepala@kampus.test', 'password' => 'x']);

        // Lupa absen pulang: incomplete → tepat waktu setelah koreksi.
        $this->at('2026-10-27 07:20');
        $this->punch($e)->assertCreated();
        Attendance::sole()->update(['status' => AttendanceStatus::Incomplete]);
        $c1 = AttendanceCorrection::create(['employee_id' => $e->id, 'date' => '2026-10-27', 'check_out_time' => '16:05', 'reason' => 'Lupa', 'status' => LeaveStatus::Pending]);
        $a = app(CorrectionService::class)->approve($c1, $head);
        $this->assertSame(AttendanceStatus::Present, $a->status);
        $this->assertSame(465, $a->worked_minutes); // 07.20–16.05 dikurangi istirahat 60 menit

        // Tidak ada catatan sama sekali: dibuat dari koreksi, terlambat dihitung.
        $c2 = AttendanceCorrection::create(['employee_id' => $e->id, 'date' => '2026-10-26', 'check_in_time' => '08:00', 'check_out_time' => '16:00', 'reason' => 'Server down', 'status' => LeaveStatus::Pending]);
        $b = app(CorrectionService::class)->approve($c2, $head);
        $this->assertSame(AttendanceStatus::Late, $b->status);
        $this->assertSame(30, $b->late_minutes);
        $this->assertSame(LeaveStatus::Approved, $c2->fresh()->status);
        $this->assertSame('correction', collect($this->push->sent)->last()['data']['type']);
    }

    // ---------- Pengingat & notifikasi ----------

    public function test_reminders_are_sent_once(): void
    {
        $e = $this->makeEmployee();
        $e->activeDevice->update(['fcm_token' => 'token-uji']);

        $this->at('2026-10-28 07:20');
        $this->artisan('attendance:remind')->assertSuccessful();
        $this->artisan('attendance:remind')->assertSuccessful();
        $this->assertCount(1, $this->push->sent);
        $this->assertSame('Jangan lupa absen masuk', $this->push->sent[0]['title']);

        $this->punch($e)->assertCreated();
        $this->at('2026-10-28 16:05');
        $this->artisan('attendance:remind')->assertSuccessful();
        $this->assertSame('Jangan lupa absen pulang', collect($this->push->sent)->last()['title']);
    }

    public function test_fcm_notifier_signs_and_sends(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($key, $pem);
        $path = tempnam(sys_get_temp_dir(), 'fcm');
        file_put_contents($path, json_encode(['project_id' => 'kampus-uji', 'client_email' => 'svc@kampus-uji.iam.gserviceaccount.com', 'private_key' => $pem]));
        \Illuminate\Support\Facades\Http::fake([
            'oauth2.googleapis.com/*' => ['access_token' => 'akses'],
            'fcm.googleapis.com/*' => ['name' => 'projects/kampus-uji/messages/1'],
        ]);
        $e = $this->makeEmployee();
        $e->activeDevice->update(['fcm_token' => 'token-hp']);

        (new \App\Notifications\Push\FcmPushNotifier($path))->send($e->fresh(), 'Judul', 'Isi', ['type' => 'x']);

        \Illuminate\Support\Facades\Http::assertSent(fn ($r) => str_contains($r->url(), 'projects/kampus-uji/messages:send')
            && $r['message']['token'] === 'token-hp' && $r->hasHeader('Authorization', 'Bearer akses'));
        @unlink($path);
    }

    private function evidence(array $overrides = []): array
    {
        return $overrides + [
            'latitude' => self::LAT, 'longitude' => self::LNG, 'accuracy' => 10, 'is_mocked' => 0,
            'face_count' => 1, 'photo' => UploadedFile::fake()->image('s.jpg'),
        ];
    }
}
