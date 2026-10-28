<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\MeResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class MeController extends Controller
{
    /** Profil, unit, lokasi absen yang diizinkan, dan perangkat terikat. */
    public function show(Request $request): MeResource
    {
        return new MeResource($request->user()->employee->load(['user', 'unit', 'offices', 'activeDevice']));
    }

    public function password(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'current_password:sanctum'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);
        $user = $request->user();
        $user->update(['password' => $request->input('password')]);
        // Token lain dicabut; token perangkat ini tetap dipakai.
        $user->tokens()->where('id', '!=', $user->currentAccessToken()->id)->delete();

        return response()->json(['data' => ['ok' => true]]);
    }

    /** Simpan token Firebase Cloud Messaging untuk notifikasi (fase 2). */
    public function fcmToken(Request $request): JsonResponse
    {
        $request->validate(['token' => ['required', 'string', 'max:4096']]);
        $request->user()->employee->activeDevice->update(['fcm_token' => $request->input('token')]);

        return response()->json(['data' => ['ok' => true]]);
    }
}
