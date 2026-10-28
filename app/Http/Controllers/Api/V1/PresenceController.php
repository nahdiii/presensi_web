<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LocationRequest;
use App\Http\Requests\PunchRequest;
use App\Http\Resources\ExitPermitResource;
use App\Http\Resources\PresenceCheckResource;
use App\Models\ExitPermit;
use App\Models\PresenceCheck;
use App\Services\ExitPermitService;
use App\Services\PresenceCheckService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Cek kehadiran acak dan izin keluar sementara (fase 2). */
class PresenceController extends Controller
{
    public function __construct(
        private readonly PresenceCheckService $checks,
        private readonly ExitPermitService $permits,
    ) {}

    /** Cek kehadiran yang sedang menunggu jawaban, atau null. */
    public function pending(Request $request): JsonResponse
    {
        $check = $this->checks->pendingFor($request->user()->employee);

        return response()->json(['data' => $check ? new PresenceCheckResource($check) : null]);
    }

    /** "Saya di kampus": lokasi + selfie, sama seperti absen. */
    public function respond(PunchRequest $request, PresenceCheck $presenceCheck): JsonResponse
    {
        $check = $this->checks->respond($request->user()->employee, $presenceCheck, $request->toPunch());

        return (new PresenceCheckResource($check))
            ->additional(['meta' => ['message' => 'Terima kasih, kehadiran Anda di kampus tercatat.']])
            ->response();
    }

    public function permits(Request $request): AnonymousResourceCollection
    {
        return ExitPermitResource::collection(
            $request->user()->employee->exitPermits()->latest('out_at')->limit(50)->get()
        );
    }

    /** "Keluar kampus" dengan alasan. */
    public function startPermit(Request $request): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $permit = $this->permits->start($request->user()->employee, $data['reason'], $request->header('X-Device-UID'));

        return (new ExitPermitResource($permit))
            ->additional(['meta' => ['message' => 'Izin keluar tercatat. Tekan "Kembali" saat tiba di kampus.']])
            ->response()->setStatusCode(201);
    }

    /** "Kembali" ke kampus; lokasi harus di dalam radius. */
    public function returnPermit(LocationRequest $request, ExitPermit $exitPermit): JsonResponse
    {
        abort_unless($exitPermit->employee_id === $request->user()->employee->id, 403);
        $permit = $this->permits->returnBack($request->user()->employee, $request->toPunch());

        return (new ExitPermitResource($permit))
            ->additional(['meta' => ['message' => 'Selamat datang kembali.']])
            ->response();
    }
}
