<?php

namespace App\Http\Controllers;

use App\Models\MerchantAdminAuditLog;
use App\Models\Users;
use App\Support\MerchantPermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class MerchantAdminController extends Controller
{
    /**
     * GET /api/merchant/admins
     * List all additional admins scoped to the authenticated merchant.
     */
    public function index(Request $request): JsonResponse
    {
        $context = $this->resolveAdminContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }
        [, $merchantUserId] = $context;

        $query = Users::where('parent_merchant_id', $merchantUserId)
            ->where('is_additional_admin', true)
            ->orderByDesc('created_at');

        if ($request->filled('status') && in_array($request->query('status'), ['active', 'inactive'], true)) {
            $query->where('admin_status', $request->query('status'));
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->query('search'));
            $query->where(function ($q) use ($search) {
                $q->where('email', 'like', '%' . $search . '%')
                    ->orWhere('phone_number', 'like', '%' . $search . '%');
            });
        }

        $admins = $query->get()->map(fn (Users $admin) => $this->formatAdmin($admin))->values();

        return response()->json([
            'status' => true,
            'message' => 'Admins retrieved successfully.',
            'data' => $admins,
        ], 200);
    }

    /**
     * POST /api/merchant/admins
     * Create a new additional admin under the authenticated merchant.
     */
    public function store(Request $request): JsonResponse
    {
        $context = $this->resolveAdminContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }
        [$actorUser, $merchantUserId, $primaryMerchant] = $context;

        $validator = Validator::make(
            $request->all(),
            [
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'country_code' => ['required', 'string', 'regex:/^\+[1-9]\d{0,4}$/'],
                'phone_no' => ['required', 'string', 'regex:/^[0-9]{6,15}$/', 'unique:users,phone_number'],
                'access_type' => ['required', 'string', Rule::in(['full', 'custom'])],
                'permissions' => $request->input('access_type') === 'full'
                    ? ['nullable', 'array']
                    : ['required', 'array', 'min:1'],
                'permissions.*' => ['required', 'string', Rule::in(MerchantPermissions::all())],
            ],
            [
                'phone_no.unique' => 'The phone number has already been taken.',
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $accessType = (string) $request->input('access_type');
        $resolvedPermissions = MerchantPermissions::resolvePermissions($accessType, $request->input('permissions'));

        if ($escalationResponse = $this->checkPrivilegeEscalation($actorUser, $accessType, $resolvedPermissions)) {
            return $escalationResponse;
        }

        $admin = DB::transaction(function () use ($request, $actorUser, $merchantUserId, $primaryMerchant, $accessType, $resolvedPermissions) {
            $created = Users::create([
                'parent_merchant_id' => $merchantUserId,
                'is_additional_admin' => true,
                'access_type' => $accessType,
                'permissions' => $resolvedPermissions,
                'admin_status' => 'active',
                'email' => $request->input('email'),
                'country_code' => $request->input('country_code'),
                'phone_number' => $request->input('phone_no'),
                'country_name' => $primaryMerchant->country_name,
                'service_type' => $primaryMerchant->service_type ?? 'card_scan',
                'business_verified' => $primaryMerchant->business_verified ?? 'APPROVED',
                'role' => $primaryMerchant->role === 'ENTERPRISE_USER' ? 'ENTERPRISE_USER' : 'BUSINESS_USER',
                'on_trial' => false,
                'trial_calls_remaining' => null,
                'trial_ends_at' => null,
            ]);

            $this->recordAudit(
                actorUser: $actorUser,
                merchantUserId: $merchantUserId,
                targetUserId: $created->id,
                action: 'admin.created',
                oldValues: null,
                newValues: [
                    'email' => $created->email,
                    'country_code' => $created->country_code,
                    'phone_no' => $created->phone_number,
                    'access_type' => $created->access_type,
                    'permissions' => $created->permissions,
                    'status' => $created->admin_status,
                ],
                request: $request
            );

            return $created;
        });

        return response()->json([
            'status' => true,
            'message' => 'Additional admin account created successfully.',
            'data' => $this->formatAdmin($admin),
        ], 201);
    }

    /**
     * GET /api/merchant/admins/{id}
     * Retrieve single additional admin details scoped to the merchant.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $context = $this->resolveAdminContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }
        [, $merchantUserId] = $context;

        $admin = Users::where('id', $id)
            ->where('parent_merchant_id', $merchantUserId)
            ->where('is_additional_admin', true)
            ->first();

        if (!$admin) {
            return response()->json([
                'status' => false,
                'message' => 'Admin not found.',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'data' => $this->formatAdmin($admin),
        ], 200);
    }

    /**
     * PUT /api/merchant/admins/{id}/permissions
     * Update an additional admin's access_type and granular permissions.
     */
    public function updatePermissions(Request $request, int $id): JsonResponse
    {
        $context = $this->resolveAdminContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }
        [$actorUser, $merchantUserId] = $context;

        if ($guardResponse = $this->guardProtectedTarget($id, $actorUser, $merchantUserId)) {
            return $guardResponse;
        }

        $targetAdmin = Users::where('id', $id)
            ->where('parent_merchant_id', $merchantUserId)
            ->where('is_additional_admin', true)
            ->first();

        if (!$targetAdmin) {
            return response()->json([
                'status' => false,
                'message' => 'Admin not found.',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'access_type' => ['required', 'string', Rule::in(['full', 'custom'])],
            'permissions' => $request->input('access_type') === 'full'
                ? ['nullable', 'array']
                : ['required', 'array', 'min:1'],
            'permissions.*' => ['required', 'string', Rule::in(MerchantPermissions::all())],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $accessType = (string) $request->input('access_type');
        $resolvedPermissions = MerchantPermissions::resolvePermissions($accessType, $request->input('permissions'));

        if ($escalationResponse = $this->checkPrivilegeEscalation($actorUser, $accessType, $resolvedPermissions)) {
            return $escalationResponse;
        }

        $oldValues = [
            'access_type' => $targetAdmin->access_type,
            'permissions' => $targetAdmin->getEffectivePermissions(),
        ];

        $targetAdmin->update([
            'access_type' => $accessType,
            'permissions' => $resolvedPermissions,
        ]);

        $this->recordAudit(
            actorUser: $actorUser,
            merchantUserId: $merchantUserId,
            targetUserId: $targetAdmin->id,
            action: 'admin.permissions_updated',
            oldValues: $oldValues,
            newValues: [
                'access_type' => $targetAdmin->access_type,
                'permissions' => $targetAdmin->getEffectivePermissions(),
            ],
            request: $request
        );

        return response()->json([
            'status' => true,
            'message' => 'Admin permissions updated successfully.',
            'data' => [
                'id' => $targetAdmin->id,
                'access_type' => $targetAdmin->access_type,
                'permissions' => $targetAdmin->getEffectivePermissions(),
                'updated_at' => $targetAdmin->updated_at,
            ],
        ], 200);
    }

    /**
     * PATCH /api/merchant/admins/{id}/status
     * Toggle an additional admin between active and inactive, revoking tokens on deactivation.
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $context = $this->resolveAdminContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }
        [$actorUser, $merchantUserId] = $context;

        if ($guardResponse = $this->guardProtectedTarget($id, $actorUser, $merchantUserId)) {
            return $guardResponse;
        }

        $targetAdmin = Users::where('id', $id)
            ->where('parent_merchant_id', $merchantUserId)
            ->where('is_additional_admin', true)
            ->first();

        if (!$targetAdmin) {
            return response()->json([
                'status' => false,
                'message' => 'Admin not found.',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'status' => ['required', 'string', Rule::in(['active', 'inactive'])],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $newStatus = (string) $request->input('status');
        $oldStatus = $targetAdmin->admin_status ?? 'active';

        $updatePayload = ['admin_status' => $newStatus];
        if ($newStatus === 'inactive') {
            $updatePayload['tokens_revoked_at'] = now();
        }

        $targetAdmin->update($updatePayload);

        $this->recordAudit(
            actorUser: $actorUser,
            merchantUserId: $merchantUserId,
            targetUserId: $targetAdmin->id,
            action: 'admin.status_toggled',
            oldValues: ['status' => $oldStatus],
            newValues: ['status' => $newStatus],
            request: $request
        );

        return response()->json([
            'status' => true,
            'message' => "Admin status updated to {$newStatus} successfully.",
            'data' => [
                'id' => $targetAdmin->id,
                'status' => $targetAdmin->admin_status,
                'updated_at' => $targetAdmin->updated_at,
            ],
        ], 200);
    }

    /**
     * DELETE /api/merchant/admins/{id}
     * Permanently revoke and remove an additional admin account.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $context = $this->resolveAdminContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }
        [$actorUser, $merchantUserId] = $context;

        if ($guardResponse = $this->guardProtectedTarget($id, $actorUser, $merchantUserId)) {
            return $guardResponse;
        }

        $targetAdmin = Users::where('id', $id)
            ->where('parent_merchant_id', $merchantUserId)
            ->where('is_additional_admin', true)
            ->first();

        if (!$targetAdmin) {
            return response()->json([
                'status' => false,
                'message' => 'Admin not found.',
            ], 404);
        }

        DB::transaction(function () use ($actorUser, $merchantUserId, $targetAdmin, $request) {
            $oldValues = [
                'email' => $targetAdmin->email,
                'country_code' => $targetAdmin->country_code,
                'phone_no' => $targetAdmin->phone_number,
                'access_type' => $targetAdmin->access_type,
                'permissions' => $targetAdmin->getEffectivePermissions(),
                'status' => $targetAdmin->admin_status,
            ];

            $targetAdmin->update(['tokens_revoked_at' => now()]);

            $this->recordAudit(
                actorUser: $actorUser,
                merchantUserId: $merchantUserId,
                targetUserId: $targetAdmin->id,
                action: 'admin.deleted',
                oldValues: $oldValues,
                newValues: null,
                request: $request
            );

            $targetAdmin->delete();
        });

        return response()->json([
            'status' => true,
            'message' => 'Admin account revoked and removed successfully.',
        ], 200);
    }

    /**
     * Resolve the acting user, enforce permission boundary, and return tenant scope.
     *
     * @return array{0: Users, 1: int, 2: Users}|JsonResponse
     */
    private function resolveAdminContext(Request $request): array|JsonResponse
    {
        $actorId = $request->header('X-Actor-User-ID') ?? $request->header('X-User-ID');
        $actorUser = $actorId ? Users::find($actorId) : auth('api')->user();

        if (!$actorUser) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized.',
            ], 401);
        }

        if ($actorUser->is_additional_admin && $actorUser->admin_status === 'inactive') {
            return response()->json([
                'status' => false,
                'message' => 'Your account has been deactivated by the merchant administrator. Please contact your organization owner.',
            ], 403);
        }

        if ($actorUser->is_additional_admin && !$actorUser->hasMerchantPermission('dashboard.admin_management')) {
            return response()->json([
                'status' => false,
                'message' => 'Forbidden: You do not have permission to manage merchant admins.',
            ], 403);
        }

        $merchantUserId = $actorUser->getEffectiveMerchantUserId();
        $primaryMerchant = $merchantUserId === (int) $actorUser->id
            ? $actorUser
            : Users::find($merchantUserId);

        if (!$primaryMerchant) {
            return response()->json([
                'status' => false,
                'message' => 'Primary merchant account not found.',
            ], 404);
        }

        return [$actorUser, $merchantUserId, $primaryMerchant];
    }

    /**
     * Block targeting the Primary Admin or the acting user's own account.
     */
    private function guardProtectedTarget(int $targetId, Users $actorUser, int $merchantUserId): ?JsonResponse
    {
        if ($targetId === $merchantUserId) {
            return response()->json([
                'status' => false,
                'message' => 'Forbidden: Primary merchant admin account cannot be altered or deleted.',
            ], 403);
        }

        if ($targetId === (int) $actorUser->id) {
            return response()->json([
                'status' => false,
                'message' => 'Forbidden: You cannot alter or delete your own admin account.',
            ], 403);
        }

        return null;
    }

    /**
     * Prevent privilege escalation by additional admins.
     *
     * @param  list<string>  $requestedPermissions
     */
    private function checkPrivilegeEscalation(Users $actorUser, string $accessType, array $requestedPermissions): ?JsonResponse
    {
        if (!$actorUser->is_additional_admin) {
            return null;
        }

        if ($accessType === 'full' && $actorUser->access_type !== 'full') {
            return response()->json([
                'status' => false,
                'message' => 'Forbidden: You cannot grant full access without possessing full access.',
            ], 403);
        }

        $actorPermissions = $actorUser->getEffectivePermissions();
        $unauthorized = array_diff($requestedPermissions, $actorPermissions);

        if (!empty($unauthorized)) {
            return response()->json([
                'status' => false,
                'message' => 'Forbidden: You cannot grant permissions that you do not possess.',
            ], 403);
        }

        return null;
    }

    /**
     * Format an additional admin user model to match the IAM API specification.
     *
     * @return array<string, mixed>
     */
    private function formatAdmin(Users $admin): array
    {
        return [
            'id' => $admin->id,
            'email' => $admin->email,
            'country_code' => $admin->country_code,
            'phone_no' => $admin->phone_number,
            'access_type' => $admin->access_type ?? 'custom',
            'permissions' => $admin->getEffectivePermissions(),
            'status' => $admin->admin_status ?? 'active',
            'created_at' => $admin->created_at,
            'updated_at' => $admin->updated_at,
        ];
    }

    /**
     * Persist an immutable audit log entry for IAM actions.
     *
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    private function recordAudit(
        Users $actorUser,
        int $merchantUserId,
        ?int $targetUserId,
        string $action,
        ?array $oldValues,
        ?array $newValues,
        Request $request
    ): void {
        MerchantAdminAuditLog::create([
            'merchant_id' => $merchantUserId,
            'performed_by_user_id' => $actorUser->id,
            'target_user_id' => $targetUserId,
            'action' => $action,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent() ? substr((string) $request->userAgent(), 0, 255) : null,
        ]);
    }
}

