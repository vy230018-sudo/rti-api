<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class JwtService
{
    public function issueToken(User $user): array
    {
        $issuedAt = CarbonImmutable::now();
        $expiresAt = $issuedAt->addMinutes(config('jwt.ttl', 60));
        $jti = (string) str()->uuid();

        $payload = [
            'iss' => config('jwt.issuer'),
            'sub' => $user->getKey(),
            'iat' => $issuedAt->timestamp,
            'exp' => $expiresAt->timestamp,
            'jti' => $jti,
            'is_admin' => (bool) $user->is_admin,
        ];

        return [
            'token' => $this->encode($payload),
            'expires_at' => $expiresAt->toIso8601String(),
            'expires_in' => $expiresAt->diffInSeconds($issuedAt),
        ];
    }

    public function decode(string $token): array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            throw new AuthenticationException('Invalid token format.');
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;

        $signature = $this->base64UrlDecode($encodedSignature);
        $expectedSignature = hash_hmac('sha256', $encodedHeader.'.'.$encodedPayload, $this->secret(), true);

        if (! hash_equals($expectedSignature, $signature)) {
            throw new AuthenticationException('Invalid token signature.');
        }

        $payload = json_decode($this->base64UrlDecode($encodedPayload), true);

        if (! is_array($payload)) {
            throw new AuthenticationException('Invalid token payload.');
        }

        if (($payload['exp'] ?? 0) < now()->timestamp) {
            throw new AuthenticationException('Token has expired.');
        }

        if (isset($payload['jti']) && Cache::has($this->blacklistKey($payload['jti']))) {
            throw new AuthenticationException('Token has been revoked.');
        }

        return $payload;
    }

    public function invalidate(string $token): void
    {
        $payload = $this->decode($token);

        if (! isset($payload['jti'], $payload['exp'])) {
            return;
        }

        $expiresAt = CarbonImmutable::createFromTimestamp($payload['exp']);

        Cache::put($this->blacklistKey($payload['jti']), true, $expiresAt);
    }

    protected function encode(array $payload): string
    {
        $header = [
            'typ' => 'JWT',
            'alg' => 'HS256',
        ];

        $encodedHeader = $this->base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR));
        $encodedPayload = $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        $encodedSignature = $this->base64UrlEncode(
            hash_hmac('sha256', $encodedHeader.'.'.$encodedPayload, $this->secret(), true)
        );

        return $encodedHeader.'.'.$encodedPayload.'.'.$encodedSignature;
    }

    protected function secret(): string
    {
        $secret = (string) config('jwt.secret');

        if ($secret === '') {
            throw new RuntimeException('JWT secret is not configured.');
        }

        if (str_starts_with($secret, 'base64:')) {
            $decoded = base64_decode(substr($secret, 7), true);

            if ($decoded === false) {
                throw new RuntimeException('JWT secret is not valid base64.');
            }

            return $decoded;
        }

        return $secret;
    }

    protected function blacklistKey(string $jti): string
    {
        return 'jwt_blacklist:'.$jti;
    }

    protected function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    protected function base64UrlDecode(string $value): string
    {
        $padding = strlen($value) % 4;

        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        if ($decoded === false) {
            throw new AuthenticationException('Invalid token encoding.');
        }

        return $decoded;
    }
}
