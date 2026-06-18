<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\SuperAdmin;
use App\Models\Scan;
use App\Models\Subscription;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;


class SuperAdminController extends Controller
{
    /**
     * Create or update a merchant's business profile.
     * Proxies the creation or update of business profile details to the Auth Service.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required',
            'display_name' => 'required|string|max:255',
            'display_logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation Error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Call the Auth Service internally to update/create the business profile
        $authServiceUrl = config('services.internal.auth', 'http://localhost:8001') . '/api/internal/superadmin/store-business-profile';

        try {
            $httpRequest = Http::withHeaders([
                'X-Internal-Service-Token' => config('services.internal.token'),
                'Accept' => 'application/json',
            ]);

            // Forward logo file if uploaded
            if ($request->hasFile('display_logo')) {
                $file = $request->file('display_logo');
                $httpRequest->attach(
                    'display_logo',
                    file_get_contents($file->getRealPath()),
                    $file->getClientOriginalName()
                );
            }

            $response = $httpRequest->post($authServiceUrl, [
                'user_id' => $request->user_id,
                'display_name' => $request->display_name,
            ]);

            if ($response->failed()) {
                $errorResponse = $response->json();
                return response()->json([
                    'status' => false,
                    'message' => $errorResponse['message'] ?? 'Failed to update/create business profile in Auth Service.',
                    'code' => $response->status()
                ], $response->status());
            }

            return response()->json($response->json(), $response->status());
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Service communication error.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Retrieve a merchant's business profile.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'merchant_id' => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation Error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Call the Auth Service internally to retrieve the business profile
        $authServiceUrl = config('services.internal.auth', 'http://localhost:8001') . '/api/internal/superadmin/show-business-profile';

        try {
            $response = Http::withHeaders([
                'X-Internal-Service-Token' => config('services.internal.token'),
                'Accept' => 'application/json',
            ])->get($authServiceUrl, [
                'merchant_id' => $request->merchant_id
            ]);

            if ($response->failed()) {
                $errorResponse = $response->json();
                return response()->json([
                    'status' => false,
                    'message' => $errorResponse['message'] ?? 'Business profile not found.',
                    'code' => $response->status()
                ], $response->status());
            }

            return response()->json($response->json(), $response->status());
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Service communication error.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function uploadDocumentation(Request $request)
    {
        // Custom validation with messages
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'required|in:PDF,DOCX,IMAGE',
            'fileName' => 'required|string|max:255',
            'fileType' => 'required|string|max:100',
            'fileBase' => ['required', 'string', 'regex:/^[A-Za-z0-9+\/=]+$/']
        ], [
            'type.in' => 'Type must be one of: PDF, DOCX, IMAGE.',
            'fileBase.regex' => 'The fileBase must be a valid base64 encoded string.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'validation_error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Decode and store file
            $fileData = base64_decode($request->fileBase);

            if ($fileData === false) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid base64 string.'
                ], 400);
            }

            $uniqueName = uniqid() . '_' . preg_replace('/[^A-Za-z0-9_\.\-]/', '_', $request->fileName);
            $path = 'documents/' . $uniqueName;

            Storage::disk('s3')->put($path, $fileData);

            // Save to DB
            $doc = SuperAdmin::create([
                'title' => $request->title,
                'description' => $request->description,
                'type' => $request->type,
                'file_path' => $path,
                'file_type' => $request->fileType,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Documentation uploaded successfully.',
                'data' => $doc
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'An error occurred while uploading documentation.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getDocumentation()
    {
        $docs = SuperAdmin::all();

        if ($docs->isEmpty()) {
            return response()->json([
                'status' => 'not_found',
                'message' => 'No documentation found.',
                'data' => []
            ], 404);
        }

        // Add file_url for each document
        $docs->transform(function ($doc) {
            $doc->file_url = Storage::disk('s3')->url($doc->file_path);
            return $doc;
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Documentation list retrieved.',
            'data' => $docs
        ], 200);
    }

    public function getSuperAdminDocumentation(\Illuminate\Http\Request $request)
    {
        $docs = SuperAdmin::all();

        if ($docs->isEmpty()) {
            return response()->json([
                'status' => 'not_found',
                'message' => 'No documentation found.',
                'data' => []
            ], 404);
        }

        // Add file_url for each document
        $docs->transform(function ($doc) {
            $doc->file_url = asset('storage/' . ltrim($doc->file_path, '/'));
            return $doc;
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Documentation list retrieved.',
            'data' => $docs
        ], 200);
    }

    /**
     * Grant sub-admin access by updating user role.
     * Communicates with Auth Service internally to update roles in the primary users table.
     */
    public function grantSubadminAccess(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'admin_email' => 'required|email',
            'user_email' => 'required|email',
            'role' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation Error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Call the Auth Service internally to update the role in the primary database
        $authServiceUrl = config('services.internal.auth', 'http://localhost:8001') . '/api/internal/update-role';

        try {
            $response = Http::withHeaders([
                'X-Internal-Service-Token' => config('services.internal.token'),
                'Accept' => 'application/json',
            ])->post($authServiceUrl, [
                'admin_email' => $request->admin_email,
                'user_email' => $request->user_email,
                'role' => $request->role,
            ]);

            if ($response->failed()) {
                $errorResponse = $response->json();
                return response()->json([
                    'status' => false,
                    'message' => $errorResponse['message'] ?? 'Failed to update user role in Auth Service.',
                    'code' => $response->status()
                ], $response->status());
            }

            return response()->json($response->json(), $response->status());
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Service communication error.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Retrieve aggregated scan telemetry for all merchants.
     * Gathers local scan statistics, calculates status success rate, 
     * and maps business names from the remote Auth Service.
     */
    public function accessAllScans(Request $request)
    {
        // Fetch all business profiles/names from Auth Service internally
        $authServiceUrl = config('services.internal.auth', 'http://localhost:8001') . '/api/internal/business-names';
        $businessNamesMap = [];

        try {
            $response = Http::withHeaders([
                'X-Internal-Service-Token' => config('services.internal.token'),
                'Accept' => 'application/json',
            ])->get($authServiceUrl);

            if ($response->successful()) {
                $merchantsList = $response->json()['data'] ?? [];
                foreach ($merchantsList as $m) {
                    if (isset($m['merchant_id'])) {
                        $businessNamesMap[$m['merchant_id']] = $m['business_name'];
                    }
                }
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Failed to fetch business names: " . $e->getMessage());
        }

        // Query scans from local scans table and group by merchant_id (only retrieves merchants who actually have scans)
        $scansAggregation = Scan::select('merchant_id')
            ->selectRaw('COUNT(*) as total_scans')
            ->selectRaw("SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success_scans")
            ->groupBy('merchant_id')
            ->get();

        $merchantsData = [];

        // Loop through only the merchants that have scan records
        foreach ($scansAggregation as $agg) {
            $merchantId = $agg->merchant_id;
            $totalScans = (int) $agg->total_scans;
            $successScans = (int) $agg->success_scans;
            $successRate = $totalScans > 0 ? round(($successScans / $totalScans) * 100, 2) . '%' : '100%';

            $businessName = $businessNamesMap[$merchantId] ?? 'Merchant (' . $merchantId . ')';

            $merchantsData[] = [
                'merchant_id' => $merchantId,
                'business_name' => $businessName,
                'total_scans' => $totalScans,
                'success_rate' => $successRate,
            ];
        }

        return response()->json([
            'status' => true,
            'message' => 'All Card Scans records retrieved successfully',
            'data' => [
                'merchants' => $merchantsData,
                'total_merchants' => count($merchantsData)
            ]
        ], 200);
    }

    /**
     * Retrieve detailed scan history and session cryptography for a given merchant.
     *
     * @param  string|null  $id  Merchant ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function scanDetail(Request $request, $id = null)
    {
        $merchantId = $id ?? $request->input('merchant_id') ?? $request->input('merchantId') ?? $request->input('id');

        if (!$merchantId) {
            return response()->json([
                'status' => false,
                'message' => 'Merchant ID is required.'
            ], 400);
        }

        // Get the days query parameter (defaulting to 7 if not provided)
        $days = $request->query('days', 7);

        // Base query
        $query = Scan::select(
            'scans.id',
            'scans.user_id',
            'scans.merchant_id',
            'scans.merchant_key',
            'scans.card_number_masked',
            'scans.status',
            'scans.encrypted_data',
            'scans.scan_id',
            'scans.session_id',
            'scans.failure_reason',
            'scans.failure_stage',
            'scans.created_at',
            'scans.updated_at',
            'scan_sessions.encryption_key'
        )
            ->leftJoin('scan_sessions', 'scans.scan_id', '=', 'scan_sessions.scan_id')
            ->where('scans.merchant_id', $merchantId);

        // Filter by created_at if days is specified as a valid numeric string, except if 'all' is passed
        $filteredByDate = false;
        if ($days !== 'all' && is_numeric($days) && $days > 0) {
            $query->where('scans.created_at', '>=', now()->subDays((int)$days));
            $filteredByDate = true;
        }

        $scans = $query->latest('scans.created_at')->get();
        $isFallback = false;

        // Fallback: if we filtered by date and got 0 scans, fetch the latest 10 scans overall
        if ($scans->isEmpty() && $filteredByDate) {
            $scans = Scan::select(
                'scans.id',
                'scans.user_id',
                'scans.merchant_id',
                'scans.merchant_key',
                'scans.card_number_masked',
                'scans.status',
                'scans.encrypted_data',
                'scans.scan_id',
                'scans.session_id',
                'scans.failure_reason',
                'scans.failure_stage',
                'scans.created_at',
                'scans.updated_at',
                'scan_sessions.encryption_key'
            )
                ->leftJoin('scan_sessions', 'scans.scan_id', '=', 'scan_sessions.scan_id')
                ->where('scans.merchant_id', $merchantId)
                ->latest('scans.created_at')
                ->take(10)
                ->get();

            if ($scans->isNotEmpty()) {
                $isFallback = true;
            }
        }

        // Calculate aggregates
        $successCount = $scans->where('status', 'success')->count();
        $failureCount = $scans->where('status', 'failed')->count();
        $totalScans = $successCount + $failureCount;

        $responseMessage = $isFallback
            ? "No scans in the last {$days} days. Showing the most recent scans."
            : 'Merchant card scans retrieved successfully';

        return response()->json([
            'status' => true,
            'message' => $responseMessage,
            'data' => [
                'scans' => $scans,
                'total_scans' => $totalScans,
                'total_success' => $successCount,
                'total_failure' => $failureCount,
                'is_fallback' => $isFallback
            ]
        ], 200);
    }

    public function accessAllOldSubscriptions(Request $request)
    {
        // Get all subscriptions
        $allSubscriptions = Subscription::with('package')->select(
            'id',
            'user_id',
            'merchant_id',
            'package_id',
            'is_custom_renewal',
            'api_call_limit',
            'api_calls_used',
            'overage_calls',
            'status',
            'subscription_date',
            'renewal_date',
            'created_at',
            'updated_at'
        )->orderby('created_at', 'desc')->get();

        // Fetch all business profiles/names from Auth Service internally
        $authServiceUrl = config('services.internal.auth', 'http://localhost:8001') . '/api/internal/business-names';
        $businessNamesMap = [];

        try {
            $response = Http::withHeaders([
                'X-Internal-Service-Token' => config('services.internal.token'),
                'Accept' => 'application/json',
            ])->get($authServiceUrl);

            if ($response->successful()) {
                $merchantsList = $response->json()['data'] ?? [];
                foreach ($merchantsList as $m) {
                    if (isset($m['merchant_id'])) {
                        $businessNamesMap[$m['merchant_id']] = $m['business_name'];
                    }
                }
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Failed to fetch business names: " . $e->getMessage());
        }

        // Group subscriptions by merchant_id and format with business name
        $grouped = $allSubscriptions->groupBy('merchant_id');
        $groupedData = [];

        foreach ($grouped as $merchantId => $subscriptions) {
            $businessName = $businessNamesMap[$merchantId] ?? 'Merchant (' . $merchantId . ')';
            $groupedData[] = [
                'merchant_id' => $merchantId,
                'business_name' => $businessName,
                'subscriptions' => $subscriptions
            ];
        }

        $oldSubscriptionsCount = $allSubscriptions->where('status', '!=', 'active')->count();
        $currentSubscriptionsCount = $allSubscriptions->where('status', 'active')->count();

        return response()->json([
            'status' => true,
            'message' => 'All subscription records retrieved successfully',
            'data' => $groupedData,
            'metadata' => [
                'old_subscriptions_count' => $oldSubscriptionsCount,
                'current_subscriptions_count' => $currentSubscriptionsCount,
                'total_records' => $allSubscriptions->count()
            ]
        ], 200);
    }

    /**
     * Create sub-businesses for an enterprise/parent user.
     *
     * Proxies the creation of sub-businesses to the Auth Service.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function sub_business_store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'parent_id' => 'required',
            'sub_businesses' => 'required|array|min:1',
            'sub_businesses.*.sub_b_name' => 'required|string|max:255',
            'sub_businesses.*.sub_b_email' => 'required|email',
            'sub_businesses.*.sub_b_reg_no' => 'required|string',
            'sub_businesses.*.sub_b_street' => 'required|string',
            'sub_businesses.*.sub_b_street_line2' => 'nullable|string',
            'sub_businesses.*.sub_b_city' => 'required|string',
            'sub_businesses.*.sub_b_state' => 'required|string',
            'sub_businesses.*.sub_b_zip_code' => 'required|string',
            'sub_businesses.*.sub_b_country' => 'required|string',
            'sub_businesses.*.account_holder_first_name' => 'required|string',
            'sub_businesses.*.account_holder_last_name' => 'required|string',
            'sub_businesses.*.account_holder_email' => 'required|email',
            'sub_businesses.*.account_holder_date_of_birth' => 'required|date',
            'sub_businesses.*.account_holder_street' => 'required|string',
            'sub_businesses.*.account_holder_street_line2' => 'nullable|string',
            'sub_businesses.*.account_holder_city' => 'required|string',
            'sub_businesses.*.account_holder_state' => 'required|string',
            'sub_businesses.*.account_holder_zip_code' => 'nullable|string',
            'sub_businesses.*.account_holder_country' => 'required|string',
            'sub_businesses.*.account_holder_id_type' => 'required|string',
            'sub_businesses.*.account_holder_id_number' => 'required|string',
            'sub_businesses.*.registration_document' => 'required|file',
            'sub_businesses.*.account_holder_id_document' => 'required|file'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Call the Auth Service internally to create the sub businesses and update parent user role
        $authServiceUrl = config('services.internal.auth', 'http://localhost:8001') . '/api/internal/superadmin/sub-business-store';

        try {
            $httpRequest = Http::withHeaders([
                'X-Internal-Service-Token' => config('services.internal.token'),
                'Accept' => 'application/json',
            ]);

            // Construct array parameters for the post request
            $subBusinesses = $request->input('sub_businesses', []);

            // Attach files dynamically
            foreach ($subBusinesses as $index => $subBusiness) {
                if ($request->hasFile("sub_businesses.{$index}.registration_document")) {
                    $file = $request->file("sub_businesses.{$index}.registration_document");
                    $httpRequest->attach(
                        "sub_businesses_{$index}_registration_document",
                        file_get_contents($file->getRealPath()),
                        $file->getClientOriginalName()
                    );
                }

                if ($request->hasFile("sub_businesses.{$index}.account_holder_id_document")) {
                    $file = $request->file("sub_businesses.{$index}.account_holder_id_document");
                    $httpRequest->attach(
                        "sub_businesses_{$index}_account_holder_id_document",
                        file_get_contents($file->getRealPath()),
                        $file->getClientOriginalName()
                    );
                }
            }

            // Post request with nested fields
            $response = $httpRequest->post($authServiceUrl, [
                'parent_id' => $request->parent_id,
                'sub_businesses' => $subBusinesses
            ]);

            if ($response->failed()) {
                $errorResponse = $response->json();
                return response()->json([
                    'status' => false,
                    'message' => $errorResponse['message'] ?? 'Failed to create sub businesses in Auth Service.',
                    'code' => $response->status()
                ], $response->status());
            }

            return response()->json($response->json(), $response->status());
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Service communication error.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Retrieve all sub-businesses belonging to a parent user.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function get_sub_businesses(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'parent_id' => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation Error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Call the Auth Service internally to retrieve the sub-businesses
        $authServiceUrl = config('services.internal.auth', 'http://localhost:8001') . '/api/internal/superadmin/get-sub-businesses';

        try {
            $response = Http::withHeaders([
                'X-Internal-Service-Token' => config('services.internal.token'),
                'Accept' => 'application/json',
            ])->get($authServiceUrl, [
                'parent_id' => $request->parent_id
            ]);

            if ($response->failed()) {
                $errorResponse = $response->json();
                return response()->json([
                    'status' => false,
                    'message' => $errorResponse['message'] ?? 'Parent user not found or failed to retrieve sub-businesses.',
                    'code' => $response->status()
                ], $response->status());
            }

            return response()->json($response->json(), $response->status());
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Service communication error.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Retrieve all enterprise users with their sub-businesses.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getEnterpriseUsersWithSubBusinesses(Request $request)
    {
        // Call the Auth Service internally to retrieve all enterprise users with their sub-businesses
        $authServiceUrl = config('services.internal.auth', 'http://localhost:8001') . '/api/internal/superadmin/get-enterprise-users';

        try {
            $response = Http::withHeaders([
                'X-Internal-Service-Token' => config('services.internal.token'),
                'Accept' => 'application/json',
            ])->get($authServiceUrl);

            if ($response->failed()) {
                $errorResponse = $response->json();
                return response()->json([
                    'status' => false,
                    'message' => $errorResponse['message'] ?? 'Failed to retrieve enterprise users with sub-businesses.',
                    'code' => $response->status()
                ], $response->status());
            }

            return response()->json($response->json(), $response->status());
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Service communication error.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
