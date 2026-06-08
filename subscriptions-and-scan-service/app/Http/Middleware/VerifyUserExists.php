<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyUserExists
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $userId = $request->header('X-User-ID')
            ?? $request->header('X-Merchant-ID')
            ?? $request->input('id')
            ?? $request->input('merchant_id')
            ?? $request->input('merchantId')
            ?? $request->input('user_id')
            ?? $request->route('id');

        if (!$userId) {
            return response()->json([
                'status' => false,
                'message' => 'user_id or merchant_id is required for verification'
            ], 400);
        }

        try {
            $response = Http::withHeaders([
                'X-Internal-Service-Token' => config('services.internal.token'),
                'Accept' => 'application/json',
            ])->get(config('services.internal.auth') . '/api/internal/verify-user', [
                'id' => $userId,
                'user_id' => $userId,
            ]);

            if ($response->successful()) {
                $authUser = $response->json('data');
                // Optionally attach user data to request
                $request->merge(['auth_user' => $authUser]);

                // Overwrite/merge the IDs from the authenticated user to ensure security and prevent ID spoofing,
                // and to ensure controllers have access to these without frontend having to send them.
                $userRole = $request->header('X-User-Role') ?? ($authUser['role'] ?? null);
                $isSuperAdmin = ($userRole === 'SUPER_ADMIN');

                if (!$isSuperAdmin) {
                    // Force the authenticated user's IDs for regular users to prevent spoofing
                    if (isset($authUser['id'])) {
                        $request->merge(['id' => $authUser['id']]);
                    }
                    if (isset($authUser['merchant_id'])) {
                        $request->merge([
                            'merchant_id' => $authUser['merchant_id'],
                            'merchantId' => $authUser['merchant_id']
                        ]);
                    }
                } else {
                    // For SUPER_ADMIN, only fill in the IDs if they are missing from the request
                    if (!$request->has('id') && isset($authUser['id'])) {
                        $request->merge(['id' => $authUser['id']]);
                    }
                    if (!$request->has('merchant_id') && isset($authUser['merchant_id'])) {
                        $request->merge(['merchant_id' => $authUser['merchant_id']]);
                    }
                    if (!$request->has('merchantId') && isset($authUser['merchant_id'])) {
                        $request->merge(['merchantId' => $authUser['merchant_id']]);
                    }
                }

                return $next($request);
            }

            return response()->json([
                'status' => false,
                'message' => 'User verification failed',
                'error' => $response->json('message') ?? 'User not found in Auth Service'
            ], $response->status() == 404 ? 404 : 403);
        } catch (\Exception $e) {
            Log::error('Internal User Verification Error: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Internal verification service error'
            ], 500);
        }
    }
}
