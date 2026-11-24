<?php

namespace App\Console\Commands;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use Illuminate\Console\Command;

class ExpireSubscriptions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'subscriptions:expire';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Expire lapsed trials and honour subscriptions cancelled at period end.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $now = now();

        // An explicit cancel takes effect when the period it was requested in
        // ends, so it is applied before plain expiry: otherwise the row would
        // be marked EXPIRED and lose the fact that the tenant cancelled.
        $cancelled = Subscription::query()
            ->where('cancel_at_period_end', true)
            ->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::Trialing->value])
            ->where(fn($query) => $query
                ->where(fn($inner) => $inner
                    ->whereNotNull('current_period_end')
                    ->where('current_period_end', '<=', $now))
                ->orWhere(fn($inner) => $inner
                    ->whereNotNull('trial_ends_at')
                    ->where('trial_ends_at', '<=', $now)))
            ->update([
                'status' => SubscriptionStatus::Cancelled->value,
                'cancelled_at' => $now,
            ]);

        // A trial that has run out is no longer effective.
        $expiredTrials = Subscription::query()
            ->where('status', SubscriptionStatus::Trialing->value)
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<=', $now)
            ->update(['status' => SubscriptionStatus::Expired->value]);

        // A paid period that has run out is no longer effective.
        $expiredPeriods = Subscription::query()
            ->where('status', SubscriptionStatus::Active->value)
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '<=', $now)
            ->update(['status' => SubscriptionStatus::Expired->value]);

        $this->info(sprintf(
            'Expired %d trial(s), %d paid period(s); cancelled %d at period end.',
            $expiredTrials,
            $expiredPeriods,
            $cancelled,
        ));

        return self::SUCCESS;
    }
}
