<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Subscription;
use App\Models\Package;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class RenewCustomSubscriptions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'subscriptions:renew-custom {merchant_id?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Monthly renewal of custom subscriptions for selected merchants';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $merchantIdParam = $this->argument('merchant_id');

        // Fetch subscriptions that have been explicitly marked for custom renewal
        // and are currently active.
        $query = Subscription::where('status', 'active')
            ->where('is_custom_renewal', 1);

        if ($merchantIdParam) {
            $query->where('merchant_id', $merchantIdParam);
        } else {
            // Otherwise, filter by renewal date reached or passed
            $query->whereDate('renewal_date', '<=', now()->toDateString());
        }

        $subscriptions = $query->get()->unique('merchant_id');

        $this->info('Starting custom subscription renewal for ' . $subscriptions->count() . ' unique merchants.');

        foreach ($subscriptions as $existing) {
            if (!$existing->merchant_id) {
                continue;
            }

            DB::beginTransaction();
            try {
                // Lock and fetch all active subscriptions for this merchant to prevent concurrent writes
                $activeSubs = Subscription::where('merchant_id', $existing->merchant_id)
                    ->where('status', 'active')
                    ->lockForUpdate()
                    ->get();

                // Find the absolute latest subscription overall for this merchant
                $latestSub = Subscription::where('merchant_id', $existing->merchant_id)
                    ->latest()
                    ->first();

                // If the latest subscription is active and the renewal date is in the future,
                // it has already been renewed. Clean up any duplicates and skip.
                if ($latestSub && $latestSub->status === 'active' && Carbon::parse($latestSub->renewal_date)->isFuture()) {
                    foreach ($activeSubs as $sub) {
                        if ($sub->id !== $latestSub->id) {
                            $sub->status = 'expired';
                            $sub->save();
                        }
                    }
                    DB::commit();
                    continue;
                }

                // Mark all existing active subscriptions for this merchant as expired
                foreach ($activeSubs as $sub) {
                    $sub->status = 'expired';
                    $sub->save();
                }

                // Only the api_calls_limit is renewed and previous counts are not added
                $baseLimit = $existing->api_call_limit ?? 1000;
                $newLimit = $baseLimit;

                $subscriptionDate = Carbon::now();
                $renewalDate = $subscriptionDate->copy()->addMonth();

                // Ensure the package exists in the database to prevent foreign key constraint failure
                $packageId = $existing->package_id ?? 1;
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

                // Create the NEW active subscription record for the new cycle
                $newSubscription = new Subscription();
                $newSubscription->merchant_id = $existing->merchant_id;
                $newSubscription->user_id = $existing->user_id;
                $newSubscription->package_id = $packageId;
                $newSubscription->status = 'active';
                $newSubscription->api_call_limit = $newLimit;
                $newSubscription->api_calls_used = 0;
                $newSubscription->overage_calls = 0;
                $newSubscription->subscription_date = $subscriptionDate;
                $newSubscription->renewal_date = $renewalDate;
                $newSubscription->is_custom_renewal = 1;
                $newSubscription->save();

                DB::commit();
                $this->info("Successfully renewed subscription for merchant: {$existing->merchant_id}");

            } catch (\Exception $e) {
                DB::rollBack();
                $this->error("Failed to renew for merchant {$existing->merchant_id}: " . $e->getMessage());
                \Illuminate\Support\Facades\Log::error("Failed to renew custom subscription for merchant {$existing->merchant_id}: " . $e->getMessage());
            }
        }

        $this->info('Custom subscription renewal process completed.');
        \Illuminate\Support\Facades\Log::info('Custom subscription renewal process completed.');
    }
}
