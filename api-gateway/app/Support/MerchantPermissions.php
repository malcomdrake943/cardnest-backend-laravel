<?php

namespace App\Support;

class MerchantPermissions
{
    /**
     * Strict boundary of all valid Merchant Admin permission keys.
     * Super Admin permissions must never be included here.
     */
    public const ALL_PERMISSIONS = [
        'dashboard.overview',
        'dashboard.profile',
        'dashboard.scans',
        'dashboard.security_features',
        'dashboard.card_types',
        'dashboard.subscriptions',
        'dashboard.billing',
        'dashboard.documents',
        'dashboard.display_settings',
        'dashboard.developers',
        'dashboard.sub_businesses',
        'dashboard.kyc',
        'dashboard.admin_management',
    ];

    /**
     * Return all valid Merchant Admin permission keys.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return self::ALL_PERMISSIONS;
    }

    /**
     * Check whether a permission identifier is valid.
     */
    public static function isValid(string $permission): bool
    {
        return in_array($permission, self::ALL_PERMISSIONS, true);
    }

    /**
     * Normalize stored permissions according to access type.
     *
     * @param  array<int, string>|null  $permissions
     * @return list<string>
     */
    public static function resolvePermissions(string $accessType, ?array $permissions = null): array
    {
        if ($accessType === 'full') {
            return self::ALL_PERMISSIONS;
        }

        if (!is_array($permissions)) {
            return [];
        }

        $filtered = array_filter(
            $permissions,
            fn ($item) => is_string($item) && self::isValid($item)
        );

        return array_values(array_unique($filtered));
    }
}

