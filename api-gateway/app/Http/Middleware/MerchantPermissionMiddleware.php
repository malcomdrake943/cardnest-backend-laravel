<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MerchantPermissionMiddleware
{
    /**
     * Enforce granular merchant permissions for custom additional admins.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized.'
            ], 401);
        }

        if ($user->is_additional_admin && $user->admin_status === 'inactive') {
            return response()->json([
                'status' => false,
                'message' => 'Your account has been deactivated by the merchant administrator. Please contact your organization owner.'
            ], 403);
        }

        if ($user->role === 'SUPER_ADMIN' || !$user->is_additional_admin || $user->access_type === 'full') {
            return $next($request);
        }

        $requiredPermissions = [];
        foreach ($permissions as $permission) {
            foreach (explode('|', $permission) as $part) {
                $trimmed = trim($part);
                if ($trimmed !== '') {
                    $requiredPermissions[] = $trimmed;
                }
            }
        }

        if (empty($requiredPermissions) || $user->hasMerchantPermission($requiredPermissions)) {
            return $next($request);
        }

        return response()->json([
            'status' => false,
            'message' => 'Forbidden: You do not have permission to access this resource.'
        ], 403);
    }
}

