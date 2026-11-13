<?php

namespace Tests\Feature;

use App\Http\Controllers\GatewayController;
use App\Models\Users;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class MerchantIamGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'jwt.secret' => str_repeat('b', 64),
            'services.internal.token' => 'test-internal-token',
        ]);

        Schema::dropIfExists('users');
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('parent_id')->nullable();
            $table->string('merchant_id')->nullable();
            $table->string('aes_key')->nullable();
            $table->string('service_type')->default('card_scan')->nullable();
            $table->string('email')->unique()->nullable();
            $table->string('country_code')->nullable();
            $table->string('phone_number')->nullable();
            $table->string('country_name')->nullable();
            $table->boolean('otp_verified')->default(false)->nullable();
            $table->string('business_verified')->nullable();
            $table->string('verification_reason')->nullable();
            $table->boolean('on_trial')->default(true)->nullable();
            $table->integer('trial_calls_remaining')->nullable();
            $table->date('trial_ends_at')->nullable();
            $table->string('role')->nullable();
            $table->timestamps();
        });

        $migration = require database_path('migrations/2026_11_12_000001_add_merchant_iam_columns_to_users_table.php');
        $migration->up();
    }

    public function test_custom_sub_admin_is_enforced_by_gateway_permission_middleware(): void
    {
        $primary = Users::create([
            'merchant_id' => 'GW_MERCHANT_001',
            'email' => 'owner@gw.com',
            'country_code' => '+1',
            'phone_number' => '2025550111',
            'business_verified' => 'APPROVED',
            'role' => 'BUSINESS_USER',
            'is_additional_admin' => false,
            'access_type' => 'full',
            'admin_status' => 'active',
        ]);

        $scanOnlyAdmin = Users::create([
            'parent_merchant_id' => $primary->id,
            'is_additional_admin' => true,
            'access_type' => 'custom',
            'permissions' => ['dashboard.scans'],
            'admin_status' => 'active',
            'email' => 'scanner@gw.com',
            'country_code' => '+1',
            'phone_number' => '2025550112',
            'role' => 'BUSINESS_USER',
        ]);

        $token = JWTAuth::fromUser($scanOnlyAdmin);

        // 1. /api/auth/user returns effective merchant_id and IAM attributes
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/auth/user')
            ->assertStatus(200)
            ->assertJsonPath('data.merchant_id', 'GW_MERCHANT_001')
            ->assertJsonPath('data.is_additional_admin', true)
            ->assertJsonPath('data.parent_merchant_id', $primary->id)
            ->assertJsonPath('data.permissions', ['dashboard.scans'])
            ->assertJsonPath('data.business_verified', 'APPROVED');

        // 2. Denied routes return 403 Forbidden before hitting downstream services
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/merchant/admins')
            ->assertStatus(403);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/Subscriptions/GetByUserIDorMerchantID')
            ->assertStatus(403);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/merchant/card-preferences')
            ->assertStatus(403);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/feature/store')
            ->assertStatus(403);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/superadmin/show')
            ->assertStatus(403);

        // 3. Route precedence ensures /api/merchant/admins goes to Auth Service and /api/merchant/getCardScans goes to Subscriptions Service
        $matchedRoute = Route::getRoutes()->match(
            \Illuminate\Http\Request::create('/api/merchant/admins', 'GET')
        );
        $this->assertSame(
            '\\' . GatewayController::class . '@proxyToAuthService',
            $matchedRoute->getActionName()
        );

        $scansRoute = Route::getRoutes()->match(
            \Illuminate\Http\Request::create('/api/merchant/getCardScans', 'GET')
        );
        $this->assertSame(
            '\\' . GatewayController::class . '@proxyToSubscriptionsService',
            $scansRoute->getActionName()
        );
    }

    public function test_inactive_or_revoked_sub_admin_jwt_is_rejected_immediately(): void
    {
        $primary = Users::create([
            'merchant_id' => 'GW_MERCHANT_002',
            'email' => 'owner2@gw.com',
            'country_code' => '+1',
            'phone_number' => '2025550121',
            'role' => 'BUSINESS_USER',
            'is_additional_admin' => false,
            'access_type' => 'full',
            'admin_status' => 'active',
        ]);

        $subAdmin = Users::create([
            'parent_merchant_id' => $primary->id,
            'is_additional_admin' => true,
            'access_type' => 'full',
            'permissions' => [],
            'admin_status' => 'active',
            'email' => 'fullsub@gw.com',
            'country_code' => '+1',
            'phone_number' => '2025550122',
            'role' => 'BUSINESS_USER',
        ]);

        $token = JWTAuth::fromUser($subAdmin);

        // Active full-access sub-admin succeeds on /api/auth/user and is still blocked from superadmin
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/auth/user')
            ->assertStatus(200)
            ->assertJsonCount(13, 'data.permissions');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/superadmin/show')
            ->assertStatus(403);

        // Mark inactive -> immediate 403
        $subAdmin->update([
            'admin_status' => 'inactive',
            'tokens_revoked_at' => now(),
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/auth/user')
            ->assertStatus(403);

        // Even if re-activated, the old token issued before tokens_revoked_at remains revoked (401)
        $subAdmin->update([
            'admin_status' => 'active',
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/auth/user')
            ->assertStatus(401);
    }

    public function test_gateway_forwards_effective_tenant_and_actor_headers_to_microservices(): void
    {
        $primary = Users::create([
            'merchant_id' => 'GW_MERCHANT_003',
            'email' => 'owner3@gw.com',
            'country_code' => '+1',
            'phone_number' => '2025550131',
            'role' => 'BUSINESS_USER',
            'is_additional_admin' => false,
            'access_type' => 'full',
            'admin_status' => 'active',
        ]);

        $subAdmin = Users::create([
            'parent_merchant_id' => $primary->id,
            'is_additional_admin' => true,
            'access_type' => 'custom',
            'permissions' => ['dashboard.admin_management'],
            'admin_status' => 'active',
            'email' => 'iam3@gw.com',
            'country_code' => '+1',
            'phone_number' => '2025550132',
            'role' => 'BUSINESS_USER',
        ]);

        $history = [];
        $mock = new MockHandler([
            new GuzzleResponse(200, ['Content-Type' => 'application/json'], json_encode(['status' => true, 'data' => []])),
        ]);
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push(Middleware::history($history));
        $mockClient = new Client(['handler' => $handlerStack]);

        $this->app->instance(GatewayController::class, new GatewayController($mockClient));

        $token = JWTAuth::fromUser($subAdmin);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/merchant/admins')
            ->assertStatus(200)
            ->assertJsonPath('status', true);

        $this->assertCount(1, $history);
        $forwardedRequest = $history[0]['request'];
        $this->assertSame((string) $primary->id, $forwardedRequest->getHeaderLine('X-User-ID'));
        $this->assertSame((string) $subAdmin->id, $forwardedRequest->getHeaderLine('X-Actor-User-ID'));
        $this->assertSame('GW_MERCHANT_003', $forwardedRequest->getHeaderLine('X-Merchant-ID'));
        $this->assertSame('true', $forwardedRequest->getHeaderLine('X-Is-Additional-Admin'));
        $this->assertSame('test-internal-token', $forwardedRequest->getHeaderLine('X-Internal-Service-Token'));
    }
}
