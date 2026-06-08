<?php

namespace App\Http\Controllers;

use App\Models\Subscription;
use App\Models\Package;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;


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
        $merchantId = $authUser['merchant_id'] ?? $request->merchant_id;
        $userId = $authUser['id'] ?? null;

        $validator = Validator::make($request->all(), [
            'merchant_id' => 'required|string',
            'is_new_user' => 'nullable|boolean',
            'custom_api_count' => 'nullable|integer|min:0',
            'date' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation Error',
                'errors' => $validator->errors()
            ], 422);
        }

        // 1. Find the current most recent subscription
        $currentSubscription = Subscription::where('merchant_id', $merchantId)
            ->latest()
            ->first();

        $now = now();
        $isNewUser = !$currentSubscription || filter_var($request->is_new_user, FILTER_VALIDATE_BOOLEAN);
        $isExpired = $currentSubscription && $currentSubscription->renewal_date && Carbon::parse($currentSubscription->renewal_date)->isPast();

        // 2. Decide if we need a NEW record (for renewal or new user)
        if ($isNewUser || $isExpired) {
            $startDate = ($isNewUser && $request->date) ? Carbon::parse($request->date) : $now;

            // Handle null currentSubscription for new users
            $baseLimit = $request->custom_api_count ?? ($currentSubscription->api_call_limit ?? 1000);

            if ($isExpired) {
                // Mark the old one as expired before creating the new one
                $currentSubscription->status = 'expired';
                $currentSubscription->save();

                // Calculate renewal adjustment from the OLD record
                $oldLimit = $currentSubscription->api_call_limit ?? 0;
                $oldUsed = $currentSubscription->api_calls_used ?? 0;

                $pending = max(0, $oldLimit - $oldUsed);
                $overage = max(0, $oldUsed - $oldLimit);

                $baseLimit = $request->custom_api_count ?? $currentSubscription->api_call_limit ?? $oldLimit;
                $newLimit = max(0, $baseLimit + $pending - $overage);
            } else {
                $newLimit = $baseLimit;
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
            $subscription->package_id = $packageId ?? null;
            $subscription->status = 'active';
            $subscription->api_call_limit = $newLimit;
            $subscription->api_calls_used = 0;
            $subscription->overage_calls = 0;
            $subscription->subscription_date = $startDate;
            $subscription->renewal_date = $startDate->copy()->addMonth();
            $subscription->is_custom_renewal = 1;
            $subscription->save();
        } else {
            // 4. Mid-cycle update (adjusting current active subscription)
            $subscription = $currentSubscription;
            if (!empty($request->custom_api_count)) {
                $subscription->api_call_limit = $request->custom_api_count;
            }
            $subscription->save();
        }

        return response()->json([
            'status' => true,
            'message' => $isExpired ? 'New subscription cycle started' : 'Subscription updated',
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
            'id' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation Error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Retrieve all subscriptions from the single 'subscriptions' table
        $subscriptions = Subscription::where('merchant_id', $request->id)
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
