<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->is_admin) {
            return new JsonResponse([
                'message' => 'Admin access is required.',
                // Client is code se pehchan sakta hai ki 403 app se aaya hai (admin token nahi hai),
                // server/WAF ke HTML 403 se nahi.
                'code' => 'admin_required',
            ], 403);
        }

        return $next($request);
    }
}
