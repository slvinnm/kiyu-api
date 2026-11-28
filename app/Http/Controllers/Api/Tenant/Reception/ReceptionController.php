<?php

namespace App\Http\Controllers\Api\Tenant\Reception;

use App\Http\Controllers\Controller;
use App\Services\ReceptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReceptionController extends Controller
{
    public function __construct(
        public ReceptionService $receptionService
    ) {}

    /**
     * Get the current reception queue state for a department/station.
     */
    public function queue(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'department_id' => ['required', 'integer', $this->departmentRule()],
            'station_id' => ['nullable', 'integer', $this->stationRule()],
        ]);

        $queue = $this->receptionService->getReceptionQueue(
            tenant: $this->tenant(),
            departmentId: (int) $validated['department_id'],
            stationId: isset($validated['station_id']) ? (int) $validated['station_id'] : null,
            actor: $request->user()?->id
        );

        return response()->json([
            'message' => 'Reception queue retrieved.',
            'data' => $queue,
        ]);
    }

    /**
     * Call next queue ticket for reception at the resolved registration station.
     */
    public function callNext(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'department_id' => ['required', 'integer', $this->departmentRule()],
            'station_id' => ['nullable', 'integer', $this->stationRule()],
        ]);

        $ticket = $this->receptionService->callNext(
            tenant: $this->tenant(),
            departmentId: (int) $validated['department_id'],
            userId: $this->actor($request),
            stationId: isset($validated['station_id']) ? (int) $validated['station_id'] : null
        );

        if (! $ticket) {
            return response()->json([
                'message' => 'No eligible tickets in reception queue.',
                'data' => null,
            ]);
        }

        return response()->json([
            'message' => "Ticket {$ticket->queue_number} called for reception.",
            'data' => $ticket,
        ]);
    }

    /**
     * Complete client registration.
     */
    public function completeRegistration(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'acquisition_id' => ['required', 'integer', 'exists:queue_acquisitions,id'],
            'customer_id' => ['nullable', 'integer', $this->customerRule()],
            'customer_data' => ['nullable', 'array'],
            'customer_data.name' => ['required_with:customer_data', 'string', 'max:255'],
            'customer_data.phone' => ['nullable', 'string'],
            'customer_data.email' => ['nullable', 'email'],
            'customer_data.address' => ['nullable', 'string'],
            'priority' => ['nullable', 'integer', 'in:1,2,3'],
        ]);

        $result = $this->receptionService->completeRegistration(
            tenant: $this->tenant(),
            acquisitionId: (int) $validated['acquisition_id'],
            customerId: isset($validated['customer_id']) ? (int) $validated['customer_id'] : null,
            customerData: $validated['customer_data'] ?? null,
            priority: isset($validated['priority']) ? (int) $validated['priority'] : null,
            userId: $this->actor($request),
        );

        return response()->json([
            'message' => 'Registration completed successfully and queue ticket created.',
            'data' => $result,
        ]);
    }

    /**
     * Recall a reception ticket (announce again).
     */
    public function recall(Request $request, int $id): JsonResponse
    {
        $ticket = $this->receptionService->recall(
            tenant: $this->tenant(),
            ticketId: $id,
            userId: $this->actor($request),
        );

        return response()->json([
            'message' => "Ticket {$ticket->queue_number} recalled for reception.",
            'data' => $ticket,
        ]);
    }

    /**
     * Skip a reception ticket.
     */
    public function skip(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string'],
        ]);

        $ticket = $this->receptionService->skip(
            tenant: $this->tenant(),
            ticketId: $id,
            reason: $validated['reason'] ?? null,
            userId: $this->actor($request),
        );

        return response()->json([
            'message' => "Ticket {$ticket->queue_number} skipped.",
            'data' => $ticket,
        ]);
    }

    /**
     * Mark a reception ticket as no-show.
     */
    public function noShow(Request $request, int $id): JsonResponse
    {
        $ticket = $this->receptionService->noShow(
            tenant: $this->tenant(),
            ticketId: $id,
            userId: $this->actor($request),
        );

        return response()->json([
            'message' => "Ticket {$ticket->queue_number} marked no-show.",
            'data' => $ticket,
        ]);
    }
}
