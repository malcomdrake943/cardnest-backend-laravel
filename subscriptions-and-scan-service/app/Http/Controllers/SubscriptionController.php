<?php

namespace App\Http\Controllers;

use App\Models\Subscription;
use App\Models\Package;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;


class SubscriptionController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function GetByUserIDorMerchantID(Request $request)
    {
        // the user is verified by the middleware
        $authUser = $request->auth_user;

        // Use the verified IDs from the Auth service to ensure data integrity
        $userId = $authUser['id'] ?? null;
        $merchantId = $authUser['merchant_id'] ?? null;

        $subscription = Subscription::with('package')
            ->where('user_id', $userId)
            ->orWhere('merchant_id', $merchantId)
            ->latest()
            ->first();

        if (!$subscription) {
            return response()->json([
                'status' => false,
                'code' => 404,
                'message' => 'No subscription found for this merchant.'
            ], 404);
        }

        return response()->json([
            'status' => true,
            'code' => 200,
            'message' => 'Subscription retrieved successfully',
            'data' => $subscription
        ], 200);
    }


    /**
     * Show the form for creating a new resource.
     */
    public function customPackagePricing(Request $request)
    {
        // Validate the request
        $validator = Validator::make($request->all(), [
            'package_id' => 'required|integer'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Find the pricing by id
        $pricing = Package::where('id', $request->package_id)->first();

        if (!$pricing) {
            return response()->json([
                'status' => 'error',
                'message' => 'Pricing not found for the specified package'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $pricing
        ], 200);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function setupRenewal(Request $request)
    {
        // the user is verified by the middleware
        $authUser = $request->auth_user;
        $userRole = $request->header('X-User-Role') ?? ($authUser['role'] ?? null);

        // Ensure this is accessed only by a Super Admin
        if ($userRole !== 'SUPER_ADMIN') {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized. Only super admins can configure renewals.'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'merchant_id' => 'required|string',
            'is_new_user' => 'nullable|boolean',
            'custom_api_count' => 'nullable|integer|min:0',
            'date' => 'nullable|date',
            'user_id' => 'nullable|string',
            'package_id' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation Error',
                'errors' => $validator->errors()
            ], 422);
        }

        $merchantId = $request->merchant_id;

        // 1. Find the current most recent subscription (non-blocking query first to resolve user_id)
        $currentSubscription = Subscription::where('merchant_id', $merchantId)
            ->latest()
            ->first();

        // Determine user_id: request input, or existing subscription's user_id, or look up from auth service
        $userId = $request->user_id ?? ($currentSubscription->user_id ?? null);
        if (!$userId) {
            try {
                $authResponse = Http::withHeaders([
                    'X-Internal-Service-Token' => config('services.internal.token'),
                    'Accept' => 'application/json',
                ])->get(config('services.internal.auth') . '/api/internal/verify-user', [
                    'id' => $merchantId,
                    'merchant_id' => $merchantId,
                ]);
                if ($authResponse->successful()) {
                    $userId = $authResponse->json('data.id');
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error("Failed to fetch merchant user ID: " . $e->getMessage());
            }
        }

        $now = now();
        $isExpiredResult = false;

        // Perform the state checks and modifications inside a database transaction with write locks
        $subscription = DB::transaction(function () use ($request, $merchantId, $userId, $now, &$isExpiredResult) {
            $activeSubscriptions = Subscription::where('merchant_id', $merchantId)
                ->where('status', 'active')
                ->lockForUpdate()
                ->get();

            // Refresh the current subscription inside the lock
            $currentSubscription = Subscription::where('merchant_id', $merchantId)
                ->latest()
                ->first();

            $isNewUser = !$currentSubscription || filter_var($request->is_new_user, FILTER_VALIDATE_BOOLEAN);
            $isExpired = $currentSubscription && $currentSubscription->renewal_date && Carbon::parse($currentSubscription->renewal_date)->isPast();
            $isExpiredResult = $isExpired;

            // 2. Decide if we need a NEW record (for renewal or new user)
            if ($isNewUser || $isExpired) {
                // If this is a renewal (not a new user) but a newer active subscription has already been created
                // (e.g., by a concurrent/parallel request), we clean up any duplicates and return the existing active one.
                if (!$isNewUser && $currentSubscription && $currentSubscription->status === 'active' && Carbon::parse($currentSubscription->renewal_date)->isFuture()) {
                    foreach ($activeSubscriptions as $sub) {
                        if ($sub->id !== $currentSubscription->id) {
                            $sub->status = 'expired';
                            $sub->save();
                        }
                    }
                    $isExpiredResult = false; // It was already renewed by another thread, so count it as updated in place
                    return $currentSubscription;
                }

                $startDate = ($isNewUser && $request->date) ? Carbon::parse($request->date) : $now;

                // Handle null currentSubscription for new users
                $baseLimit = $request->custom_api_count ?? ($currentSubscription->api_call_limit ?? 1000);
                $newLimit = $baseLimit;

                // Mark all existing active ones as expired before creating the new one
                foreach ($activeSubscriptions as $sub) {
                    $sub->status = 'expired';
                    $sub->save();
                }

                // 3. Create the NEW active subscription record for the new cycle
                $packageId = $request->package_id ?? ($currentSubscription->package_id ?? 1);
                // Ensure the package exists in the database to prevent foreign key constraint failure
                if (!Package::where('id', $packageId)->exists()) {
                    Package::insert([
                        'id' => $packageId,
                        'package_name' => 'Default Package ' . $packageId,
                        'package_price' => 0.00,
                        'package_period' => 'month',
                        'package_description' => 'Default plan created automatically',
                        'monthly_limit' => 1000,
                        'overage_rate' => 0.10,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                $subscription = new Subscription();
                $subscription->merchant_id = $merchantId;
                $subscription->user_id = $userId;
                $subscription->package_id = $packageId;
                $subscription->status = 'active';
                $subscription->api_call_limit = $newLimit;
                $subscription->api_calls_used = 0;
                $subscription->overage_calls = 0;
                $subscription->subscription_date = $startDate;
                $subscription->renewal_date = $startDate->copy()->addMonth();
                $subscription->is_custom_renewal = 1;
                $subscription->save();

                return $subscription;
            } else {
                // 4. Mid-cycle update (adjusting current active subscription)
                // If there are duplicate active subscriptions, we will update the latest one and expire the others
                $latestActive = $activeSubscriptions->sortByDesc('created_at')->first();
                if (!$latestActive) {
                    $latestActive = $currentSubscription;
                }

                foreach ($activeSubscriptions as $sub) {
                    if ($sub->id !== $latestActive->id) {
                        $sub->status = 'expired';
                        $sub->save();
                    }
                }

                if (!empty($request->custom_api_count)) {
                    $latestActive->api_call_limit = $request->custom_api_count;
                }
                if ($userId) {
                    $latestActive->user_id = $userId;
                }
                $latestActive->status = 'active';
                $latestActive->save();

                return $latestActive;
            }
        });

        return response()->json([
            'status' => true,
            'message' => $isExpiredResult ? 'New subscription cycle started' : 'Subscription updated',
            'data' => $subscription
        ], 200);
    }

    /**
     * Retrieve all subscriptions (both current/active and old/expired) for a given merchant.
     *
     * This is handled entirely using the single 'subscriptions' table.
     * The verification is done by the 'verify.user' middleware.
     *
     * @param  \Illuminate\Http\Request  $request  { id: string }
     * @return \Illuminate\Http\JsonResponse
     */
    public function getOldSubscriptions(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'nullable',
            'merchant_id' => 'nullable',
            'merchantId' => 'nullable',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation Error',
                'errors' => $validator->errors()
            ], 422);
        }

        $authUser = $request->auth_user;
        $userRole = $request->header('X-User-Role') ?? ($authUser['role'] ?? null);
        $isSuperAdmin = ($userRole === 'SUPER_ADMIN');

        // Determine merchant_id to query based on user role and request inputs
        if ($isSuperAdmin) {
            $merchantId = $request->merchant_id ?? $request->merchantId ?? $request->id ?? ($authUser['merchant_id'] ?? null);
        } else {
            $merchantId = $authUser['merchant_id'] ?? $request->merchant_id ?? $request->merchantId;
        }

        if (!$merchantId) {
            return response()->json([
                'status' => false,
                'message' => 'Validation Error',
                'errors' => ['merchant_id' => ['The merchant ID is required.']]
            ], 422);
        }

        // Retrieve all subscriptions from the single 'subscriptions' table
        $subscriptions = Subscription::with('package')->where('merchant_id', $merchantId)
            ->latest()
            ->get();

        // Segment the subscriptions for backwards-compatible metadata counts
        $currentSubscriptions = $subscriptions->where('status', 'active');
        $oldSubscriptions = $subscriptions->where('status', '!=', 'active');

        return response()->json([
            'status' => true,
            'message' => 'Retrieved all subscription records against merchant_id.',
            'data' => $subscriptions,
            'metadata' => [
                'total_old_subscriptions' => $oldSubscriptions->count(),
                'total_current_subscriptions' => $currentSubscriptions->count(),
                'total_all_subscriptions' => $subscriptions->count()
            ]
        ], 200);
    }
}
