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
    protected $description = 'Syncs users, locations, and split business profiles from the legacy database';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info("=== Starting Data Synchronization ===");

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

        // Sync steps
        $this->syncUsers();
        $this->syncBusinessProfiles();
        $this->syncLocations();

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
     * Synchronize the users table in chunks
     */
    private function syncUsers()
    {
        $this->info("Syncing users...");
        $lastSync = $this->getLastSyncTimestamp('users');

        $query = DB::connection('legacy')
            ->table('users')
            ->where('updated_at', '>', $lastSync)
            ->orderBy('id', 'asc');

        $count = $query->count();
        $this->info("Found {$count} new or updated user(s) to sync.");

        $query->chunk(200, function ($legacyUsers) {
            foreach ($legacyUsers as $user) {
                // Check for renaming of phone_no to phone_number
                $phoneNumber = $user->phone_number ?? $user->phone_no ?? null;

                DB::table('users')->updateOrInsert(
                    ['id' => $user->id],
                    [
                        'parent_id' => $user->parent_id ?? null,
                        'merchant_id' => $user->merchant_id ?? null,
                        'aes_key' => $user->aes_key ?? null,
                        'service_type' => $user->service_type ?? 'card_scan',
                        'email' => $user->email,
                        'country_code' => $user->country_code ?? null,
                        'phone_number' => $phoneNumber,
                        'country_name' => $user->country_name ?? null,
                        'otp_verified' => $user->otp_verified ?? 0,
                        'business_verified' => $user->business_verified ?? null,
                        'verification_reason' => $user->verification_reason ?? null,
                        'on_trial' => $user->on_trial ?? 1,
                        'trial_calls_remaining' => $user->trial_calls_remaining ?? null,
                        'trial_ends_at' => $user->trial_ends_at ?? null,
                        'role' => $user->role ?? null,
                        'device_id' => $user->device_id ?? null,
                        'session_id' => $user->session_id ?? null,
                        'device_timestamp' => $user->device_timestamp ?? null,
                        'device' => $user->device ?? null,
                        'network' => $user->network ?? null,
                        'sims' => $user->sims ?? null,
                        'location' => $user->location ?? null,
                        'created_at' => $user->created_at ?? Carbon::now(),
                        'updated_at' => $user->updated_at ?? Carbon::now(),
                    ]
                );
            }
        });

        $this->updateLastSyncTimestamp('users');
    }

    /**
     * Synchronize the business profiles & extract account holders in chunks
     */
    private function syncBusinessProfiles()
    {
        $this->info("Syncing business profiles and account holders...");
        $lastSync = $this->getLastSyncTimestamp('business_profiles');

        $query = DB::connection('legacy')
            ->table('business_profiles')
            ->where('updated_at', '>', $lastSync)
            ->orderBy('id', 'asc');

        $count = $query->count();
        $this->info("Found {$count} new or updated business profile(s) to sync.");

        $query->chunk(200, function ($legacyProfiles) {
            foreach ($legacyProfiles as $legacy) {
                DB::transaction(function () use ($legacy) {
                    // A. Prepare Account Holder Data with schema-robust fallbacks
                    $accountHolderData = [
                        'first_name' => $legacy->account_holder_first_name ?? $legacy->first_name ?? $legacy->name ?? '',
                        'last_name' => $legacy->account_holder_last_name ?? $legacy->last_name ?? '',
                        'email' => $legacy->account_holder_email ?? $legacy->email ?? '',
                        'date_of_birth' => $legacy->account_holder_dob ?? $legacy->date_of_birth ?? $legacy->dob ?? '',
                        'street' => $legacy->account_holder_street ?? $legacy->street ?? '',
                        'street_line2' => $legacy->account_holder_street2 ?? $legacy->street_line2 ?? $legacy->street2 ?? null,
                        'city' => $legacy->account_holder_city ?? $legacy->city ?? '',
                        'state' => $legacy->account_holder_state ?? $legacy->state ?? '',
                        'zip_code' => $legacy->account_holder_zip ?? $legacy->zip_code ?? $legacy->zip ?? '',
                        'country' => $legacy->account_holder_country ?? $legacy->country ?? '',
                        'id_type' => $legacy->account_holder_id_type ?? $legacy->id_type ?? $legacy->kyc_type ?? null,
                        'id_number' => $legacy->account_holder_id_number ?? $legacy->id_number ?? $legacy->kyc_number ?? null,
                        'id_document_path' => $legacy->account_holder_id_document ?? $legacy->id_document_path ?? $legacy->kyc_document ?? null,
                        'updated_at' => $legacy->updated_at ?? Carbon::now(),
                    ];

                    // B. Find or create Account Holder
                    $existingProfile = DB::table('business_profiles')->where('id', $legacy->id)->first();

                    if ($existingProfile) {
                        // Update existing account holder
                        DB::table('account_holders')
                            ->where('id', $existingProfile->account_holder_id)
                            ->update($accountHolderData);
                        
                        $accountHolderId = $existingProfile->account_holder_id;
                    } else {
                        // Create new account holder
                        $accountHolderData['created_at'] = $legacy->created_at ?? Carbon::now();
                        $accountHolderId = DB::table('account_holders')->insertGetId($accountHolderData);
                    }

                    // C. Save/Update Business Profile referencing the Account Holder
                    DB::table('business_profiles')->updateOrInsert(
                        ['id' => $legacy->id],
                        [
                            'user_id' => $legacy->user_id,
                            'service_type' => $legacy->service_type ?? 'card_scan',
                            'account_holder_id' => $accountHolderId,
                            'email' => $legacy->business_email ?? $legacy->email ?? null,
                            'business_name' => $legacy->business_name ?? null,
                            'business_registration_number' => $legacy->business_registration_number ?? $legacy->registration_number ?? null,
                            'street' => $legacy->business_street ?? $legacy->street ?? null,
                            'street_line2' => $legacy->business_street2 ?? $legacy->street_line2 ?? null,
                            'city' => $legacy->business_city ?? $legacy->city ?? null,
                            'state' => $legacy->business_state ?? $legacy->state ?? null,
                            'zip_code' => $legacy->business_zip ?? $legacy->zip_code ?? null,
                            'country' => $legacy->business_country ?? $legacy->country ?? null,
                            'registration_document_path' => $legacy->registration_document_path ?? $legacy->business_document ?? null,
                            'display_name' => $legacy->display_name ?? null,
                            'display_logo' => $legacy->display_logo ?? null,
                            'created_at' => $legacy->created_at ?? Carbon::now(),
                            'updated_at' => $legacy->updated_at ?? Carbon::now(),
                        ]
                    );
                });
            }
        });

        $this->updateLastSyncTimestamp('business_profiles');
    }

    /**
     * Synchronize the locations table in chunks
     */
    private function syncLocations()
    {
        $this->info("Syncing locations...");
        
        // Double check if legacy locations table exists first
        if (!Schema::connection('legacy')->hasTable('locations')) {
            $this->warn("Legacy table 'locations' does not exist. Skipping locations sync.");
            return;
        }

        $lastSync = $this->getLastSyncTimestamp('locations');

        $query = DB::connection('legacy')
            ->table('locations')
            ->where('updated_at', '>', $lastSync)
            ->orderBy('id', 'asc');

        $count = $query->count();
        $this->info("Found {$count} new or updated location(s) to sync.");

        $query->chunk(200, function ($legacyLocations) {
            foreach ($legacyLocations as $loc) {
                // Check for lat/lon variations
                $lat = $loc->lat ?? $loc->latitude ?? null;
                $lon = $loc->lon ?? $loc->lng ?? $loc->longitude ?? null;

                // Make sure referencing user exists to avoid foreign key violations
                $userExists = DB::table('users')->where('id', $loc->user_id)->exists();
                if ($loc->user_id && !$userExists) {
                    $this->warn("Skipping location ID {$loc->id} because user_id {$loc->user_id} does not exist in the new database.");
                    continue;
                }

                DB::table('locations')->updateOrInsert(
                    ['id' => $loc->id],
                    [
                        'user_id' => $loc->user_id ?? null,
                        'merchant_id' => $loc->merchant_id ?? null,
                        'device_id' => $loc->device_id ?? null,
                        'lat' => $lat,
                        'lon' => $lon,
                        'address' => $loc->address ?? null,
                        'postal_code' => $loc->postal_code ?? null,
                        'raw_response' => $loc->raw_response ?? null,
                        'created_at' => $loc->created_at ?? Carbon::now(),
                        'updated_at' => $loc->updated_at ?? Carbon::now(),
                    ]
                );
            }
        });

        $this->updateLastSyncTimestamp('locations');
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
