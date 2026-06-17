<?php

namespace App\Http\Controllers;

use App\Models\Scan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\User;
use App\Models\ScanSession;
use App\Models\Subscription;
use App\Models\BillingLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;


class ScanController extends Controller
{
    /**
     * Generate a secure, double-wrapped auth token for initiating a card scan session.
     */
    public function generateToken(Request $request)
    {
        // 1. Input Validation
        $validator = Validator::make($request->all(), [
            'merchantId' => ['required', 'string'],
            'isMobile' => ['required', 'in:true,false'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'code' => 422,
                'message' => 'Validation failed.',
                'errors' => $validator->errors()
            ], 422);
        }

        // 2. Fetch Merchant Data from Auth Service
        $authServiceUrl = config('services.internal.auth', 'http://localhost:8001') . '/api/internal/verify-user';

        try {
            $authResponse = Http::withHeaders([
                'X-Internal-Service-Token' => config('services.internal.token'),
                'Accept' => 'application/json',
            ])->get($authServiceUrl, [
                'merchant_id' => $request->merchantId
            ]);

            if ($authResponse->failed()) {
                return response()->json(['message' => 'Invalid merchant credentials or Auth Service error'], 404);
            }

            $merchantData = $authResponse->json()['data'];
            $profile = $merchantData['business_profile'] ?? null;
        } catch (\Exception $e) {
            return response()->json(['message' => 'Auth Service communication error'], 500);
        }

        // Check Access and Determine Subscription Status
        $subscription = Subscription::where('merchant_id', $request->merchantId)
            ->latest()
            ->first();

        if (!$subscription) {
            return response()->json([
                'status' => false,
                'code' => 403,
                'message' => 'No subscription found. Please subscribe to continue.'
            ], 403);
        }

        $isExpired = now()->gt(Carbon::parse($subscription->renewal_date));
        if ($isExpired) {
            return response()->json([
                'status' => false,
                'code' => 403,
                'message' => 'Your subscription has expired. Please renew to continue.'
            ], 403);
        }

        if ($subscription->status !== 'active') {
            return response()->json([
                'status' => false,
                'code' => 403,
                'message' => 'Your subscription is not active. Please activate to continue.'
            ], 403);
        }

        $limit = (int) $subscription->api_call_limit;
        $used = (int) $subscription->api_calls_used;
        if ($used >= $limit) {
            return response()->json([
                'status' => false,
                'code' => 403,
                'message' => 'Subscription API limit reached. Please upgrade your plan to continue.'
            ], 403);
        }

        $subscriptionStatus = 'active';
        $subscriptionDetails = [
            'renewal_date' => $subscription->renewal_date,
            'api_calls_used' => $subscription->api_calls_used,
            'api_call_limit' => $subscription->api_call_limit,
            'overage_calls' => $subscription->overage_calls,
        ];

        // 4. Generate a new unique Scan ID
        do {
            $scanId = Str::random(10);
        } while (ScanSession::where('scan_id', $scanId)->exists());

        ScanSession::create([
            'scan_id' => $scanId,
            'merchant_id' => $request->merchantId,
            'device_type' => $request->isMobile === 'true' ? 'mobile' : 'web',
            'tries' => 0,
            'encryption_key' => $merchantData['aes_key'] ?? null,
        ]);

        // 5. Encrypt Token (Double-Wrapped: JWT Library -> AES)
        $customClaims = [
            'scan_id' => $scanId,
            'merchant_id' => $request->merchantId,
            'encryption_key' => $merchantData['aes_key'] ?? null,
            'iat' => now()->timestamp,
            'exp' => now()->addHours(1)->timestamp
        ];

        // Hydrate a dummy user model for JWTAuth (since users are remote)
        $user = new User();
        $user->id = $merchantData['id'] ?? 0;

        $jwtToken = JWTAuth::customClaims($customClaims)->fromUser($user);

        $key = hex2bin(config('services.internal.aes_key'));
        $encryptedToken = base64_encode(openssl_encrypt(
            $jwtToken,
            'AES-256-ECB',
            $key,
            OPENSSL_RAW_DATA
        ));

        // 6. Build response
        $response = [
            'status' => true,
            'message' => 'Token generated successfully',
            'authToken' => $encryptedToken,
            'subscription_status' => $subscriptionStatus,
            'user_type' => $subscription ? 'subscribed' : 'none',
        ];

        if ($subscriptionDetails) {
            $response['subscription_details'] = $subscriptionDetails;
        }

        if ($request->isMobile === 'false') {
            $businessName = $profile['display_name'] ?? 'Merchant';
            $response['scanID'] = $scanId;
            $response['scanURL'] = "https://auth.cardnest.io/" . rawurlencode($businessName) . "/{$scanId}";
        }

        if ($subscriptionStatus === 'expired') {
            $response['warning'] = 'Your subscription has expired. Please renew to continue uninterrupted service.';
        }

        return response()->json($response);
    }


