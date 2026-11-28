<?php

namespace App\Console\Commands;

use App\Enums\Feature;
use App\Models\PlanEntitlement;
use App\Models\Subscription;
use Illuminate\Console\Command;

/**
 * Backfill subscription entitlement snapshots from the plan catalogue.
 *
 * Runs once at the migration, then idempotently: it copies each subscription's
 * plan entitlements onto `subscription_entitlements` for any feature it does not
 * already have. The old aggregate report codes are mapped to the individual
 * report codes that actually worked, so existing subscribers keep their rights
 * without silently gaining new report modules.
 */
class BackfillSubscriptionEntitlements extends Command
{
    /**
     * @var string
     */
    protected $signature = 'billing:backfill-entitlements {--dry-run : Report what would change without writing}';

    /**
     * @var string
     */
    protected $description = 'Snapshot plan entitlements onto existing subscriptions.';

    /**
     * Legacy aggregate code -> the report codes it really granted.
     *
     * `basic_reports` only ever backed the summary endpoint, and
     * `advanced_reports` added the export. No other report module was reachable,
     * so no other individual code is granted here.
     *
     * @var array<string, list<Feature>>
     */
    private array $mapping = [
        'basic_reports' => [Feature::ReportQueueOverview],
        'advanced_reports' => [Feature::ReportQueueOverview, Feature::ReportExport],
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $written = 0;

        Subscription::query()->with('plan.entitlements')->orderBy('id')->each(function (Subscription $subscription) use ($dryRun, &$written): void {
            foreach ($subscription->plan->entitlements as $entitlement) {
                foreach ($this->expand($entitlement) as $feature) {
                    $exists = $subscription->entitlements()->where('feature_code', $feature->value)->exists();

                    if ($exists) {
                        continue;
                    }

                    $written++;

                    if ($dryRun) {
                        continue;
                    }

                    $subscription->entitlements()->create([
                        'feature_code' => $feature->value,
                        'value' => $this->valueFor($entitlement, $feature),
                    ]);
                }
            }
        });

        $this->info($dryRun
            ? "Would backfill {$written} entitlement row(s)."
            : "Backfilled {$written} entitlement row(s).");

        return self::SUCCESS;
    }

    /**
     * The individual feature codes a catalogue row represents.
     *
     * @return list<Feature>
     */
    private function expand(PlanEntitlement $entitlement): array
    {
        if (isset($this->mapping[$entitlement->feature_code])) {
            return $this->mapping[$entitlement->feature_code];
        }

        $feature = Feature::tryFrom($entitlement->feature_code);

        return $feature === null ? [] : [$feature];
    }

    /**
     * The snapshot value for a mapped feature.
     *
     * Aggregates were toggles, so their mapped report codes are toggles too
     * (null value). A capacity/limit row keeps its original value.
     */
    private function valueFor(PlanEntitlement $entitlement, Feature $feature): mixed
    {
        if (isset($this->mapping[$entitlement->feature_code])) {
            return null;
        }

        return $entitlement->value;
    }
}
