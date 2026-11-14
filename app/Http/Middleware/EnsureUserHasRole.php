<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }

            return redirect()->guest('/admin/login');
        }

        $userRole = $user->role instanceof \BackedEnum ? $user->role->value : (string) $user->role;

        if (! in_array($userRole, $roles, true)) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => 'Bạn không có quyền truy cập tài nguyên này.',
                ], 403);
            }

            abort(403, 'Bạn không có quyền truy cập khu vực quản trị.');
        }

        return $next($request);
    }
}
