<?php

namespace App\Http\Controllers\Api\Tenant\Queue;

use App\Http\Controllers\Controller;
use App\Models\QueueTicket;
use App\Services\QueueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QueueController extends Controller
{
    public function __construct(
        public QueueService $queueService
    ) {}

    /**
     * Call next queue ticket for a station.
     */
    public function callNext(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'station_id' => ['required', 'integer', $this->stationRule()],
        ]);

        $ticket = $this->queueService->callNext(
            tenant: $this->tenant(),
            stationId: (int) $validated['station_id'],
            userId: $this->actor($request),
        );

        if (! $ticket) {
            return response()->json([
                'message' => 'No eligible tickets in queue for this station.',
                'data' => null,
            ]);
        }

        return response()->json([
            'message' => "Ticket {$ticket->queue_number} called.",
            'data' => $ticket,
        ]);
    }

    /**
     * Recall a ticket announcement.
     */
    public function recall(Request $request, int $id): JsonResponse
    {
        $ticket = $this->findTicket($id);
        $userId = $this->actor($request);

        $result = $this->queueService->recall($ticket, $userId);

        return response()->json([
            'message' => "Ticket {$result->queue_number} recalled for station.",
            'data' => $result,
        ]);
    }

    /**
     * Start service for a ticket.
     */
    public function start(Request $request, int $id): JsonResponse
    {
        $ticket = $this->findTicket($id);
        $userId = $this->actor($request);

        $result = $this->queueService->start($ticket, $userId);

        return response()->json([
            'message' => "Service started for ticket {$result->queue_number}. QueueSession is now in progress.",
            'data' => $result,
        ]);
    }

    /**
     * Hold a ticket.
     */
    public function hold(Request $request, int $id): JsonResponse
    {
        $ticket = $this->findTicket($id);
        $notes = $request->input('notes');
        $userId = $this->actor($request);

        $result = $this->queueService->hold($ticket, $notes, $userId);

        return response()->json([
            'message' => "Ticket {$result->queue_number} placed on hold.",
            'data' => $result,
        ]);
    }

    /**
     * Resume a ticket from hold back to CALLED.
     */
    public function resume(Request $request, int $id): JsonResponse
    {
        $ticket = $this->findTicket($id);
        $userId = $this->actor($request);

        $result = $this->queueService->resume($ticket, $userId);

        return response()->json([
            'message' => "Ticket {$result->queue_number} resumed to CALLED status.",
            'data' => $result,
        ]);
    }

    /**
     * Complete a ticket and advance the workflow.
     */
    public function complete(Request $request, int $id): JsonResponse
    {
        $ticket = $this->findTicket($id);
        $notes = $request->input('notes');
        $userId = $this->actor($request);

        $result = $this->queueService->complete($ticket, $notes, $userId);

        return response()->json([
            'message' => "Ticket {$ticket->queue_number} completed.",
            'data' => $result,
        ]);
    }

    /**
     * Skip a ticket.
     */
    public function skip(Request $request, int $id): JsonResponse
    {
        $ticket = $this->findTicket($id);
        $notes = $request->input('notes');
        $userId = $this->actor($request);

        $result = $this->queueService->skip($ticket, $notes, $userId);

        return response()->json([
            'message' => "Ticket {$result->queue_number} marked as skipped.",
            'data' => $result,
        ]);
    }

    /**
     * Mark a ticket as No-Show.
     */
    public function noShow(Request $request, int $id): JsonResponse
    {
        $ticket = $this->findTicket($id);
        $notes = $request->input('notes');
        $userId = $this->actor($request);

        $result = $this->queueService->noShow($ticket, $notes, $userId);

        return response()->json([
            'message' => "Ticket {$result->queue_number} marked as no-show.",
            'data' => $result,
        ]);
    }

    /**
     * Cancel a ticket.
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $ticket = $this->findTicket($id);
        $notes = $request->input('notes');
        $userId = $this->actor($request);

        $result = $this->queueService->cancelTicket($ticket, $notes, $userId);

        return response()->json([
            'message' => "Ticket {$result->queue_number} cancelled.",
            'data' => $result,
        ]);
    }

    /**
     * Transfer a ticket to another compatible station.
     */
    public function transfer(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'target_station_id' => ['required', 'integer', $this->stationRule()],
            'notes' => ['nullable', 'string'],
        ]);

        $ticket = $this->findTicket($id);

        $result = $this->queueService->transfer(
            tenant: $this->tenant(),
            ticket: $ticket,
            targetStationId: (int) $validated['target_station_id'],
            notes: $validated['notes'] ?? null,
            userId: $this->actor($request),
        );

        return response()->json([
            'message' => "Ticket {$ticket->queue_number} transferred successfully to new station.",
            'data' => $result,
        ]);
    }

    /**
     * Resolve a ticket that belongs to the current tenant.
     */
    private function findTicket(int $id): QueueTicket
    {
        return QueueTicket::query()
            ->whereHas('queueSession', fn($q) => $q->where('tenant_id', $this->tenantId()))
            ->findOrFail($id);
    }
}
