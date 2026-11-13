<?php

namespace App\Http\Middleware;

use Closure;
use Exception;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;

class JWTAuthentication
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            // Attempt to authenticate the user from the token in the request
            $user = JWTAuth::parseToken()->authenticate();
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found'
                ], 404);
            }
        } catch (TokenExpiredException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Token has expired'
            ], 401);
        } catch (TokenInvalidException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Token is invalid'
            ], 401);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Authorization token not found'
            ], 401);
        }

        // Check if additional admin has been deactivated
        if ($user->is_additional_admin && $user->admin_status === 'inactive') {
            return response()->json([
                'status' => false,
                'message' => 'Your account has been deactivated by the merchant administrator. Please contact your organization owner.'
            ], 403);
        }

        // Reject tokens issued at or before token revocation timestamp
        try {
            $payload = JWTAuth::parseToken()->getPayload();
            $iat = $payload->get('iat');
            if ($user->tokens_revoked_at && $iat && (int) $iat <= $user->tokens_revoked_at->timestamp) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Token has been revoked'
                ], 401);
            }
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Token is invalid'
            ], 401);
        }

        if ($user->is_additional_admin) {
            $user->load('parentMerchant');
        }

        // Attach the authenticated user to the request context
        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        return $next($request);
    }
}
