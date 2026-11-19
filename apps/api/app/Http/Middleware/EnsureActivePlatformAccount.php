<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActivePlatformAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->platform_status === 'suspended') {
            return response()->json([
                'message' => 'This account is suspended. Contact platform support for assistance.',
                'status' => 'account_suspended',
            ], 403);
        }

        return $next($request);
    }
}
