<?php

namespace App\Http\Controllers;

use App\Models\Users;
use Illuminate\Http\Request;
use App\Models\BusinessProfile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\AccountHolder;

class InternalController extends Controller
{
    /**
     * Verify if a user exists and is active.
     */
    public function verifyUser(Request $request)
    {
        // Simple security check using Internal Service Token
        $token = $request->header('X-Internal-Service-Token');
        if ($token !== config('services.internal.token')) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized internal request'
            ], 401);
        }

        $userId = $request->input('id') ?? $request->input('user_id') ?? $request->input('merchant_id');

        $query = Users::query();

        if ($userId) {
            $query->where('id', $userId)
                ->orwhere('merchant_id', $userId);
        } else {
            return response()->json([
                'status' => false,
                'message' => 'Missing user_id or merchant_id'
            ], 400);
        }

        $user = $query->with('businessProfile')->first();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User not found'
            ], 404);
        }

        return response()->json([
            'status' => true,
            'message' => 'User verified',
            'data' => [
                'id' => $user->id,
                'merchant_id' => $user->merchant_id,
                'email' => $user->email,
                'aes_key' => $user->aes_key,
                'on_trial' => $user->on_trial,
                'trial_ends_at' => $user->trial_ends_at,
                'trial_calls_remaining' => $user->trial_calls_remaining,
                'business_verified' => $user->business_verified,
                'business_profile' => $user->businessProfile ? [
                    'display_name' => $user->businessProfile->display_name,
                    'display_logo' => $user->businessProfile->display_logo,
                ] : null
            ]
        ], 200);
    }

    /**
     * Update user business profile information internally.
     */
    public function updateProfile(Request $request)
    {
        $token = $request->header('X-Internal-Service-Token');
        if ($token !== config('services.internal.token')) {
            return response()->json(['status' => false, 'message' => 'Unauthorized internal request'], 401);
        }

        $merchantId = $request->input('merchant_id');
        $user = Users::where('merchant_id', $merchantId)->with('businessProfile')->first();

        if (!$user || !$user->businessProfile) {
            return response()->json(['status' => false, 'message' => 'Business profile not found'], 404);
        }

        $profile = $user->businessProfile;

        if ($request->has('display_name')) {
            $profile->display_name = $request->display_name;
        }

        if ($request->hasFile('display_logo')) {
            // Delete old logo if exists
            if ($profile->display_logo) {
                Storage::disk('public')->delete($profile->display_logo);
                Storage::disk('s3')->delete($profile->display_logo);
            }

            // Store new logo
            $path = $request->file('display_logo')->store('businesslogo', 's3');
            $profile->display_logo = $path;
        } elseif ($request->boolean('delete_logo')) {
            // Explicitly delete the logo
            if ($profile->display_logo) {
                Storage::disk('public')->delete($profile->display_logo);
                Storage::disk('s3')->delete($profile->display_logo);
            }
            $profile->display_logo = null;
        }

        $profile->save();

        return response()->json([
            'status' => true,
            'message' => 'Merchant display information updated successfully.',
            'data' => [
                'display_name' => $profile->display_name,
                'display_logo' => $profile->display_logo ? Storage::disk('s3')->url($profile->display_logo) : null
            ]
        ]);
    }

    /**
     * Update a user's role internally.
     */
    public function updateUserRole(Request $request)
    {
        $token = $request->header('X-Internal-Service-Token');
        if ($token !== config('services.internal.token')) {
            return response()->json(['status' => false, 'message' => 'Unauthorized internal request'], 401);
        }

        $adminEmail = $request->input('admin_email');
        $userEmail = $request->input('user_email');
        $role = $request->input('role');

        $validRoles = ['SUPER_ADMIN', 'BUSINESS_USER', 'ENTERPRISE_USER'];
        if (!in_array($role, $validRoles)) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid role specified. Valid roles are: ' . implode(', ', $validRoles),
                'code' => 422
            ], 422);
        }

        // Verify admin exists and has rights (SUPER_ADMIN)
        $admin = Users::where('email', $adminEmail)->first();
        if (!$admin || !in_array($admin->role, ['SUPER_ADMIN'])) {
            return response()->json([
                'status' => false,
                'message' => 'You have no rights to make changes or admin not found.',
                'code' => 403
            ], 403);
        }

        // Find target user and update role
        $user = Users::where('email', $userEmail)->first();
        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User not found with this email',
                'code' => 404
            ], 404);
        }

        $user->role = $role;
        $user->save();

        return response()->json([
            'status' => true,
            'message' => 'Role updated successfully',
            'data' => [
                'user_id' => $user->id,
                'email' => $user->email,
                'role' => $user->role
            ]
        ], 200);
    }

    /**
     * Retrieve a list of all merchants and their business names.
     */
    public function getBusinessNames(Request $request)
    {
        $token = $request->header('X-Internal-Service-Token');
        if ($token !== config('services.internal.token')) {
            return response()->json(['status' => false, 'message' => 'Unauthorized internal request'], 401);
        }

        $merchants = Users::whereNotNull('merchant_id')
            ->with('businessProfile')
            ->get()
            ->map(function ($user) {
                return [
                    'merchant_id' => $user->merchant_id,
                    'business_name' => $user->businessProfile->business_name ?? 'Merchant (' . $user->merchant_id . ')'
                ];
            });

        return response()->json([
            'status' => true,
            'data' => $merchants
        ]);
    }

    /**
     * Create or update a business profile from SuperAdmin internally.
     */
    public function storeBusinessProfile(Request $request)
    {
        $token = $request->header('X-Internal-Service-Token');
        if ($token !== config('services.internal.token')) {
            return response()->json(['status' => false, 'message' => 'Unauthorized internal request'], 401);
        }

        $userId = $request->input('user_id');
        $user = Users::where('id', $userId)->with('businessProfile')->first();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User not found with this ID',
                'code' => 404
            ], 404);
        }

        $profile = $user->businessProfile;

        $displayName = $request->input('display_name');

        // Handle display logo upload
        $logoPath = null;
        if ($request->hasFile('display_logo')) {
            // Delete old logo if exists
            if ($profile && $profile->display_logo) {
                Storage::disk('public')->delete($profile->display_logo);
                Storage::disk('s3')->delete($profile->display_logo);
            }

            // Store new logo
            $logoPath = $request->file('display_logo')->store('businesslogo', 's3');
        }

        if ($profile) {
            if ($displayName) {
                $profile->display_name = $displayName;
            }
            if ($logoPath) {
                $profile->display_logo = $logoPath;
            }
            $profile->save();
            $wasRecentlyCreated = false;
        } else {
            // Create a brand new business profile if one doesn't exist
            $profile = BusinessProfile::create([
                'user_id' => $userId,
                'display_name' => $displayName,
                'business_name' => $displayName, // Fallback
                'display_logo' => $logoPath
            ]);
            $wasRecentlyCreated = true;
        }

        return response()->json([
            'status' => true,
            'message' => 'Business profile ' . ($wasRecentlyCreated ? 'created' : 'updated') . ' successfully',
            'data' => [
                'id' => $profile->id,
                'user_id' => $profile->user_id,
                'display_name' => $profile->display_name,
                'display_logo' => $profile->display_logo ? Storage::disk('s3')->url($profile->display_logo) : null
            ]
        ]);
    }

    /**
     * Retrieve a merchant's business profile by merchant_id internally.
     */
    public function showBusinessProfile(Request $request)
    {
        $token = $request->header('X-Internal-Service-Token');
        if ($token !== config('services.internal.token')) {
            return response()->json(['status' => false, 'message' => 'Unauthorized internal request'], 401);
        }

        $merchantId = $request->input('merchant_id');
        $user = Users::where('merchant_id', $merchantId)->with('businessProfile')->first();

        if (!$user || !$user->businessProfile) {
            return response()->json([
                'status' => false,
                'message' => 'Business profile not found',
                'code' => 404
            ], 404);
        }

        return response()->json([
            'status' => true,
            'data' => [
                'id' => $user->businessProfile->id,
                'user_id' => $user->businessProfile->user_id,
                'display_name' => $user->businessProfile->display_name,
                'display_logo' => $user->businessProfile->display_logo ? Storage::disk('s3')->url($user->businessProfile->display_logo) : null
            ]
        ], 200);
    }

    /**
     * Create sub-businesses for an enterprise/parent user internally.
     */
    public function storeSubBusinesses(Request $request)
    {
        $token = $request->header('X-Internal-Service-Token');
        if ($token !== config('services.internal.token')) {
            return response()->json(['status' => false, 'message' => 'Unauthorized internal request'], 401);
        }

        try {
            DB::beginTransaction();

            $parentId = $request->input('parent_id');
            $parentUser = Users::where('merchant_id', $parentId)->first();

            if (!$parentUser) {
                return response()->json([
                    'status' => false,
                    'message' => 'Parent user not found'
                ], 404);
            }

            // Update parent role to ENTERPRISE_USER
            $parentUser->role = 'ENTERPRISE_USER';
            $parentUser->save();

            $subBusinessesData = $request->input('sub_businesses', []);
            $createdSubBusinesses = [];

            // Let's iterate and create each sub-business
            foreach ($subBusinessesData as $index => $subBusiness) {
                $aesKey = Str::random(16);

                // Create the user record
                $user = Users::create([
                    'email' => $subBusiness['sub_b_email'],
                    'aes_key' => $aesKey,
                    'role' => 'SUB_BUSINESS',
                    'parent_id' => $parentUser->merchant_id,
                    'business_verified' => 'APPROVED',
                    'service_type' => 'card_scan'
                ]);

                // Generate new format merchant_id (16 characters)
                $numbers = '0123456789';
                $alphaChars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
                $merchantId = '';

                for ($i = 0; $i < 14; $i++) {
                    $merchantId .= $numbers[rand(0, strlen($numbers) - 1)];
                }

                $positions = array_rand(range(0, 15), 2);
                foreach ($positions as $pos) {
                    $merchantId = substr_replace(
                        $merchantId,
                        $alphaChars[rand(0, strlen($alphaChars) - 1)],
                        $pos,
                        0
                    );
                }
                $merchantId = substr($merchantId, 0, 16);

                $user->merchant_id = $merchantId;
                $user->save();

                // Handle file paths
                $registrationDocPath = null;
                $idDocPath = null;

                // File keys are e.g. "sub_businesses_0_registration_document"
                if ($request->hasFile("sub_businesses_{$index}_registration_document")) {
                    $regFile = $request->file("sub_businesses_{$index}_registration_document");
                    $regPath = $regFile->store('business_documents', 's3');
                    $registrationDocPath = Storage::disk('s3')->url($regPath);
                }

                if ($request->hasFile("sub_businesses_{$index}_account_holder_id_document")) {
                    $idFile = $request->file("sub_businesses_{$index}_account_holder_id_document");
                    $idPath = $idFile->store('kyc_documents', 's3');
                    $idDocPath = Storage::disk('s3')->url($idPath);
                }

                // 1. Create AccountHolder record
                $accountHolder = AccountHolder::create([
                    'first_name' => $subBusiness['account_holder_first_name'],
                    'last_name' => $subBusiness['account_holder_last_name'],
                    'email' => $subBusiness['account_holder_email'],
                    'date_of_birth' => $subBusiness['account_holder_date_of_birth'],
                    'street' => $subBusiness['account_holder_street'],
                    'street_line2' => $subBusiness['account_holder_street_line2'] ?? null,
                    'city' => $subBusiness['account_holder_city'],
                    'state' => $subBusiness['account_holder_state'],
                    'zip_code' => $subBusiness['account_holder_zip_code'] ?? null,
                    'country' => $subBusiness['account_holder_country'],
                    'id_type' => $subBusiness['account_holder_id_type'],
                    'id_number' => $subBusiness['account_holder_id_number'],
                    'id_document_path' => $idDocPath,
                ]);

                // 2. Create BusinessProfile record linked to AccountHolder
                $businessProfile = BusinessProfile::create([
                    'user_id' => $user->id,
                    'service_type' => 'card_scan',
                    'account_holder_id' => $accountHolder->id,
                    'email' => $subBusiness['sub_b_email'],
                    'business_name' => $subBusiness['sub_b_name'],
                    'business_registration_number' => $subBusiness['sub_b_reg_no'],
                    'street' => $subBusiness['sub_b_street'],
                    'street_line2' => $subBusiness['sub_b_street_line2'] ?? null,
                    'city' => $subBusiness['sub_b_city'],
                    'state' => $subBusiness['sub_b_state'],
                    'zip_code' => $subBusiness['sub_b_zip_code'],
                    'country' => $subBusiness['sub_b_country'],
                    'registration_document_path' => $registrationDocPath,
                ]);

                $createdSubBusinesses[] = [
                    'user' => $user->fresh(),
                    'business_profile' => $businessProfile->load('accountHolder')
                ];
            }

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Sub-businesses created successfully and parent role updated',
                'data' => [
                    'parent_id' => $parentId,
                    'sub_businesses' => $createdSubBusinesses
                ]
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => 'Failed to create sub-businesses',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Retrieve all sub-businesses belonging to a parent user internally.
     */
    public function getSubBusinesses(Request $request)
    {
        $token = $request->header('X-Internal-Service-Token');
        if ($token !== config('services.internal.token')) {
            return response()->json(['status' => false, 'message' => 'Unauthorized internal request'], 401);
        }

        $parentId = $request->input('parent_id');
        $parentUser = Users::where('merchant_id', $parentId)->first();

        if (!$parentUser) {
            return response()->json([
                'status' => false,
                'message' => 'Parent user not found',
                'code' => 404
            ], 404);
        }

        // Get all sub-businesses under this parent user
        $subBusinesses = Users::with(['businessProfile.accountHolder'])
            ->where('parent_id', $parentUser->merchant_id)
            ->where('role', 'SUB_BUSINESS')
            ->get();

        // Format exactly matching the original format
        $formattedSubBusinesses = $subBusinesses->map(function ($user) {
            $profile = $user->businessProfile;
            $accountHolder = $profile ? $profile->accountHolder : null;

            return [
                'merchant_id' => $user->merchant_id,
                'email' => $user->email,
                'created_at' => $user->created_at,
                'business_profile' => $profile ? [
                    'business_name' => $profile->business_name,
                    'business_registration_number' => $profile->business_registration_number,
                    'street' => $profile->street,
                    'street_line2' => $profile->street_line2,
                    'city' => $profile->city,
                    'state' => $profile->state,
                    'zip_code' => $profile->zip_code,
                    'country' => $profile->country,
                    'account_holder_first_name' => $accountHolder ? $accountHolder->first_name : null,
                    'account_holder_last_name' => $accountHolder ? $accountHolder->last_name : null,
                    'account_holder_email' => $accountHolder ? $accountHolder->email : null,
                    'account_holder_date_of_birth' => $accountHolder ? $accountHolder->date_of_birth : null,
                    'account_holder_id_type' => $accountHolder ? $accountHolder->id_type : null,
                    'account_holder_id_number' => $accountHolder ? $accountHolder->id_number : null,
                    'registration_document_url' => $profile->registration_document_path
                        ? (filter_var($profile->registration_document_path, FILTER_VALIDATE_URL) ? $profile->registration_document_path : asset('storage/' . $profile->registration_document_path))
                        : null,
                    'account_holder_id_document_url' => ($accountHolder && $accountHolder->id_document_path)
                        ? (filter_var($accountHolder->id_document_path, FILTER_VALIDATE_URL) ? $accountHolder->id_document_path : asset('storage/' . $accountHolder->id_document_path))
                        : null,
                ] : null
            ];
        });

        return response()->json([
            'status' => true,
            'message' => 'Sub-businesses retrieved successfully',
            'data' => [
                'parent_id' => $parentUser->merchant_id,
                'sub_businesses' => $formattedSubBusinesses
            ]
        ], 200);
    }

    /**
     * Retrieve all enterprise users with their sub-businesses internally.
     */
    public function getEnterpriseUsersWithSubBusinesses(Request $request)
    {
        $token = $request->header('X-Internal-Service-Token');
        if ($token !== config('services.internal.token')) {
            return response()->json(['status' => false, 'message' => 'Unauthorized internal request'], 401);
        }

        try {
            // Get all enterprise users with their business profiles and account holders
            $enterpriseUsers = Users::with(['businessProfile.accountHolder'])
                ->where('role', 'ENTERPRISE_USER')
                ->get();

            // Format the response with their sub-businesses
            $formattedEnterpriseUsers = $enterpriseUsers->map(function ($enterpriseUser) {
                // Get all sub-businesses for this enterprise user
                $subBusinesses = Users::with(['businessProfile.accountHolder'])
                    ->where('parent_id', $enterpriseUser->merchant_id)
                    ->where('role', 'SUB_BUSINESS')
                    ->get()
                    ->map(function ($user) {
                        $profile = $user->businessProfile;
                        $accountHolder = $profile ? $profile->accountHolder : null;

                        return [
                            'merchant_id' => $user->merchant_id,
                            'email' => $user->email,
                            'aes_key' => $user->aes_key,
                            'business_verified' => $user->business_verified,
                            'created_at' => $user->created_at,
                            'business_profile' => $profile ? [
                                'business_name' => $profile->business_name,
                                'business_registration_number' => $profile->business_registration_number,
                                'street' => $profile->street,
                                'street_line2' => $profile->street_line2,
                                'city' => $profile->city,
                                'state' => $profile->state,
                                'zip_code' => $profile->zip_code,
                                'country' => $profile->country,
                                'account_holder_first_name' => $accountHolder ? $accountHolder->first_name : null,
                                'account_holder_last_name' => $accountHolder ? $accountHolder->last_name : null,
                                'account_holder_email' => $accountHolder ? $accountHolder->email : null,
                                'account_holder_date_of_birth' => $accountHolder ? $accountHolder->date_of_birth : null,
                                'account_holder_id_type' => $accountHolder ? $accountHolder->id_type : null,
                                'account_holder_id_number' => $accountHolder ? $accountHolder->id_number : null,
                                'registration_document_url' => $profile->registration_document_path
                                    ? (filter_var($profile->registration_document_path, FILTER_VALIDATE_URL) ? $profile->registration_document_path : asset('storage/' . $profile->registration_document_path))
                                    : null,
                                'account_holder_id_document_url' => ($accountHolder && $accountHolder->id_document_path)
                                    ? (filter_var($accountHolder->id_document_path, FILTER_VALIDATE_URL) ? $accountHolder->id_document_path : asset('storage/' . $accountHolder->id_document_path))
                                    : null,
                            ] : null
                        ];
                    });

                $entProfile = $enterpriseUser->businessProfile;
                $entAccountHolder = $entProfile ? $entProfile->accountHolder : null;

                return [
                    'enterprise_user' => [
                        'merchant_id' => $enterpriseUser->merchant_id,
                        'email' => $enterpriseUser->email,
                        'aes_key' => $enterpriseUser->aes_key,
                        'business_verified' => $enterpriseUser->business_verified,
                        'created_at' => $enterpriseUser->created_at,
                        'business_profile' => $entProfile ? [
                            'business_name' => $entProfile->business_name,
                            'business_registration_number' => $entProfile->business_registration_number,
                            'street' => $entProfile->street,
                            'street_line2' => $entProfile->street_line2,
                            'city' => $entProfile->city,
                            'state' => $entProfile->state,
                            'zip_code' => $entProfile->zip_code,
                            'country' => $entProfile->country,
                            'account_holder_first_name' => $entAccountHolder ? $entAccountHolder->first_name : null,
                            'account_holder_last_name' => $entAccountHolder ? $entAccountHolder->last_name : null,
                            'account_holder_email' => $entAccountHolder ? $entAccountHolder->email : null,
                            'account_holder_date_of_birth' => $entAccountHolder ? $entAccountHolder->date_of_birth : null,
                            'account_holder_id_type' => $entAccountHolder ? $entAccountHolder->id_type : null,
                            'account_holder_id_number' => $entAccountHolder ? $entAccountHolder->id_number : null,
                            'registration_document_url' => $entProfile->registration_document_path
                                ? (filter_var($entProfile->registration_document_path, FILTER_VALIDATE_URL) ? $entProfile->registration_document_path : asset('storage/' . $entProfile->registration_document_path))
                                : null,
                            'account_holder_id_document_url' => ($entAccountHolder && $entAccountHolder->id_document_path)
                                ? (filter_var($entAccountHolder->id_document_path, FILTER_VALIDATE_URL) ? $entAccountHolder->id_document_path : asset('storage/' . $entAccountHolder->id_document_path))
                                : null,
                        ] : null
                    ],
                    'sub_businesses' => $subBusinesses,
                    'sub_businesses_count' => $subBusinesses->count()
                ];
            });

            return response()->json([
                'status' => true,
                'message' => 'Enterprise users with their sub-businesses retrieved successfully',
                'data' => $formattedEnterpriseUsers,
                'total_enterprise_users' => $enterpriseUsers->count()
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve enterprise users with sub-businesses',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
