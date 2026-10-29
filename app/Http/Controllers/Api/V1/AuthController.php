<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\RejectReason;
use App\Exceptions\AttendanceRejected;
use App\Http\Controllers\Controller;
use App\Http\Resources\MeResource;
use App\Models\Device;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Login dengan email/NIP dan password. Login pertama mengikat perangkat;
     * setelah itu hanya perangkat yang sama yang bisa dipakai sampai admin
     * menekan Reset Perangkat.
     *
     * @unauthenticated
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
            'device_uid' => ['required', 'string', 'min:16', 'max:128'],
            'device_model' => ['nullable', 'string', 'max:120'],
            'platform' => ['nullable', 'in:android,ios'],
        ]);

        $user = User::where('email', $data['login'])->first()
            ?? Employee::where('nip', $data['login'])->first()?->user;
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['login' => 'Email/NIP atau password salah.']);
        }
        $employee = $user->employee;
        if (! $user->is_active || ! $employee) {
            throw new AttendanceRejected(RejectReason::InactiveAccount,
                $user->is_active ? 'Akun ini bukan akun pegawai.' : 'Akun Anda dinonaktifkan. Hubungi Bagian SDM.');
        }

        $device = DB::transaction(function () use ($employee, $data) {
            $active = $employee->devices()->whereNull('revoked_at')->lockForUpdate()->first();
            if ($active && ! hash_equals($active->device_uid, $data['device_uid'])) {
                throw new AttendanceRejected(RejectReason::UnknownDevice,
                    "Akun ini sudah terikat ke perangkat lain ({$active->model}). Minta Bagian SDM melakukan Reset Perangkat.");
            }
            // Satu HP tidak boleh dipakai absen untuk beberapa akun (titip absen).
            $taken = Device::where('device_uid', $data['device_uid'])->whereNull('revoked_at')
                ->where('employee_id', '!=', $employee->id)->exists();
            if ($taken) {
                throw new AttendanceRejected(RejectReason::UnknownDevice,
                    'Perangkat ini sudah terikat ke akun pegawai lain.');
            }

            return $active ?? $employee->devices()->create([
                'device_uid' => $data['device_uid'],
                'model' => $data['device_model'] ?? null,
                'platform' => $data['platform'] ?? null,
                'bound_at' => now(),
            ]);
        });

        $token = $user->createToken('device:'.$device->id)->plainTextToken;

        return response()->json(['data' => [
            'token' => $token,
            'user' => new MeResource($employee->fresh(['user', 'unit', 'offices', 'activeDevice'])),
        ]]);
    }

    /** Mencabut token perangkat ini. */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
        // HP yang sudah logout tidak boleh lagi menerima notifikasi akun ini.
        $request->user()->employee?->activeDevice?->update(['fcm_token' => null]);

        return response()->json(['data' => ['ok' => true]]);
    }
}
