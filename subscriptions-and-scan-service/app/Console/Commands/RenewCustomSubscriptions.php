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

        $subscriptions = $query->get();

        $this->info('Starting custom subscription renewal for ' . $subscriptions->count() . ' subscriptions.');

        foreach ($subscriptions as $existing) {
            DB::beginTransaction();
            try {
                // Mark the old subscription as expired
                $existing->status = 'expired';
                $existing->save();

                // Calculate renewal logic (carry over unused calls & adjust for overages)
                $baseLimit = $existing->api_call_limit ?? 1000;
                $oldLimit = $existing->api_call_limit ?? 0;
                $oldUsed = $existing->api_calls_used ?? 0;

                $pending = max(0, $oldLimit - $oldUsed);
                $overage = max(0, $oldUsed - $oldLimit);
                $newLimit = max(0, $baseLimit + $pending - $overage);

                $subscriptionDate = Carbon::now();
                $renewalDate = $subscriptionDate->copy()->addMonth();

                // Create the NEW active subscription record for the new cycle
                $newSubscription = new Subscription();
                $newSubscription->merchant_id = $existing->merchant_id;
                $newSubscription->user_id = $existing->user_id;
                $newSubscription->package_id = $existing->package_id ?? 1;
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
            }
        }

        $this->info('Custom subscription renewal process completed.');
    }
}
