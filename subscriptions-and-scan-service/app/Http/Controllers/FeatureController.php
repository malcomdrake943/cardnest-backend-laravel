<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Feature;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

class FeatureController extends Controller
{
    /**
     * Decrypt and decode the AES-wrapped JWT token to retrieve the payload.
     *
     * @param string $encryptedToken
     * @return \PHPOpenSourceSaver\JWTAuth\Payload|null
     */
    private function decodeAuthToken(string $encryptedToken)
    {
        try {
            $key = hex2bin(env('AES_256_KEY'));
            if ($key === false || strlen($key) !== 32) {
                Log::error('FeatureController: Invalid AES key length');
                return null;
            }

            $cipherText = base64_decode($encryptedToken, true);
            if ($cipherText === false) {
                return null;
            }

            $jwtToken = openssl_decrypt(
                $cipherText,
                'AES-256-ECB',
                $key,
                OPENSSL_RAW_DATA
            );

            if (!$jwtToken) {
                return null;
            }

            return JWTAuth::setToken($jwtToken)->getPayload();
        } catch (\Exception $e) {
            Log::error('FeatureController: Token decoding failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Verify if the user exists in the system via the auth service.
     *
     * @param int $userId
     * @return bool
     */
    private function userExists($userId): bool
    {
        try {
            $response = Http::withHeaders([
                'X-Internal-Service-Token' => config('services.internal.token'),
                'Accept' => 'application/json',
            ])->get(config('services.internal.auth') . '/api/internal/verify-user', [
                'id' => $userId,
                'user_id' => $userId,
            ]);

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('FeatureController userExists verification error: ' . $e->getMessage());
            return false;
        }
    }

    public function getFeatures(Request $request)
    {
        $request->validate([
            'auth_token' => 'required|string'
        ]);

        $payload = $this->decodeAuthToken($request->auth_token);
        if (!$payload) {
            return response()->json([
                'code' => 400,
                'message' => 'Invalid or expired auth token.'
            ], 400);
        }

        $userId = $payload->get('sub');

        // Check if user exists via auth service
        if (!$this->userExists($userId)) {
            return response()->json([
                'code' => 404,
                'message' => 'User not found with the provided user_id.'
            ], 404);
        }

        // Get features for the user
        $features = Feature::where('user_id', $userId)->first();

        if (!$features) {
            return response()->json([
                'code' => 404,
                'message' => 'No features found for this user.'
            ], 404);
        }

        return response()->json([
            'code' => 200,
            'message' => 'Features retrieved successfully.',
            'data' => $features
        ], 200);
    }

    public function store(Request $request)
    {
        $request->validate([
            'auth_token'        => 'required|string',
            'bank_logo'         => 'nullable|boolean',
            'chip'              => 'nullable|boolean',
            'mag_strip'         => 'nullable|boolean',
            'sig_strip'         => 'nullable|boolean',
            'hologram'          => 'nullable|boolean',
            'customer_service'  => 'nullable|boolean',
            'symmetry'          => 'nullable|boolean',
        ]);

        $payload = $this->decodeAuthToken($request->auth_token);
        if (!$payload) {
            return response()->json([
                'code' => 400,
                'message' => 'Invalid or expired auth token.'
            ], 400);
        }

        $userId = $payload->get('sub');

        // Check if user exists via auth service
        if (!$this->userExists($userId)) {
            return response()->json([
                'code' => 404,
                'message' => 'User not found with the provided user_id.'
            ], 404);
        }

        // Create or update Feature based on user_id
        $detail = Feature::updateOrCreate(
            ['user_id' => $userId], // Search criteria
            $request->only(['bank_logo', 'chip', 'mag_strip', 'sig_strip', 'hologram', 'customer_service', 'symmetry']) // Data to update
        );

        return response()->json([
            'code'    => 200,
            'message' => 'Features saved successfully.',
            'data'    => $detail
        ], 200);
    }

    public function storeFeature(Request $request)
    {
        $request->validate([
            'auth_token'        => 'required|string',
            'bank_logo'         => 'nullable|boolean',
            'chip'              => 'nullable|boolean',
            'mag_strip'         => 'nullable|boolean',
            'sig_strip'         => 'nullable|boolean',
            'hologram'          => 'nullable|boolean',
            'customer_service'  => 'nullable|boolean',
            'symmetry'          => 'nullable|boolean',
        ]);

        $payload = $this->decodeAuthToken($request->auth_token);
        if (!$payload) {
            return response()->json([
                'code' => 400,
                'message' => 'Invalid or expired auth token.'
            ], 400);
        }

        $userId = $payload->get('sub');

        // Check if user exists via auth service
        if (!$this->userExists($userId)) {
            return response()->json([
                'code' => 404,
                'message' => 'User not found with the provided user_id.'
            ], 404);
        }

        // Create or update Feature based on user_id
        $detail = Feature::updateOrCreate(
            ['user_id' => $userId], // Search criteria
            $request->only(['bank_logo', 'chip', 'mag_strip', 'sig_strip', 'hologram', 'customer_service', 'symmetry']) // Data to update
        );

        return response()->json([
            'code'    => 200,
            'message' => 'Features saved successfully.',
            'data'    => $detail
        ], 200);
    }
}
