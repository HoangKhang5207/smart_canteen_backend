<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
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

        $expandedRoles = $roles;
        if (in_array(UserRole::PARENT->value, $roles, true) && ! in_array(UserRole::CUSTOMER->value, $expandedRoles, true)) {
            $expandedRoles[] = UserRole::CUSTOMER->value;
        }

        if (! in_array($userRole, $expandedRoles, true)) {
            if ($request->expectsJson() || $request->is('api/*')) {
                $isParentRequired = in_array(UserRole::PARENT->value, $roles, true)
                    && $userRole === UserRole::STUDENT->value;

                return response()->json([
                    'message' => $isParentRequired
                        ? 'Thao tác này chỉ dành cho tài khoản phụ huynh. Vui lòng liên hệ phụ huynh để thực hiện.'
                        : 'Bạn không có quyền truy cập tài nguyên này.',
                    'error_code' => $isParentRequired ? 'PARENT_ROLE_REQUIRED' : 'FORBIDDEN',
                ], 403);
            }

            abort(403, 'Bạn không có quyền truy cập khu vực quản trị.');
        }

        return $next($request);
    }
}
