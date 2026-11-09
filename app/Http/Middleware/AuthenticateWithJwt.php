<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\JwtService;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateWithJwt
{
    public function __construct(protected JwtService $jwtService)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $token = $this->extractBearerToken($request);
            $payload = $this->jwtService->decode($token);
            $user = User::query()->find($payload['sub'] ?? null);

            // Delete (is_deleted = 0) user ka purana token bhi reject karo. Admin ko chhod ke.
            if (! $user || (! $user->is_admin && (string) $user->is_deleted === '0')) {
                throw new AuthenticationException('Authenticated user was not found.');
            }

            Auth::setUser($user);
            $request->setUserResolver(fn () => $user);
            $request->attributes->set('jwt_token', $token);
            $request->attributes->set('jwt_payload', $payload);
        } catch (AuthenticationException $exception) {
            return new JsonResponse([
                'message' => $exception->getMessage(),
            ], 401);
        }

        return $next($request);
    }

    protected function extractBearerToken(Request $request): string
    {
        $token = $request->bearerToken();

        if (! $token) {
            throw new AuthenticationException('Bearer token is required.');
        }

        return $token;
    }
}