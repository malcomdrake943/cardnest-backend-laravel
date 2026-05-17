<?php

namespace App\Http\Controllers;

use App\Models\AccountHolder;
use App\Models\BusinessProfile;
use App\Models\Users;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Mail;
use App\Mail\welcomemail;
use App\Mail\adminemail;
use Illuminate\Support\Facades\Storage;

class BusinessProfileController extends Controller
{
    /**
     * Display a listing of the pending busniess profile.
     */
    public function index()
    {
        try {
            $profiles = BusinessProfile::with([
                'user' => function ($query) {
                    $query->where('business_verified', 'PENDING');
                },
                'accountHolder'
            ])
                ->whereHas('user', function ($query) {
                    $query->where('business_verified', 'PENDING');
                    $query->where('service_type', 'card_scan');
                })
                ->get();

            // Append service_type to the top level of each profile response
            $profiles->map(function ($profile) {
                if ($profile->user) {
                    $profile->service_type = $profile->user->service_type;
                }
                return $profile;
            });

            return response()->json([
                'status' => true,
                'data' => $profiles
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to fetch documents',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Show the approved business profile.
     */
    public function business_verified_approved()
    {
        try {
            $profiles = BusinessProfile::with('user', 'accountHolder')
                ->whereHas('user', function ($query) {
                    $query->where('business_verified', 'APPROVED');
                })
                ->get();

            // Append service_type to the top level of each profile response
            $profiles->map(function ($profile) {
                if ($profile->user) {
                    $profile->service_type = $profile->user->service_type;
                }
                return $profile;
            });

            return response()->json([
                'status' => true,
                'data' => $profiles
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to fetch documents',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created business profile in storage.
     */
    public function store(Request $request)
    {
        // Get the user by email
        $user = Users::where('email', $request->email)->first();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User not found',
                'errors' => ['user' => 'The specified user does not exist']
            ], 404);
        }

        // Validate the request
        $validator = Validator::make($request->all(), [
            'business_name' => 'required|string|max:255',
            'business_registration_number' => 'required|string|max:255',
            'street' => 'required|string|max:255',
            'street_line2' => 'nullable|string|max:255',
            'city' => 'required|string|max:255',
            'state' => 'required|string|max:255',
            'zip_code' => 'nullable|string|max:20',
            'country' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'account_holder_first_name' => 'required|string|max:255',
            'account_holder_last_name' => 'required|string|max:255',
            'account_holder_email' => 'required|email|max:255',
            'account_holder_date_of_birth' => 'required|date|before:today',
            'account_holder_street' => 'required|string|max:255',
            'account_holder_street_line2' => 'nullable|string|max:255',
            'account_holder_city' => 'required|string|max:255',
            'account_holder_state' => 'required|string|max:255',
            'account_holder_zip_code' => 'nullable|string|max:20',
            'account_holder_country' => 'required|string|max:255',
            'account_holder_id_type' => 'required|string',
            'account_holder_id_number' => 'required|string|max:255',
            'registration_document' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10000',
            'account_holder_id_document' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10000',
            'service_type' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation Error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Handle registration document upload
            $registrationFile = $request->file('registration_document');
            $registrationFileName = time() . '_registration_' . $registrationFile->getClientOriginalName();
            $registrationFilePath = $registrationFile->storeAs('business_documents', $registrationFileName, 'public');
            $registrationPublicUrl = asset('storage/' . $registrationFilePath);

            // Handle ID document upload
            $idFile = $request->file('account_holder_id_document');
            $idFileName = time() . '_id_' . $idFile->getClientOriginalName();
            $idFilePath = $idFile->storeAs('kyc_documents', $idFileName, 'public');
            $idPublicUrl = asset('storage/' . $idFilePath);

            // Check if business profile exists
            $businessProfile = BusinessProfile::where('user_id', $user->id)->first();
            $account_holder = $businessProfile?->accountHolder; //gets the account holder data

            $businessProfileData = [
                'user_id' => $user->id,
                'business_name' => $request->business_name,
                'business_registration_number' => $request->business_registration_number,
                'street' => $request->street,
                'street_line2' => $request->street_line2,
                'city' => $request->city,
                'state' => $request->state,
                'zip_code' => $request->zip_code,
                'country' => $request->country,
                'email' => $request->email,
                'service_type' => $request->service_type,
                'registration_document_path' => $registrationPublicUrl,
            ];

            $accountHolderData = [
                'first_name' => $request->account_holder_first_name,
                'last_name' => $request->account_holder_last_name,
                'email' => $request->account_holder_email,
                'date_of_birth' => $request->account_holder_date_of_birth,
                'street' => $request->account_holder_street,
                'street_line2' => $request->account_holder_street_line2,
                'city' => $request->account_holder_city,
                'state' => $request->account_holder_state,
                'zip_code' => $request->account_holder_zip_code,
                'country' => $request->account_holder_country,
                'id_type' => $request->account_holder_id_type,
                'id_number' => $request->account_holder_id_number,
                'id_document_path' => $idPublicUrl,
            ];

            if ($businessProfile) {
                // Delete old files if they exist
                if ($businessProfile->registration_document_path) {
                    $oldRegPath = str_replace(asset('storage/'), '', $businessProfile->registration_document_path);
                    Storage::disk('public')->delete($oldRegPath);
                }
                if ($account_holder && $account_holder->id_document_path) {
                    $oldIdPath = str_replace(asset('storage/'), '', $account_holder->id_document_path);
                    Storage::disk('public')->delete($oldIdPath);
                }

                // Update existing profile
                $businessProfile->update($businessProfileData);
                if ($account_holder) {
                    $account_holder->update($accountHolderData);
                }

                $message = 'Business profile updated successfully';
                $statusCode = 200;
            } else {
                // Create new profile
                $businessProfileData['user_id'] = $user->id;
                $businessProfile = BusinessProfile::create($businessProfileData);
                $account_holder = AccountHolder::create($accountHolderData);
                $businessProfile->update([
                    'account_holder_id' => $account_holder->id
                ]);
                $message = 'Business profile created successfully';
                $statusCode = 201;
            }

            // Update user's business verification status to PENDING and update service_type
            $user->update([
                'business_verified' => 'PENDING',
                'service_type' => $request->service_type ?? "card_scan"
            ]);

            // MAIL CODE
            $accountHolderFirstName = 'Customer';
            if ($request->account_holder_first_name && !empty($request->account_holder_first_name)) {
                $accountHolderFirstName = $request->account_holder_first_name;
            }
            $toEmail = $request->email;
            $message = "signup";
            $subject = "Thank You for Choosing CardNest – Your Application is Under Review";

            $email1 = Mail::to($toEmail)->send(new welcomemail($message, $subject, $accountHolderFirstName));
            // MAIL CODE

            // MAIL 2 CODE
            $pendingProfiles = BusinessProfile::with('user')
                ->whereHas('user', function ($query) {
                    $query->where('business_verified', 'PENDING');
                })
                ->get();

            // Calculate the values
            $totalApplicationsPending = $pendingProfiles->count();

            $totalApplicationsPending = $totalApplicationsPending ? $totalApplicationsPending : '0';
            $businessNames = $request->business_name ? $request->business_name : 'No Business Name';
            $submissionDates = now()->format('Y-m-d');

            $admin = [
                'total_application_pending' => $totalApplicationsPending,
                'submission_dates' => $submissionDates,
                'customer_business_names' => $businessNames
            ];

            $toEmail = 'support@cardnest.io';
            $moreuser = 'ericpope20@yahoo.com';
            $mailType = "admin";
            $subject = "Action Required – New Pending Customer/Business Applications for Review";

            $email2 = Mail::to($toEmail)->cc($moreuser)->send(new adminemail($mailType, $subject, $admin));
            // MAIL 2 CODE

            return response()->json([
                'status' => true,
                'message' => $message,
                'data' => $businessProfile
            ], $statusCode);

        } catch (\Exception $e) {
            // Delete any uploaded files if something went wrong
            if (isset($registrationFilePath)) {
                Storage::disk('public')->delete($registrationFilePath);
            }
            if (isset($idFilePath)) {
                Storage::disk('public')->delete($idFilePath);
            }

            return response()->json([
                'status' => false,
                'message' => 'Server Error',
                'errors' => ['server' => $e->getMessage()]
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function updateStatus(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:users,id',
            'status' => 'required|string|in:APPROVED,PENDING,INCOMPLETE',
            'reason' => 'required_if:status,INCOMPLETE|string|nullable'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Find the user with their current business profile
            $user = Users::with('businessProfile')->find($request->user_id);

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'User not found'
                ], 404);
            }

            // Get previous status
            $previousStatus = $user->business_verified;
            $newStatus = $request->status;

            if ($newStatus === 'APPROVED') {
                // Check for account_holder_first_name with multiple fallbacks
                $accountHolderFirstName = 'Customer'; // Default string

                if ($user->businessProfile && !empty($user->businessProfile->accountHolder && $user->businessProfile->accountHolder->first_name)) {
                    $accountHolderFirstName = $user->businessProfile->accountHolder->first_name;
                }

                // MAIL CODE
                $toEmail = $user->email;
                $message = "approved";
                $subject = "Welcome to CardNest – Your Application Has Been Approved!";

                $emailResponse = Mail::to($toEmail)->send(new approvedemail($message, $subject, $accountHolderFirstName));
                // MAIL CODE    
            }

            // Update the status and reason if provided
            $user->business_verified = $newStatus;

            if ($newStatus === 'INCOMPLETE') {
                $user->verification_reason = $emailResponse->reason;
            } else {
                $user->verification_reason = null; // Clear reason if status changes from INCOMPLETE
            }

            $user->save();

            return response()->json([
                'status' => true,
                'message' => $this->getStatusMessage($newStatus),
                'data' => [
                    'user_id' => $user->id,
                    'previous_status' => $previousStatus,
                    'new_status' => $newStatus,
                    'status_changed' => ($previousStatus !== $newStatus),
                    'reason' => $newStatus === 'INCOMPLETE' ? $request->reason : null,
                    'business_profile' => $user->businessProfile
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to update status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function getStatus(Request $request)
    {
        // Validate the request
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:users,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Get user with business profile
            $user = Users::with('businessProfile')
                ->find($request->user_id);

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'User not found'
                ], 404);
            }

            // Get all user attributes
            $userData = $user->toArray();

            // Add verification status message
            $userData['verification_status_message'] = $this->getStatusMessage($user->business_verified);

            // Include the business profile data
            if ($user->businessProfile) {
                $userData['business_profile'] = $user->businessProfile->toArray();
            } else {
                $userData['business_profile'] = null;
            }

            return response()->json([
                'status' => true,
                'message' => 'User data retrieved successfully',
                'data' => $userData
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve user data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    protected function getStatusMessage($status)
    {
        $messages = [
            'APPROVED' => 'Business documents approved successfully',
            'PENDING' => 'Business verification status set to pending',
            'INCOMPLETE' => 'Business documents marked as incomplete'
        ];

        return $messages[$status] ?? 'Status updated';
    }

}
