<?php

namespace App\Notifications\Push;

use App\Models\Employee;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Firebase Cloud Messaging HTTP v1. Kredensial berupa berkas service account
 * JSON dari Firebase Console (Project settings → Service accounts).
 */
class FcmPushNotifier implements PushNotifier
{
    private array $account;

    public function __construct(string $credentialsPath)
    {
        $this->account = json_decode((string) file_get_contents($credentialsPath), true)
            ?? throw new RuntimeException("Berkas kredensial FCM tidak valid: $credentialsPath");
    }

    public function send(Employee $employee, string $title, string $body, array $data = []): void
    {
        $token = $employee->activeDevice?->fcm_token;
        if (! $token) {
            return;
        }

        $response = Http::withToken($this->accessToken())
            ->post("https://fcm.googleapis.com/v1/projects/{$this->account['project_id']}/messages:send", [
                'message' => [
                    'token' => $token,
                    'notification' => ['title' => $title, 'body' => $body],
                    'data' => array_map('strval', $data),
                    'android' => ['priority' => 'high'],
                    'apns' => ['headers' => ['apns-priority' => '10']],
                ],
            ]);

        if ($response->failed()) {
            // Token kedaluwarsa (aplikasi dihapus/diinstal ulang): buang supaya tidak dicoba terus.
            if (in_array($response->json('error.details.0.errorCode'), ['UNREGISTERED', 'INVALID_ARGUMENT'], true)) {
                $employee->activeDevice?->update(['fcm_token' => null]);
            }
            Log::warning('FCM gagal', ['employee_id' => $employee->id, 'status' => $response->status(), 'body' => $response->json()]);
        }
    }

    /** Token OAuth dari JWT yang ditandatangani kunci service account (berlaku 1 jam). */
    private function accessToken(): string
    {
        return Cache::remember('fcm:access-token', now()->addMinutes(50), function () {
            $now = time();
            $segments = [
                $this->b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
                $this->b64(json_encode([
                    'iss' => $this->account['client_email'],
                    'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                    'aud' => 'https://oauth2.googleapis.com/token',
                    'iat' => $now,
                    'exp' => $now + 3600,
                ])),
            ];
            openssl_sign(implode('.', $segments), $signature, $this->account['private_key'], OPENSSL_ALGO_SHA256);
            $segments[] = $this->b64($signature);

            return Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => implode('.', $segments),
            ])->throw()->json('access_token');
        });
    }

    private function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