    /**
     * Create a fresh scan token for a customer-facing scan session.
     */
    public function createScanToken(Request $request)
    {
        $request->validate([
            'scanId' => 'required|string',
        ]);

        $session = ScanSession::where('scan_id', $request->scanId)->first();
        if (!$session) {
            return response()->json(['message' => 'Invalid scan ID'], 404);
        }

        // MICROSERVICE ADAPTATION: Since the users table is in Auth-Service, 
        // we hydrate a dummy User model with the ID from the session.
        $merchant = new User();
        $merchant->id = $session->merchant_id;

        // JWT claims
        $customClaims = [
            'scan_id' => $session->scan_id,
            'merchant_id' => $session->merchant_id,
            'isMobile' => $session->device_type,
            'encryption_key' => $session->encryption_key,
            'iat' => now()->timestamp,
            'exp' => now()->addHours(1)->timestamp // Recommended for security
        ];

        // Create JWT using library implementation
        $jwtToken = JWTAuth::customClaims($customClaims)->fromUser($merchant);

        // Encrypt JWT using AES-256
        try {
            $key = hex2bin(config('services.internal.aes_key'));
            if ($key === false || strlen($key) !== 32) {
                throw new \Exception('Invalid AES key length');
            }

            $encryptedToken = base64_encode(openssl_encrypt(
                $jwtToken,
                'AES-256-ECB',
                $key,
                OPENSSL_RAW_DATA
            ));

            return response()->json([
                'authToken' => $encryptedToken
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to secure scan token',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Accept and store the encrypted card data from a completed scan.
     */
    public function submitEncryptedData(Request $request)
    {
        $request->validate([
            'scanId' => 'required|string',
            'encrypted_data' => 'required|string',
        ]);

        $session = ScanSession::where('scan_id', $request->scanId)->first();

        if (!$session) {
            return response()->json(['message' => 'Invalid scan ID'], 404);
        }

        // 1. Save the encrypted data locally
        $session->encrypted_data = $request->encrypted_data;
        $session->scanned_at = now();
        $session->save();

        // 2. MICROSERVICE SYNC: Notify Auth Service to record the usage
        try {
            $authServiceUrl = config('services.internal.auth', 'http://localhost:8001') . '/api/internal/record-scan';

            // We use an asynchronous-like approach or just a fire-and-forget 
            // so we don't slow down the mobile app response.
            Http::withHeaders([
                'X-Internal-Service-Token' => config('services.internal.token'),
                'Accept' => 'application/json',
            ])->post($authServiceUrl, [
                'merchant_id' => $session->merchant_id,
                'scan_id' => $session->scan_id,
                'device_type' => $session->device_type
            ]);
        } catch (\Exception $e) {
            // Log it but allow the response to succeed
            \Illuminate\Support\Facades\Log::error("Auth Service Scan Sync Failed: " . $e->getMessage());
        }

        return response()->json([
            'status' => true,
            'message' => 'Scanned data submitted successfully.',
        ]);
    }


    /**
     * Retrieve the stored encrypted card data for a given scan session.
     */
    public function getEncryptedData(Request $request)
    {

        $session = ScanSession::select('id', 'scan_id', 'merchant_id', 'encrypted_data')->where('scan_id', $request->scanId)->first();

        if (!$session) {
            return response()->json(['message' => 'Invalid scan ID'], 404);
        }

        return response()->json([
            'message' => 'Scanned data retrieved successfully.',
            'data' => $session
        ]);
    }

    /**
     * Peel off the AES encryption layer from a double-wrapped auth token.
     */
    public function decodeToken(Request $request)
    {
        $request->validate([
            'authToken' => 'required|string'
        ]);

        try {
            // Encrypted token from request
            $encryptedToken = $request->authToken;

            // Convert HEX key → 32-byte binary key
            $key = hex2bin(config('services.internal.aes_key'));

            if ($key === false || strlen($key) !== 32) {
                return response()->json([
                    'success' => false,
                    'error' => 'Invalid AES key length'
                ], 500);
            }

            // Base64 decode
            $cipherText = base64_decode($encryptedToken, true);
            if ($cipherText === false) {
                return response()->json([
                    'success' => false,
                    'error' => 'Invalid base64 token'
                ], 400);
            }

            // AES-256-ECB decrypt (NO IV)
            $decryptedToken = openssl_decrypt(
                $cipherText,
                'AES-256-ECB',
                $key,
                OPENSSL_RAW_DATA
            );

            if (!$decryptedToken) {
                return response()->json([
                    'success' => false,
                    'error' => 'Token decryption failed'
                ], 400);
            }

            // JUST RETURN THE JWT STRING
            return response()->json([
                'success' => true,
                'authToken' => $decryptedToken
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Decryption error',
                'details' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Record a card scan event and update subscription usage if applicable.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateCardScan(Request $request)
    {
        // Validate request parameters
        $validator = Validator::make($request->all(), [
            'merchant_id' => 'required|string',
            'merchant_key' => 'required|string',
            'card_number_masked' => 'required|string',
            'status' => 'required|in:success,failure,failed',
            'scan_id' => 'nullable|string',
            'failure_reason' => 'nullable|string',
            'failure_stage' => 'nullable|string',
            'encrypted_data' => 'nullable|string',
            'session_id' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation Error',
                'errors' => $validator->errors()
            ], 422);
        }

        $message = 'Card scan saved successfully.';
        $warning = null;

        // Retrieve verified user ID from verify.user middleware
        $authUser = $request->auth_user;
        $userId = $authUser['id'] ?? null;

        // Handle subscription users
        $subscription = Subscription::where('merchant_id', $request->merchant_id)
            ->latest()
            ->first();

        $status = $request->status;

        $hasActiveSubscription = $subscription && 
            $subscription->status === 'active' && 
            now()->lte(\Carbon\Carbon::parse($subscription->renewal_date));

        if (!$hasActiveSubscription) {
            $warning = 'No active subscription found. Please subscribe to continue without interruptions.';
        } else {
            // Check billing overdue > 30 days
            $billing = BillingLog::where('merchant_id', $request->merchant_id)->latest()->first();

            if ($billing && !$billing->is_paid && now()->gt($billing->due_date) && now()->diff($billing->due_date)->days > 30) {
                $warning = 'Subscription blocked due to unpaid invoice. Please settle your payment to avoid service interruptions.';
            }

            // Track subscription usage
            $limit = $subscription->api_call_limit; // Singular column name
            $subscription->api_calls_used += 1;

            if ($subscription->api_calls_used > $limit) {
                $subscription->overage_calls += 1;
                $warning = 'Subscription API limit reached. Overage charges may apply.';
            }

            $subscription->save();
            $message = 'Card scan saved and subscription usage updated.';
        }

        // Always create the card scan record regardless of subscription status
        $scan = Scan::create([
            'user_id' => $userId,
            'merchant_id' => $request->merchant_id,
            'merchant_key' => $request->merchant_key,
            'card_number_masked' => $request->card_number_masked,
            'status' => $status,
            'scan_id' => $request->scan_id,
            'failure_reason' => $request->failure_reason,
            'failure_stage' => $request->failure_stage,
            'encrypted_data' => $request->encrypted_data,
            'session_id' => $request->session_id
        ]);

        $response = [
            'status' => true,
            'message' => $message,
            'data' => $scan
        ];

        // Add warning message if any
        if ($warning) {
            $response['warning'] = $warning;
        }

        return response()->json($response, 200);
    }

    /**
     * Retrieve all card scan records for a given merchant.
     *
     * Supports both subscription and active merchants.
     *
     * @param  \Illuminate\Http\Request  $request  { id: string }
     * @return \Illuminate\Http\JsonResponse
     */
    public function getCardScans(Request $request)
    {
        $authUser = $request->auth_user;
        $userRole = $request->header('X-User-Role') ?? ($authUser['role'] ?? null);
        $isSuperAdmin = ($userRole === 'SUPER_ADMIN');

        // Determine merchant_id to query based on user role and request inputs
        if ($isSuperAdmin) {
            $merchantId = $request->input('merchant_id') ?? $request->input('merchantId') ?? $request->input('id') ?? ($authUser['merchant_id'] ?? null);
        } else {
            $merchantId = $authUser['merchant_id'] ?? $request->input('merchant_id') ?? $request->input('merchantId');
        }

        if (!$merchantId) {
            return response()->json([
                'status' => false,
                'message' => 'Validation Error',
                'errors' => ['id' => ['The merchant ID is required.']]
            ], 422);
        }

        // Check local subscriptions
        $subscription = Subscription::where('merchant_id', $merchantId)
            ->latest()
            ->first();

        if ($subscription) {
            // Get the days query parameter (defaulting to 7 if not provided)
            $days = $request->query('days', 7);

            // Base query
            $query = Scan::where('merchant_id', $merchantId);

            // Filter by created_at if days is specified as a valid numeric string, except if 'all' is passed
            $filteredByDate = false;
            if ($days !== 'all' && is_numeric($days) && $days > 0) {
                $query->where('created_at', '>=', now()->subDays((int)$days));
                $filteredByDate = true;
            }

            $scan = $query->latest()->get();
            $isFallback = false;

            // Fallback: if we filtered by date and got 0 scans, fetch the latest 10 scans overall
            if ($scan->isEmpty() && $filteredByDate) {
                $scan = Scan::where('merchant_id', $merchantId)
                    ->latest()
                    ->take(10)
                    ->get();

                if ($scan->isNotEmpty()) {
                    $isFallback = true;
                }
            }

            $responseMessage = $isFallback
                ? "No scans in the last {$days} days. Showing the most recent scans."
                : 'Retrieve Card Scans record against merchant_id.';

            return response()->json([
                'status' => true,
                'message' => $responseMessage,
                'data' => $scan,
                'is_fallback' => $isFallback
            ], 200);
        } else {
            return response()->json([
                'status' => false,
                'message' => 'There is no subscription purchased.',
                'data' => NULL
            ], 400);
        }
    }
}
