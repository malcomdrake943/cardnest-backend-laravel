<?php

namespace Tests\Feature;

use App\Models\AccountHolder;
use App\Models\BusinessProfile;
use App\Models\MerchantAdminAuditLog;
use App\Models\Users;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MerchantAdminIamTest extends TestCase
{
    use RefreshDatabase;

    private string $privateKeyPem;
    private string $certificatePem;
    private string $kid = 'test-firebase-kid-1';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'jwt.secret' => str_repeat('a', 64),
            'services.internal.token' => 'test-internal-token',
            'services.firebase.project_id' => 'cardnest-test-project',
        ]);

        Cache::flush();
        $this->generateTestKeypair();

        Http::fake([
            'https://www.googleapis.com/robot/v1/metadata/x509/*' => Http::response([
                $this->kid => $this->certificatePem,
            ], 200),
        ]);
    }

    public function test_primary_admin_signup_and_login_remain_compatible_with_iam_fields(): void
    {
        $signupResponse = $this->postJson('/api/signup', [
            'email' => 'owner@merchant.com',
            'country_code' => '+1',
            'country_name' => 'United States',
            'phone_no' => '2025550100',
            'service_type' => 'card_scan',
        ]);

        $signupResponse->assertStatus(200)
            ->assertJsonPath('status', true)
            ->assertJsonPath('user.email', 'owner@merchant.com');

        $loginResponse = $this->postJson('/api/login', [
            'country_code' => '+1',
            'login_input' => 'owner@merchant.com',
        ]);

        $loginResponse->assertStatus(200)
            ->assertJsonPath('status', true)
            ->assertJsonPath('user.is_additional_admin', false)
            ->assertJsonPath('user.access_type', 'full')
            ->assertJsonPath('user.status', 'active')
            ->assertJsonPath('user.phone_no', '2025550100')
            ->assertJsonCount(13, 'user.permissions');
    }

    public function test_primary_admin_can_create_list_show_update_toggle_and_delete_additional_admins(): void
    {
        $primary = $this->createPrimaryMerchant();
        $otherMerchant = $this->createPrimaryMerchant([
            'email' => 'other@merchant.com',
            'phone_number' => '2025550199',
            'merchant_id' => 'OTHERMERCHANT123',
        ]);

        $headers = ['X-User-ID' => (string) $primary->id, 'X-Actor-User-ID' => (string) $primary->id];

        // 1. Create custom admin with valid Firebase OTP token
        $token1 = $this->createFirebaseToken('+12025550100');
        $createRes = $this->withHeaders($headers)->postJson('/api/merchant/admins', [
            'firebase_token' => $token1,
            'email' => 'finance@merchant.com',
            'country_code' => '+1',
            'phone_no' => '2025550143',
            'access_type' => 'custom',
            'permissions' => ['dashboard.billing', 'dashboard.subscriptions'],
        ]);

        $createRes->assertStatus(201)
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.email', 'finance@merchant.com')
            ->assertJsonPath('data.phone_no', '2025550143')
            ->assertJsonPath('data.access_type', 'custom')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.permissions', ['dashboard.billing', 'dashboard.subscriptions']);

        $adminId = $createRes->json('data.id');

        // Verify created user does not collide with primary merchant_id lookup
        $createdUser = Users::find($adminId);
        $this->assertTrue($createdUser->is_additional_admin);
        $this->assertEquals($primary->id, $createdUser->parent_merchant_id);
        $this->assertNull($createdUser->merchant_id);

        // Verify audit log for creation
        $this->assertDatabaseHas('merchant_admin_audit_logs', [
            'merchant_id' => $primary->id,
            'performed_by_user_id' => $primary->id,
            'target_user_id' => $adminId,
            'action' => 'admin.created',
        ]);

        // 2. Replay protection blocks reusing the exact same Firebase token
        $replayRes = $this->withHeaders($headers)->postJson('/api/merchant/admins', [
            'firebase_token' => $token1,
            'email' => 'ops@merchant.com',
            'country_code' => '+1',
            'phone_no' => '2025550144',
            'access_type' => 'full',
            'permissions' => ['dashboard.overview'],
        ]);
        $replayRes->assertStatus(403);

        // 3. Create another admin under a different merchant to test tenant isolation
        $otherAdmin = Users::create([
            'parent_merchant_id' => $otherMerchant->id,
            'is_additional_admin' => true,
            'access_type' => 'custom',
            'permissions' => ['dashboard.scans'],
            'admin_status' => 'active',
            'email' => 'alien@other.com',
            'country_code' => '+1',
            'phone_number' => '2025550188',
            'role' => 'BUSINESS_USER',
        ]);

        // 4. List admins (tenant-isolated + search/status filters)
        $listRes = $this->withHeaders($headers)->getJson('/api/merchant/admins?status=active&search=finance');
        $listRes->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'finance@merchant.com');

        // 5. Show admin details (own vs other merchant)
        $this->withHeaders($headers)->getJson("/api/merchant/admins/{$adminId}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $adminId);

        $this->withHeaders($headers)->getJson("/api/merchant/admins/{$otherAdmin->id}")
            ->assertStatus(404);

        // 6. Update permissions (test full with empty array, then switch back to custom)
        $fullPermRes = $this->withHeaders($headers)->putJson("/api/merchant/admins/{$adminId}/permissions", [
            'access_type' => 'full',
            'permissions' => [],
        ]);
        $fullPermRes->assertStatus(200)
            ->assertJsonPath('data.access_type', 'full')
            ->assertJsonCount(13, 'data.permissions');

        $updatePermRes = $this->withHeaders($headers)->putJson("/api/merchant/admins/{$adminId}/permissions", [
            'access_type' => 'custom',
            'permissions' => ['dashboard.overview', 'dashboard.scans', 'dashboard.billing'],
        ]);
        $updatePermRes->assertStatus(200)
            ->assertJsonPath('data.access_type', 'custom')
            ->assertJsonPath('data.permissions', ['dashboard.overview', 'dashboard.scans', 'dashboard.billing']);

        // 7. Toggle status to inactive and verify login is blocked
        $statusRes = $this->withHeaders($headers)->patchJson("/api/merchant/admins/{$adminId}/status", [
            'status' => 'inactive',
        ]);
        $statusRes->assertStatus(200)
            ->assertJsonPath('data.status', 'inactive');

        $createdUser->refresh();
        $this->assertNotNull($createdUser->tokens_revoked_at);

        $blockedLogin = $this->postJson('/api/login', [
            'country_code' => '+1',
            'login_input' => 'finance@merchant.com',
        ]);
        $blockedLogin->assertStatus(403)
            ->assertJsonPath('status', false);

        // Reactivate and verify login returns IAM fields + parent merchant_id
        $this->withHeaders($headers)->patchJson("/api/merchant/admins/{$adminId}/status", [
            'status' => 'active',
        ])->assertStatus(200);

        $subLogin = $this->postJson('/api/login', [
            'country_code' => '+1',
            'login_input' => 'finance@merchant.com',
        ]);
        $subLogin->assertStatus(200)
            ->assertJsonPath('merchant_id', $primary->merchant_id)
            ->assertJsonPath('user.is_additional_admin', true)
            ->assertJsonPath('user.parent_merchant_id', $primary->id)
            ->assertJsonPath('user.permissions', ['dashboard.overview', 'dashboard.scans', 'dashboard.billing'])
            ->assertJsonPath('user.status', 'active');

        // 8. Delete admin
        $this->withHeaders($headers)->deleteJson("/api/merchant/admins/{$adminId}")
            ->assertStatus(200)
            ->assertJsonPath('status', true);

        $this->assertDatabaseMissing('users', ['id' => $adminId]);
        $this->assertEquals(6, MerchantAdminAuditLog::count()); // created, full perms, custom perms, inactive, active, deleted
    }

    public function test_firebase_otp_validation_and_duplicate_checks(): void
    {
        $primary = $this->createPrimaryMerchant();
        $headers = ['X-User-ID' => (string) $primary->id, 'X-Actor-User-ID' => (string) $primary->id];

        // Duplicate email
        $this->withHeaders($headers)->postJson('/api/merchant/admins', [
            'firebase_token' => $this->createFirebaseToken('+12025550100'),
            'email' => $primary->email,
            'country_code' => '+1',
            'phone_no' => '2025550145',
            'access_type' => 'custom',
            'permissions' => ['dashboard.scans'],
        ])->assertStatus(422)->assertJsonStructure(['status', 'message', 'errors' => ['email']]);

        // Duplicate phone_no
        $this->withHeaders($headers)->postJson('/api/merchant/admins', [
            'firebase_token' => $this->createFirebaseToken('+12025550100'),
            'email' => 'unique@merchant.com',
            'country_code' => '+1',
            'phone_no' => $primary->phone_number,
            'access_type' => 'custom',
            'permissions' => ['dashboard.scans'],
        ])->assertStatus(422)
            ->assertJsonPath('message', 'The phone number has already been taken.')
            ->assertJsonStructure(['status', 'message', 'errors' => ['phone_no']]);

        // Super Admin permission rejected
        $this->withHeaders($headers)->postJson('/api/merchant/admins', [
            'firebase_token' => $this->createFirebaseToken('+12025550100'),
            'email' => 'unique2@merchant.com',
            'country_code' => '+1',
            'phone_no' => '2025550146',
            'access_type' => 'custom',
            'permissions' => ['superadmin.access'],
        ])->assertStatus(422);

        // Phone mismatch with Firebase token
        $this->withHeaders($headers)->postJson('/api/merchant/admins', [
            'firebase_token' => $this->createFirebaseToken('+19999999999'),
            'email' => 'unique3@merchant.com',
            'country_code' => '+1',
            'phone_no' => '2025550147',
            'access_type' => 'custom',
            'permissions' => ['dashboard.scans'],
        ])->assertStatus(403);

        // Expired Firebase token
        $this->withHeaders($headers)->postJson('/api/merchant/admins', [
            'firebase_token' => $this->createFirebaseToken('+12025550100', time() - 3600, time() - 1800),
            'email' => 'unique4@merchant.com',
            'country_code' => '+1',
            'phone_no' => '2025550148',
            'access_type' => 'custom',
            'permissions' => ['dashboard.scans'],
        ])->assertStatus(422);
    }

    public function test_privilege_escalation_and_primary_admin_protection(): void
    {
        $primary = $this->createPrimaryMerchant();

        $delegatedAdmin = Users::create([
            'parent_merchant_id' => $primary->id,
            'is_additional_admin' => true,
            'access_type' => 'custom',
            'permissions' => ['dashboard.admin_management', 'dashboard.scans'],
            'admin_status' => 'active',
            'email' => 'iammanager@merchant.com',
            'country_code' => '+1',
            'phone_number' => '2025550155',
            'role' => 'BUSINESS_USER',
        ]);

        $targetAdmin = Users::create([
            'parent_merchant_id' => $primary->id,
            'is_additional_admin' => true,
            'access_type' => 'custom',
            'permissions' => ['dashboard.scans'],
            'admin_status' => 'active',
            'email' => 'target@merchant.com',
            'country_code' => '+1',
            'phone_number' => '2025550156',
            'role' => 'BUSINESS_USER',
        ]);

        $delegatedHeaders = [
            'X-User-ID' => (string) $primary->id,
            'X-Actor-User-ID' => (string) $delegatedAdmin->id,
        ];

        // Cannot grant unheld permission during creation
        $this->withHeaders($delegatedHeaders)->postJson('/api/merchant/admins', [
            'firebase_token' => $this->createFirebaseToken('+12025550155'),
            'email' => 'escalated@merchant.com',
            'country_code' => '+1',
            'phone_no' => '2025550157',
            'access_type' => 'custom',
            'permissions' => ['dashboard.billing'],
        ])->assertStatus(403);

        // Cannot grant full access if actor is custom
        $this->withHeaders($delegatedHeaders)->putJson("/api/merchant/admins/{$targetAdmin->id}/permissions", [
            'access_type' => 'full',
            'permissions' => ['dashboard.scans'],
        ])->assertStatus(403);

        // Cannot target primary admin or self
        $this->withHeaders($delegatedHeaders)->deleteJson("/api/merchant/admins/{$primary->id}")
            ->assertStatus(403);
        $this->withHeaders($delegatedHeaders)->patchJson("/api/merchant/admins/{$primary->id}/status", ['status' => 'inactive'])
            ->assertStatus(403);
        $this->withHeaders($delegatedHeaders)->deleteJson("/api/merchant/admins/{$delegatedAdmin->id}")
            ->assertStatus(403);
    }

    public function test_additional_admin_inherits_parent_business_profile_and_internal_verification(): void
    {
        $primary = $this->createPrimaryMerchant();
        $holder = AccountHolder::create([
            'first_name' => 'John',
            'last_name' => 'Owner',
            'email' => $primary->email,
            'date_of_birth' => '1990-01-01',
            'street' => '123 Main St',
            'city' => 'New York',
            'state' => 'NY',
            'zip_code' => '10001',
            'country' => 'US',
            'id_type' => 'passport',
            'id_number' => 'P1234567',
        ]);

        BusinessProfile::create([
            'user_id' => $primary->id,
            'account_holder_id' => $holder->id,
            'business_name' => 'CardNest Demo LLC',
            'email' => $primary->email,
            'service_type' => 'card_scan',
        ]);

        $subAdmin = Users::create([
            'parent_merchant_id' => $primary->id,
            'is_additional_admin' => true,
            'access_type' => 'custom',
            'permissions' => ['dashboard.scans'],
            'admin_status' => 'active',
            'email' => 'sub@merchant.com',
            'country_code' => '+1',
            'phone_number' => '2025550177',
            'role' => 'BUSINESS_USER',
        ]);

        $statusRes = $this->getJson('/api/business-profile/business-verification-status?user_id=' . $subAdmin->id);
        $statusRes->assertStatus(200)
            ->assertJsonPath('data.merchant_id', $primary->merchant_id)
            ->assertJsonPath('data.business_verified', 'APPROVED')
            ->assertJsonPath('data.business_profile.business_name', 'CardNest Demo LLC')
            ->assertJsonPath('data.is_additional_admin', true)
            ->assertJsonPath('data.permissions', ['dashboard.scans']);

        $internalRes = $this->withHeaders([
            'X-Internal-Service-Token' => 'test-internal-token',
        ])->getJson('/api/internal/verify-user?user_id=' . $subAdmin->id);

        $internalRes->assertStatus(200)
            ->assertJsonPath('data.id', $primary->id)
            ->assertJsonPath('data.merchant_id', $primary->merchant_id)
            ->assertJsonPath('data.actor_user_id', $subAdmin->id)
            ->assertJsonPath('data.is_additional_admin', true);
    }

    private function createPrimaryMerchant(array $overrides = []): Users
    {
        return Users::create(array_merge([
            'merchant_id' => 'MERCHANT12345678',
            'aes_key' => '1234567890123456',
            'email' => 'primary@merchant.com',
            'country_code' => '+1',
            'country_name' => 'United States',
            'phone_number' => '2025550100',
            'service_type' => 'card_scan',
            'business_verified' => 'APPROVED',
            'role' => 'BUSINESS_USER',
            'is_additional_admin' => false,
            'access_type' => 'full',
            'admin_status' => 'active',
        ], $overrides));
    }

    private function generateTestKeypair(): void
    {
        $opensslConfig = [];
        foreach ([getenv('OPENSSL_CONF'), 'D:/xampp/apache/conf/openssl.cnf', 'C:/xampp/apache/conf/openssl.cnf'] as $candidate) {
            if (is_string($candidate) && $candidate !== '' && file_exists($candidate)) {
                $opensslConfig['config'] = $candidate;
                break;
            }
        }

        $res = openssl_pkey_new(array_merge([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ], $opensslConfig));

        openssl_pkey_export($res, $privateKey, null, $opensslConfig);
        $csr = openssl_csr_new(['commonName' => 'firebase-test'], $res, $opensslConfig);
        $x509 = openssl_csr_sign($csr, null, $res, 365, $opensslConfig);
        openssl_x509_export($x509, $certOut);

        $this->privateKeyPem = $privateKey;
        $this->certificatePem = $certOut;
    }

    private function createFirebaseToken(string $phoneNumber, ?int $iat = null, ?int $exp = null): string
    {
        $now = time();
        $header = ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $this->kid];
        $payload = [
            'iss' => 'https://securetoken.google.com/cardnest-test-project',
            'aud' => 'cardnest-test-project',
            'sub' => 'firebase-uid-123',
            'iat' => $iat ?? $now,
            'exp' => $exp ?? ($now + 300),
            'phone_number' => $phoneNumber,
        ];

        $segments = [
            $this->base64UrlEncode(json_encode($header)),
            $this->base64UrlEncode(json_encode($payload)),
        ];

        openssl_sign(implode('.', $segments), $signature, $this->privateKeyPem, OPENSSL_ALGO_SHA256);
        $segments[] = $this->base64UrlEncode($signature);

        return implode('.', $segments);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

