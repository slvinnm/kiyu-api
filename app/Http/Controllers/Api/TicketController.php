<?php

namespace App\Http\Controllers\Api;

use App\Enums\QueueTicketStatus;
use App\Http\Controllers\Api\Concerns\HandlesTableQuery;
use App\Http\Controllers\Controller;
use App\Models\QueueTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TicketController extends Controller
{
    use HandlesTableQuery;

    /**
     * List tickets.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $this->validateTableQuery($request, [
            'station_id' => ['nullable', 'integer', 'exists:stations,id'],
            'status' => ['nullable', 'string', Rule::in(array_column(QueueTicketStatus::cases(), 'value'))],
            'queue_session_id' => ['nullable', 'integer', 'exists:queue_sessions,id'],
            'priority' => ['nullable', 'integer', Rule::in([1, 2, 3])],
            'date' => ['nullable', 'date'],
        ]);

        $query = QueueTicket::query()
            ->whereHas('queueSession', fn($q) => $q->where('tenant_id', $this->tenantId()))
            ->with(['station', 'workflowStep', 'queueSession.customer']);

        foreach (['station_id', 'status', 'queue_session_id', 'priority'] as $filter) {
            if (! empty($validated[$filter])) {
                $query->where($filter, $validated[$filter]);
            }
        }

        if (! empty($validated['date'])) {
            $query->whereDate('created_at', $validated['date']);
        }

        if (! empty($validated['search'])) {
            $search = $validated['search'];
            $query->where(function ($q) use ($search): void {
                $q->where('queue_number', 'like', "%{$search}%")
                    ->orWhereHas('queueSession.customer', fn($q) => $q->where('name', 'like', "%{$search}%"));
            });
        }

        $this->applyTableSort(
            $query,
            $validated,
            ['queue_number', 'status', 'priority', 'internal_sequence', 'created_at', 'id'],
        );

        return response()->json([
            'data' => $query->paginate($this->tablePerPage($validated)),
        ]);
    }

    /**
     * Aggregate counts for dashboard widgets.
     */
    public function statistics(Request $request): JsonResponse
    {
        abort_if($this->isClient($request), 403, 'Forbidden.');

        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::in(array_column(QueueTicketStatus::cases(), 'value'))],
            'station_id' => ['nullable', 'integer', 'exists:stations,id'],
            'date' => ['nullable', 'date'],
        ]);

        $query = QueueTicket::query()
            ->whereHas('queueSession', fn($q) => $q->where('tenant_id', $this->tenantId()));

        foreach (['status', 'station_id'] as $filter) {
            if (! empty($validated[$filter])) {
                $query->where($filter, $validated[$filter]);
            }
        }

        if (! empty($validated['date'])) {
            $query->whereDate('created_at', $validated['date']);
        }

        return response()->json([
            'data' => [
                'total' => $query->count(),
            ],
        ]);
    }

    /**
     * Show ticket details.
     */
    public function show(int $id): JsonResponse
    {
        $ticket = QueueTicket::query()
            ->whereHas('queueSession', fn($q) => $q->where('tenant_id', $this->tenantId()))
            ->with(['station', 'workflowStep', 'queueSession.customer', 'events', 'transferredFrom', 'transferredToStation'])
            ->findOrFail($id);

        return response()->json([
            'data' => $ticket,
        ]);
    }

    /**
     * Get lifecycle events for a ticket.
     */
    public function events(int $id): JsonResponse
    {
        $ticket = QueueTicket::query()
            ->whereHas('queueSession', fn($q) => $q->where('tenant_id', $this->tenantId()))
            ->findOrFail($id);

        return response()->json([
            'data' => $ticket->events()->orderBy('id')->get(),
        ]);
    }
}
