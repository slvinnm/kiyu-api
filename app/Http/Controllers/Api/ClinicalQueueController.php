<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\QueueTicket;
use App\Services\ClinicalQueueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClinicalQueueController extends Controller
{
    public function __construct(
        public ClinicalQueueService $clinicalQueueService
    ) {}

    /**
     * Call next queue ticket for a clinical station.
     */
    public function callNext(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'station_id' => ['required', 'integer', 'exists:stations,id'],
        ]);

        $ticket = $this->clinicalQueueService->callNext(
            tenant: $this->tenant(),
            stationId: (int) $validated['station_id'],
            assignedTo: $this->actor($request),
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

        $result = $this->clinicalQueueService->recall($ticket, $userId);

        return response()->json([
            'message' => "Ticket {$result->queue_number} recalled for clinical station.",
            'data' => $result,
        ]);
    }

    /**
     * Start clinical service for a ticket.
     */
    public function start(Request $request, int $id): JsonResponse
    {
        $ticket = $this->findTicket($id);
        $userId = $this->actor($request);

        $result = $this->clinicalQueueService->start($ticket, $userId);

        return response()->json([
            'message' => "Service started for ticket {$result->queue_number}. Visit is now in progress.",
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

        $result = $this->clinicalQueueService->hold($ticket, $notes, $userId);

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

        $result = $this->clinicalQueueService->resume($ticket, $userId);

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

        $result = $this->clinicalQueueService->complete($ticket, $notes, $userId);

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

        $result = $this->clinicalQueueService->skip($ticket, $notes, $userId);

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

        $result = $this->clinicalQueueService->noShow($ticket, $notes, $userId);

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

        $result = $this->clinicalQueueService->cancelTicket($ticket, $notes, $userId);

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
            'target_station_id' => ['required', 'integer', 'exists:stations,id'],
            'notes' => ['nullable', 'string'],
        ]);

        $ticket = $this->findTicket($id);

        $result = $this->clinicalQueueService->transfer(
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
            ->whereHas('visit', fn($q) => $q->where('tenant_id', $this->tenantId()))
            ->findOrFail($id);
    }
}
