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
            'visit_id' => ['nullable', 'integer', 'exists:visits,id'],
            'priority' => ['nullable', 'integer', Rule::in([1, 2, 3])],
            'date' => ['nullable', 'date'],
        ]);

        $query = QueueTicket::query()->with(['station', 'workflowStep', 'visit.patient']);

        foreach (['station_id', 'status', 'visit_id', 'priority'] as $filter) {
            if (! empty($validated[$filter])) {
                $query->where($filter, $validated[$filter]);
            }
        }

        if (! empty($validated['date'])) {
            $this->whereOnDate($query, $validated['date']);
        }

        if (! empty($validated['search'])) {
            $search = $validated['search'];
            $query->where(function ($q) use ($search): void {
                $q->where('queue_number', 'like', "%{$search}%")
                    ->orWhereHas('visit.patient', fn($q) => $q->where('name', 'like', "%{$search}%"));
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
     * Show ticket details.
     */
    public function show(int $id): JsonResponse
    {
        $ticket = QueueTicket::query()
            ->with(['station', 'workflowStep', 'visit.patient', 'events', 'transferredFrom', 'transferredToStation'])
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
        $ticket = QueueTicket::query()->findOrFail($id);

        return response()->json([
            'data' => $ticket->events()->orderBy('id')->get(),
        ]);
    }
}
