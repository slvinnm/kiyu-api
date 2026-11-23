<?php

namespace App\Jobs;

use App\Models\QueueTicket;
use App\Models\Tenant;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Notify a client over WhatsApp that their queue number is being called.
 *
 * Queued so a slow third-party API never blocks the operator's call-next.
 * The job rehydrates the tenant and is safely skipped when WhatsApp is
 * disabled, the plan does not grant it, or there is no phone number.
 */
class SendQueueCallWhatsApp implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $tenantId,
        public string $phone,
        public string $message,
    ) {}

    /**
     * Queue a call notification for a ticket, when it has a reachable client.
     *
     * Returns without dispatching when the visit has no client or phone, so
     * callers never have to branch on it.
     */
    public static function forTicket(QueueTicket $ticket): void
    {
        $visit = $ticket->visit;

        if ($visit === null || $visit->tenant_id === null) {
            return;
        }

        $phone = (string) ($visit->client?->phone ?? '');
        $station = $ticket->station?->name ?? 'the counter';

        if ($phone === '') {
            return;
        }

        $message = sprintf(
            'Your queue number %s is being called at %s. Please proceed to the counter.',
            $ticket->queue_number,
            $station,
        );

        self::dispatch((int) $visit->tenant_id, $phone, $message);
    }

    /**
     * Execute the job.
     */
    public function handle(WhatsAppService $whatsapp): void
    {
        if ($this->phone === '') {
            return;
        }

        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null) {
            return;
        }

        $whatsapp->sendText($tenant, $this->phone, $this->message);
    }
}
