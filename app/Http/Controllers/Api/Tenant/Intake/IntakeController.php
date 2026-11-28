<?php

namespace App\Http\Controllers\Api\Tenant\Intake;

use App\Enums\Priority;
use App\Http\Controllers\Controller;
use App\Services\IntakeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Kiosk and reception intake inside the tenant workspace.
 *
 * Kiosk and online-checkin are device endpoint calls (no user role); the
 * manual endpoint is reception staff creating a walk-in. Priority is never
 * taken from the customer at a self-service screen.
 */
class IntakeController extends Controller
{
    public function __construct(
        public IntakeService $intakeService,
    ) {}

    public function kiosk(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'department_id' => ['required', 'integer', $this->departmentRule()],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ]);

        $result = $this->intakeService->createKioskIntake(
            tenant: $this->tenant(),
            departmentId: (int) $validated['department_id'],
            priority: Priority::Normal->value,
            idempotencyKey: $validated['idempotency_key'] ?? null,
        );

        return response()->json([
            'message' => 'Kiosk intake created successfully.',
            'data' => $result,
        ], 201);
    }

    public function manual(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'department_id' => ['required', 'integer', $this->departmentRule()],
            'priority' => ['nullable', 'integer', 'in:1,2,3'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ]);

        $result = $this->intakeService->createManualIntake(
            tenant: $this->tenant(),
            departmentId: (int) $validated['department_id'],
            priority: (int) ($validated['priority'] ?? 1),
            idempotencyKey: $validated['idempotency_key'] ?? null,
            registeredByUserId: $this->actor($request),
        );

        return response()->json([
            'message' => 'Manual intake created successfully.',
            'data' => $result,
        ], 201);
    }

    /**
     * Create an online booking on behalf of a customer (reception staff).
     */
    public function online(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => ['required', 'integer', $this->customerRule()],
            'department_id' => ['required', 'integer', $this->departmentRule()],
            'priority' => ['nullable', 'integer', 'in:1,2,3'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ]);

        $result = $this->intakeService->createOnlineBooking(
            tenant: $this->tenant(),
            customerId: (int) $validated['customer_id'],
            departmentId: (int) $validated['department_id'],
            priority: (int) ($validated['priority'] ?? 1),
            idempotencyKey: $validated['idempotency_key'] ?? null,
        );

        return response()->json([
            'message' => 'Online booking created successfully. Customer must check in at kiosk upon arrival.',
            'data' => $result,
        ], 201);
    }

    public function onlineCheckin(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'booking_identifier' => ['required', 'string'],
        ]);

        $result = $this->intakeService->performOnlineCheckin(
            tenant: $this->tenant(),
            bookingIdentifier: $validated['booking_identifier'],
        );

        return response()->json([
            'message' => 'Check-in successful. Customer has entered the reception queue.',
            'data' => $result,
        ]);
    }

    public function onlineLookup(string $identifier): JsonResponse
    {
        $result = $this->intakeService->lookupOnlineBooking(
            tenant: $this->tenant(),
            bookingIdentifier: $identifier,
        );

        return response()->json([
            'message' => 'Online booking preview retrieved.',
            'data' => $result,
        ]);
    }
}
