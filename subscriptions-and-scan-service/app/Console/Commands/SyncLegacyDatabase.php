<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

class SyncLegacyDatabase extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:sync-legacy {--full : Run a full historical sync instead of delta}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Syncs packages, whitelisted subscriptions, scan sessions, and scans from the legacy database';

    /**
     * Whitelist of merchant IDs to synchronize.
     *
     * @var array
     */
    protected $allowedMerchantIds = [
        '6O4584Y217268387',
        'G5536942984B2978',
        '93500624K6V95758',
        '4845925323992C4M',
        'mer000150'
    ];

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info("=== Starting Subscription & Scan Data Synchronization ===");
        $this->info("Target Merchants: " . implode(', ', $this->allowedMerchantIds));

        // Disable query logs to prevent memory leaks during massive operations
        DB::connection()->disableQueryLog();
        try {
            DB::connection('legacy')->disableQueryLog();
        } catch (\Exception $e) {
            // Ignore connection driver configuration issues for now
        }

        // Verify that the legacy connection is configured and accessible
        try {
            DB::connection('legacy')->getPdo();
            $this->info("Connected successfully to legacy database.");
        } catch (\Exception $e) {
            $this->error("Could not connect to the legacy database connection 'legacy'.");
            $this->error("Error: " . $e->getMessage());
            $this->error("Please configure the LEGACY_DB_* credentials in your .env file.");
            return 1;
        }

        // Initialize the log table if it doesn't exist
        $this->ensureSyncLogTableExists();

        // Sync steps (Sync packages first so foreign keys are resolved for subscriptions)
        $this->syncPackages();
        $this->syncSubscriptions();
        $this->syncScanSessions();
        $this->syncScans();

        $this->info("=== Data Synchronization Completed! ===");
        return 0;
    }

    /**
     * Create the sync log table inline if it is missing
     */
    private function ensureSyncLogTableExists()
    {
        if (!Schema::hasTable('migration_sync_log')) {
            $this->info("Creating migration_sync_log table...");
            Schema::create('migration_sync_log', function ($table) {
                $table->id();
                $table->string('table_name')->unique();
                $table->timestamp('last_synced_at')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Synchronize the packages table in chunks
     */
    private function syncPackages()
    {
        $this->info("Syncing packages...");
        
        $tableName = 'default_packages';
        if (!Schema::connection('legacy')->hasTable($tableName)) {
            $this->warn("Legacy table '{$tableName}' does not exist. Skipping packages sync.");
            return;
        }

        $lastSync = $this->getLastSyncTimestamp('packages');

        $query = DB::connection('legacy')
            ->table($tableName)
            ->where('updated_at', '>', $lastSync)
            ->orderBy('id', 'asc');

        $count = $query->count();
        $this->info("Found {$count} new or updated package(s) to sync.");

        $query->chunk(200, function ($legacyPackages) {
            foreach ($legacyPackages as $pkg) {
                DB::table('packages')->updateOrInsert(
                    ['id' => $pkg->id],
                    [
                        'package_name' => $pkg->package_name ?? null,
                        'package_price' => $pkg->package_price ?? null,
                        'package_period' => $pkg->package_period ?? null,
                        'package_description' => $pkg->package_description ?? null,
                        'monthly_limit' => $pkg->monthly_limit ?? null,
                        'overage_rate' => $pkg->overage_rate ?? null,
                        'created_at' => $pkg->created_at ?? Carbon::now(),
                        'updated_at' => $pkg->updated_at ?? Carbon::now(),
                    ]
                );
            }
        });

        $this->updateLastSyncTimestamp('packages');
    }

    /**
     * Synchronize the subscriptions table in chunks
     */
    private function syncSubscriptions()
    {
        $this->syncSubscriptionTable('subscriptions', 'active');
        $this->syncSubscriptionTable('old_subscriptions', 'in-active');
    }

    /**
     * Synchronize a specific legacy subscriptions table in chunks
     */
    private function syncSubscriptionTable($legacyTableName, $statusOverride = null)
    {
        $this->info("Syncing subscriptions from legacy '{$legacyTableName}' table...");
        
        if (!Schema::connection('legacy')->hasTable($legacyTableName)) {
            $this->warn("Legacy table '{$legacyTableName}' does not exist. Skipping.");
            return;
        }

        $lastSync = $this->getLastSyncTimestamp($legacyTableName);

        $query = DB::connection('legacy')
            ->table($legacyTableName)
            ->whereIn('merchant_id', $this->allowedMerchantIds)
            ->where('updated_at', '>', $lastSync)
            ->orderBy('id', 'asc');

        $count = $query->count();
        $this->info("Found {$count} record(s) to sync.");

        $query->chunk(200, function ($legacySubs) use ($statusOverride) {
            foreach ($legacySubs as $sub) {
                // Ensure referenced package exists to prevent foreign key violation
                $packageExists = DB::table('packages')->where('id', $sub->package_id)->exists();
                if (!$packageExists) {
                    $this->warn("Skipping subscription ID {$sub->id} because package_id {$sub->package_id} does not exist in target packages table.");
                    continue;
                }

                DB::table('subscriptions')->updateOrInsert(
                    ['id' => $sub->id],
                    [
                        'user_id' => $sub->user_id ?? null,
                        'merchant_id' => $sub->merchant_id ?? null,
                        'is_custom_renewal' => $sub->is_custom_renewal ?? 1,
                        'package_id' => $sub->package_id,
                        'api_call_limit' => $sub->api_call_limit ?? null,
                        'api_calls_used' => $sub->api_calls_used ?? null,
                        'overage_calls' => $sub->overage_calls ?? null,
                        'status' => $statusOverride ?? $sub->status ?? null,
                        'subscription_date' => $sub->subscription_date ?? null,
                        'renewal_date' => $sub->renewal_date ?? null,
                        'created_at' => $sub->created_at ?? Carbon::now(),
                        'updated_at' => $sub->updated_at ?? Carbon::now(),
                    ]
                );
            }
        });

        $this->updateLastSyncTimestamp($legacyTableName);
    }

    /**
     * Synchronize the scan_sessions table in chunks
     */
    private function syncScanSessions()
    {
        $this->info("Syncing scan sessions...");
        
        if (!Schema::connection('legacy')->hasTable('scan_sessions')) {
            $this->warn("Legacy table 'scan_sessions' does not exist. Skipping scan sessions sync.");
            return;
        }

        $lastSync = $this->getLastSyncTimestamp('scan_sessions');

        $query = DB::connection('legacy')
            ->table('scan_sessions')
            ->whereIn('merchant_id', $this->allowedMerchantIds)
            ->where('updated_at', '>', $lastSync)
            ->orderBy('id', 'asc');

        $count = $query->count();
        $this->info("Found {$count} new or updated scan session(s) to sync.");

        $query->chunk(200, function ($legacySessions) {
            foreach ($legacySessions as $session) {
                DB::table('scan_sessions')->updateOrInsert(
                    ['id' => $session->id],
                    [
                        'scan_id' => $session->scan_id ?? null,
                        'merchant_id' => $session->merchant_id ?? null,
                        'device_type' => $session->device_type ?? null,
                        'tries' => $session->tries ?? null,
                        'encryption_key' => $session->encryption_key ?? null,
                        'encrypted_data' => $session->encrypted_data ?? null,
                        'scanned_at' => $session->scanned_at ?? null,
                        'created_at' => $session->created_at ?? Carbon::now(),
                        'updated_at' => $session->updated_at ?? Carbon::now(),
                    ]
                );
            }
        });

        $this->updateLastSyncTimestamp('scan_sessions');
    }

    /**
     * Synchronize the scans table in chunks with legacy name fallbacks
     */
    private function syncScans()
    {
        $this->info("Syncing scans...");
        
        $tableName = null;
        if (Schema::connection('legacy')->hasTable('card_scans')) {
            $tableName = 'card_scans';
        }

        if (!$tableName) {
            $this->warn("Legacy scans table does not exist under name: 'card_scans'.");
            try {
                $tables = DB::connection('legacy')->select('SHOW TABLES');
                $this->info("Legacy database tables found:");
                foreach ($tables as $table) {
                    $tableNameVal = array_values((array)$table)[0];
                    $this->line("- " . $tableNameVal);
                }
            } catch (\Exception $e) {
                $this->error("Could not list legacy tables: " . $e->getMessage());
            }
            $this->warn("Skipping scans sync.");
            return;
        }

        $this->info("Using legacy scans table: '{$tableName}'");
        $lastSync = $this->getLastSyncTimestamp('scans');

        $query = DB::connection('legacy')
            ->table($tableName)
            ->whereIn('merchant_id', $this->allowedMerchantIds)
            ->where('updated_at', '>', $lastSync)
            ->orderBy('id', 'asc');

        $count = $query->count();
        $this->info("Found {$count} new or updated scan(s) to sync.");

        $query->chunk(200, function ($legacyScans) {
            foreach ($legacyScans as $scan) {
                DB::table('scans')->updateOrInsert(
                    ['id' => $scan->id],
                    [
                        'user_id' => $scan->user_id ?? null,
                        'merchant_id' => $scan->merchant_id ?? null,
                        'merchant_key' => $scan->merchant_key ?? null,
                        'card_number_masked' => $scan->card_number_masked ?? null,
                        'status' => $scan->status ?? null,
                        'encrypted_data' => $scan->encrypted_data ?? null,
                        'scan_id' => $scan->scan_id ?? null,
                        'session_id' => $scan->session_id ?? null,
                        'failure_reason' => $scan->failure_reason ?? null,
                        'failure_stage' => $scan->failure_stage ?? null,
                        'created_at' => $scan->created_at ?? Carbon::now(),
                        'updated_at' => $scan->updated_at ?? Carbon::now(),
                    ]
                );
            }
        });

        $this->updateLastSyncTimestamp('scans');
    }

    /**
     * Retrieve the last sync timestamp for a table
     */
    private function getLastSyncTimestamp($table)
    {
        if ($this->option('full')) {
            return '1970-01-01 00:00:00';
        }

        $record = DB::table('migration_sync_log')->where('table_name', $table)->first();
        return $record ? $record->last_synced_at : '1970-01-01 00:00:00';
    }

    /**
     * Update the last sync timestamp for a table
     */
    private function updateLastSyncTimestamp($table)
    {
        DB::table('migration_sync_log')->updateOrInsert(
            ['table_name' => $table],
            ['last_synced_at' => Carbon::now()->toDateTimeString()]
        );
    }
}
