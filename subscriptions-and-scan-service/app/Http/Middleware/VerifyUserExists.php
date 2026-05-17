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
        $userId = $request->input('user_id') ?? $request->route('user_id');
        $merchantId = $request->input('merchant_id') ?? $request->route('merchant_id');

        if (!$userId && !$merchantId) {
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
                'user_id' => $userId,
                'merchant_id' => $merchantId,
            ]);

            if ($response->successful()) {
                // Optionally attach user data to request
                $request->merge(['auth_user' => $response->json('data')]);
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
