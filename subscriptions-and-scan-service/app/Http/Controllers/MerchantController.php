<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use App\Models\ScanSession;

class MerchantController extends Controller
{
    /**
     * Retrieve merchant display information using a specific Scan ID.
     * Looks up the scan session locally and fetches merchant details from Auth Service.
     */
    public function getmerchantscanInfo(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'scanId' => ['required'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'code' => 422,
                'message' => 'Validation failed.',
                'errors' => $validator->errors()
            ], 422);
        }

        // Find the scan session locally in THIS service
        $session = ScanSession::where('scan_id', $request->scanId)->first();

        if (!$session) {
            return response()->json([
                'status' => false,
                'code' => 404,
                'message' => 'No record exists against this scanID.'
            ], 404);
        }

        // Call the Auth Service internally to get the Merchant's Business Profile
        $authServiceUrl = config('services.internal.auth', 'http://localhost:8001') . '/api/internal/verify-user';

        try {
            $response = Http::withHeaders([
                'X-Internal-Service-Token' => config('services.internal.token'),
                'Accept' => 'application/json',
            ])->get($authServiceUrl, [
                        'merchant_id' => $session->merchant_id
                    ]);

            if ($response->failed()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Could not retrieve merchant info from Auth Service',
                    'error' => $response->json('message', 'Internal Service Error')
                ], $response->status());
            }

            $merchantData = $response->json()['data'];
            $profile = $merchantData['business_profile'] ?? null;

            // Format the logo URL
            $logoUrl = null;
            if ($profile && !empty($profile['display_logo'])) {
                if (filter_var($profile['display_logo'], FILTER_VALIDATE_URL)) {
                    $logoUrl = $profile['display_logo'];
                } else {
                    $logoUrl = Storage::disk('s3')->url($profile['display_logo']);
                }
            }

            return response()->json([
                'status' => true,
                'code' => 200,
                'data' => [
                    'scanId' => $session->scan_id,
                    'merchant_id' => $session->merchant_id,
                    'display_name' => $profile['display_name'] ?? 'Merchant',
                    'display_logo' => $logoUrl
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Service communication error',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Retrieve merchant display information directly via Merchant ID.
     * Communicates with Auth Service to fetch the latest business profile data.
     */
    public function getmerchantDisplayInfo(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'merchantId' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'code' => 422,
                'message' => 'Validation failed.',
                'errors' => $validator->errors()
            ], 422);
        }

        // Call the Auth Service internally to get the Merchant's Business Profile
        $authServiceUrl = config('services.internal.auth', 'http://localhost:8001') . '/api/internal/verify-user';

        try {
            $response = Http::withHeaders([
                'X-Internal-Service-Token' => config('services.internal.token'),
                'Accept' => 'application/json',
            ])->get($authServiceUrl, [
                        'merchant_id' => $request->merchantId
                    ]);

            if ($response->failed()) {
                return response()->json([
                    'status' => false,
                    'code' => 404,
                    'message' => 'No record exists against this merchantID.'
                ], 404);
            }

            $merchantData = $response->json()['data'];
            $profile = $merchantData['business_profile'] ?? null;

            // Format the logo URL
            $logoUrl = null;
            if ($profile && !empty($profile['display_logo'])) {
                if (filter_var($profile['display_logo'], FILTER_VALIDATE_URL)) {
                    $logoUrl = $profile['display_logo'];
                } else {
                    $logoUrl = Storage::disk('s3')->url($profile['display_logo']);
                }
            }

            return response()->json([
                'status' => true,
                'code' => 200,
                'data' => [
                    'merchant_id' => $request->merchantId,
                    'display_name' => $profile['display_name'] ?? 'Merchant',
                    'display_logo' => $logoUrl
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Service communication error',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Proxy request to update merchant scan-related display info in the Auth Service.
     * Handles forwarding of display name and logo file uploads.
     */
    public function updateMerchantScanInfo(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'merchant_id' => ['required'],
            'display_name' => ['sometimes', 'string', 'max:255'],
            'display_logo' => ['sometimes', 'image', 'mimes:jpeg,png,jpg,gif,svg', 'max:2048'], // 2MB max
        ]);

        if ($validator->fails()) {
            return response()->json([
                'code' => 422,
                'message' => 'Validation failed.',
                'errors' => $validator->errors()
            ], 422);
        }

        // Forward the request to Auth Service
        $authServiceUrl = config('services.internal.auth', 'http://localhost:8001') . '/api/internal/update-profile';

        try {
            $httpRequest = Http::withHeaders([
                'X-Internal-Service-Token' => config('services.internal.token'),
                'Accept' => 'application/json',
            ]);

            // If there is a file, we must "attach" it to the multi-part request
            if ($request->hasFile('display_logo')) {
                $file = $request->file('display_logo');
                $httpRequest->attach(
                    'display_logo',
                    file_get_contents($file->getRealPath()),
                    $file->getClientOriginalName()
                );
            }

            $response = $httpRequest->post($authServiceUrl, [
                'merchant_id' => $request->merchant_id,
                'display_name' => $request->display_name,
            ]);

            if ($response->failed()) {
                return response()->json([
                    'status' => false,
                    'code' => $response->status(),
                    'message' => 'Failed to update merchant display information in Auth Service.',
                    'error' => $response->json('message')
                ], $response->status());
            }

            return response()->json($response->json());

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'code' => 500,
                'message' => 'Service communication error.',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Proxy request to update merchant profile and manage logo visibility.
     * Supports explicit logo deletion if the logo is missing from the request.
     */
    public function updateMerchantDisplayInfo(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'merchant_id' => ['required'],
            'display_name' => ['required', 'string', 'max:255'],
            'display_logo' => ['sometimes', 'nullable', 'image', 'mimes:jpeg,png,jpg,gif,svg', 'max:2048'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'code' => 422,
                'message' => 'Validation failed.',
                'errors' => $validator->errors()
            ], 422);
        }

        // Forward the request to Auth Service
        $authServiceUrl = config('services.internal.auth', 'http://localhost:8001') . '/api/internal/update-profile';

        try {
            $httpRequest = Http::withHeaders([
                'X-Internal-Service-Token' => config('services.internal.token'),
                'Accept' => 'application/json',
            ]);

            $payload = [
                'merchant_id' => $request->merchant_id,
                'display_name' => $request->display_name,
            ];

            // Handle logo logic for microservices
            if ($request->hasFile('display_logo')) {
                // Uploading a new logo
                $file = $request->file('display_logo');
                $httpRequest->attach(
                    'display_logo',
                    file_get_contents($file->getRealPath()),
                    $file->getClientOriginalName()
                );
            } elseif (!$request->has('display_logo') || is_null($request->display_logo)) {
                // If logo field is missing or explicitly null, we tell the Auth service to delete it
                $payload['delete_logo'] = true;
            }

            $response = $httpRequest->post($authServiceUrl, $payload);

            if ($response->failed()) {
                return response()->json([
                    'status' => false,
                    'code' => $response->status(),
                    'message' => 'Failed to update merchant display information in Auth Service.',
                    'error' => $response->json('message')
                ], $response->status());
            }

            return response()->json($response->json());

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'code' => 500,
                'message' => 'Service communication error.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
