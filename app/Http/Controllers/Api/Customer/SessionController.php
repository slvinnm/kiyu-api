<?php

namespace App\Http\Controllers\Api\Customer;

use App\Enums\IntakeChannel;
use App\Enums\QueueSessionStatus;
use App\Http\Controllers\Api\Concerns\HandlesTableQuery;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\QueueSession;
use App\Services\IntakeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A customer's own visits and bookings.
 *
 * Every query is scoped to the authenticated customer's `customers.id`; a
 * `customer_id` in the request can never widen it.
 */
class SessionController extends Controller
{
    use HandlesTableQuery;

    public function __construct(
        private readonly IntakeService $intake,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $this->validateTableQuery($request, [
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'status' => ['nullable', 'string', Rule::in(array_column(QueueSessionStatus::cases(), 'value'))],
            'intake_channel' => ['nullable', 'string', Rule::in(array_column(IntakeChannel::cases(), 'value'))],
            'date' => ['nullable', 'date'],
        ]);

        $query = QueueSession::query()
            ->where('tenant_id', $this->tenantId())
            ->where('customer_id', $this->ownCustomerId($request))
            ->with(['department', 'workflow']);

        foreach (['department_id', 'status', 'intake_channel'] as $filter) {
            if (! empty($validated[$filter])) {
                $query->where($filter, $validated[$filter]);
            }
        }

        if (! empty($validated['date'])) {
            $query->whereDate('created_at', $validated['date']);
        }

        $this->applyTableSort(
            $query,
            $validated,
            ['session_number', 'status', 'priority', 'intake_channel', 'created_at', 'id'],
        );

        return response()->json([
            'data' => $query->paginate($this->tablePerPage($validated)),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $session = QueueSession::query()
            ->where('tenant_id', $this->tenantId())
            ->where('customer_id', $this->ownCustomerId($request))
            ->with([
                'department',
                'workflow',
                'queueTickets.station',
                'queueTickets.workflowStep',
                'queueTickets.events',
            ])
            ->findOrFail($id);

        return response()->json([
            'data' => $session,
        ]);
    }

    /**
     * Book an online visit for the authenticated customer.
     */
    public function book(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'department_id' => ['required', 'integer', $this->departmentRule()],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ]);

        $result = $this->intake->createOnlineBooking(
            tenant: $this->tenant(),
            customerId: $this->ownCustomerId($request),
            departmentId: (int) $validated['department_id'],
            priority: 1,
            idempotencyKey: $validated['idempotency_key'] ?? null,
        );

        return response()->json([
            'message' => 'Online booking created successfully. Check in at the kiosk upon arrival.',
            'data' => $result,
        ], 201);
    }

    /**
     * The authenticated customer's own `customers.id`, or 403.
     */
    private function ownCustomerId(Request $request): int
    {
        $customer = Customer::query()
            ->where('tenant_id', $this->tenantId())
            ->where('user_id', $request->user()->id)
            ->first();

        abort_if($customer === null, 403, 'Complete your profile before using this feature.');

        return $customer->id;
    }
}
