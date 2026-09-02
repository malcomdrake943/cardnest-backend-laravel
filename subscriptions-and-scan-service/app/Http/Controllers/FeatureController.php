<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Feature;
use App\Models\User;
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
            $key = hex2bin(config('services.internal.aes_key'));
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
     * Verify if the user exists in the system via the auth service and return user data.
     *
     * @param int|string $userId
     * @return array|null
     */
    private function getVerifiedUser($userId)
    {
        try {
            $response = Http::withHeaders([
                'X-Internal-Service-Token' => config('services.internal.token'),
                'Accept' => 'application/json',
            ])->get(config('services.internal.auth') . '/api/internal/verify-user', [
                'id' => $userId,
                'user_id' => $userId,
            ]);

            if ($response->successful()) {
                return $response->json('data');
            }
            return null;
        } catch (\Exception $e) {
            Log::error('FeatureController getVerifiedUser verification error: ' . $e->getMessage());
            return null;
        }
    }

    public function getFeatures(Request $request)
    {
        $merchantId = $request->input('merchant_id');

        if (!$merchantId) {
            return response()->json([
                'code' => 400,
                'message' => 'The merchant_id is required parameter.'
            ], 400);
        }

        $user = User::where('merchant_id', $merchantId)->first();
        if (!$user) {
            return response()->json([
                'code' => 404,
                'message' => 'User not found with the provided merchant_id.'
            ], 404);
        }
        // Get features for the user
        $features = Feature::where('user_id', $user->id)->get();

        if ($features->isEmpty()) {
            return response()->json([
                'code' => 404,
                'message' => 'No features found for this user.'
            ], 404);
        }

        $firstFeature = $features->first();
        $metadataKeys = ['id', 'user_id', 'created_at', 'updated_at'];
        $metadata = array_intersect_key($firstFeature->toArray(), array_flip($metadataKeys));

        $data = $features->map(function ($feature) use ($metadataKeys) {
            return array_diff_key($feature->toArray(), array_flip($metadataKeys));
        });

        $cardPreferences = null;

        if ($merchantId) {
            $preference = \App\Models\MerchantCardPreference::where('merchant_id', $merchantId)->first();
            if ($preference) {
                $cardPreferences = [
                    'card_types' => $preference->card_types ?? [
                        ['type' => 'Credit', 'is_blocked' => false],
                        ['type' => 'Debit', 'is_blocked' => false]
                    ],
                    'card_networks' => $preference->card_networks ?? [
                        ['network' => 'MasterCard', 'is_blocked' => false],
                        ['network' => 'Visa', 'is_blocked' => false],
                        ['network' => 'American Express', 'is_blocked' => false]
                    ],
                    'blocked_countries' => $preference->blocked_countries ?? []
                ];
            } else {
                $cardPreferences = [
                    'card_types' => [
                        ['type' => 'Credit', 'is_blocked' => false],
                        ['type' => 'Debit', 'is_blocked' => false]
                    ],
                    'card_networks' => [
                        ['network' => 'MasterCard', 'is_blocked' => false],
                        ['network' => 'Visa', 'is_blocked' => false],
                        ['network' => 'American Express', 'is_blocked' => false]
                    ],
                    'blocked_countries' => []
                ];
            }
        }

        return response()->json(array_merge([
            'code' => 200,
            'message' => 'Features retrieved successfully.',
        ], $metadata, [
            'data' => $data,
            'preferences' => $cardPreferences
        ]), 200);
    }

    public function store(Request $request)
    {
        $request->validate([
            'bank_logo'         => 'nullable|boolean',
            'chip'              => 'nullable|boolean',
            'mag_strip'         => 'nullable|boolean',
            'sig_strip'         => 'nullable|boolean',
            'hologram'          => 'nullable|boolean',
            'customer_service'  => 'nullable|boolean',
            'symmetry'          => 'nullable|boolean',
        ]);


        $userId = $request->auth_user['id'];

        // Check if user exists via auth service
        if (!$this->getVerifiedUser($userId)) {
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
            'bank_logo'         => 'nullable|boolean',
            'chip'              => 'nullable|boolean',
            'mag_strip'         => 'nullable|boolean',
            'sig_strip'         => 'nullable|boolean',
            'hologram'          => 'nullable|boolean',
            'customer_service'  => 'nullable|boolean',
            'symmetry'          => 'nullable|boolean',
        ]);

        $userId = $request->auth_user['id'];

        // Check if user exists via auth service
        if (!$this->getVerifiedUser($userId)) {
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
