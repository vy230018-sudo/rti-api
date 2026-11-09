<?php

namespace App\Services;

use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Firebase Cloud Messaging (HTTP v1). Koi extra composer package nahi chahiye.
 * Android + iOS + Web teeno ke liye ek hi API.
 */
class FcmService
{
    public function sendToUser(User $user, array $payload): void
    {
        DeviceToken::query()->where('user_id', $user->id)->get()->each(function (DeviceToken $device) use ($payload) {
            $this->send($device, $payload);
        });
    }

    /** payload: ['title' => , 'body' => , 'data' => [..]] */
    public function send(DeviceToken $device, array $payload): bool
    {
        if (! config('services.fcm.enabled')) {
            return false;
        }

        // FCM data me sab values string honi chahiye
        $data = collect($payload['data'] ?? [])
            ->map(fn ($v) => is_scalar($v) || $v === null ? (string) $v : json_encode($v))
            ->all();

        $message = [
            'token' => $device->token,
            'notification' => [
                'title' => (string) ($payload['title'] ?? config('app.name')),
                'body' => (string) ($payload['body'] ?? ''),
            ],
            'data' => $data,
            'android' => [
                'priority' => 'HIGH',
                'notification' => ['channel_id' => 'default', 'sound' => 'default'],
            ],
            'apns' => ['payload' => ['aps' => ['sound' => 'default']]],
        ];

        try {
            $response = Http::withToken($this->accessToken())
                ->timeout(10)
                ->post('https://fcm.googleapis.com/v1/projects/'.config('services.fcm.project_id').'/messages:send', [
                    'message' => $message,
                ]);
        } catch (\Throwable $e) {
            Log::warning('FCM request failed: '.$e->getMessage());

            return false;
        }

        if ($response->successful()) {
            $device->forceFill(['last_used_at' => now()])->save();

            return true;
        }

        $status = $response->json('error.status');

        // Token expire / app uninstall -> token delete kar do
        if ($response->status() === 404 || in_array($status, ['NOT_FOUND', 'UNREGISTERED'], true)) {
            $device->delete();
        } else {
            Log::warning('FCM send error', ['status' => $response->status(), 'body' => $response->body()]);
        }

        return false;
    }

    protected function accessToken(): string
    {
        return Cache::remember('fcm_access_token', 3300, function () {
            $path = config('services.fcm.credentials');

            if (! $path || ! is_file($path)) {
                throw new RuntimeException('Firebase credentials file not found: '.$path);
            }

            $cred = json_decode(file_get_contents($path), true);
            $now = time();

            $header = $this->b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $claims = $this->b64(json_encode([
                'iss' => $cred['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ]));

            openssl_sign("$header.$claims", $signature, $cred['private_key'], OPENSSL_ALGO_SHA256);
            $jwt = "$header.$claims.".$this->b64($signature);

            $res = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

            if (! $res->successful() || ! $res->json('access_token')) {
                throw new RuntimeException('Could not get FCM access token: '.$res->body());
            }

            return $res->json('access_token');
        });
    }

    protected function b64(string $v): string
    {
        return rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
    }
}
