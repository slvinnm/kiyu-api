<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\IntakeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IntakeController extends Controller
{
    public function __construct(
        public IntakeService $intakeService
    ) {}

    /**
     * Create a Kiosk walk-in intake.
     */
    public function kiosk(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'priority' => ['nullable', 'integer', 'in:1,2,3'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ]);

        $result = $this->intakeService->createKioskIntake(
            tenant: $this->tenant(),
            departmentId: (int) $validated['department_id'],
            priority: (int) ($validated['priority'] ?? 1),
            idempotencyKey: $validated['idempotency_key'] ?? null
        );

        return response()->json([
            'message' => 'Kiosk intake created successfully.',
            'data' => $result,
        ], 201);
    }

    /**
     * Create a Manual walk-in intake by reception.
     */
    public function manual(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'priority' => ['nullable', 'integer', 'in:1,2,3'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
            'registered_by' => ['nullable', 'string'],
        ]);

        $result = $this->intakeService->createManualIntake(
            tenant: $this->tenant(),
            departmentId: (int) $validated['department_id'],
            priority: (int) ($validated['priority'] ?? 1),
            idempotencyKey: $validated['idempotency_key'] ?? null,
            registeredBy: $validated['registered_by'] ?? null
        );

        return response()->json([
            'message' => 'Manual intake created successfully.',
            'data' => $result,
        ], 201);
    }

    /**
     * Create an online booking intake.
     *
     * The booking always belongs to the signed-in client's own profile; the
     * body's `client_id` is ignored. Staff may book on behalf of a client.
     */
    public function online(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'priority' => ['nullable', 'integer', 'in:1,2,3'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ]);

        // A client may only book for their own profile. Staff roles keep the
        // existing ability to book for any client in their tenant.
        if ($this->isClient($request)) {
            $ownId = $this->clientId($request);

            abort_if($ownId === null, 403, 'Forbidden.');

            $clientId = $ownId;
        } else {
            $clientId = isset($validated['client_id']) ? (int) $validated['client_id'] : null;

            abort_if($clientId === null, 422, 'A client_id is required.');
        }

        $result = $this->intakeService->createOnlineBooking(
            tenant: $this->tenant(),
            clientId: $clientId,
            departmentId: (int) $validated['department_id'],
            priority: (int) ($validated['priority'] ?? 1),
            idempotencyKey: $validated['idempotency_key'] ?? null
        );

        return response()->json([
            'message' => 'Online booking created successfully. Client must check in at kiosk upon arrival.',
            'data' => $result,
        ], 201);
    }

    /**
     * Perform self check-in for an online booking at kiosk.
     */
    public function onlineCheckin(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'booking_identifier' => ['required', 'string'],
        ]);

        $result = $this->intakeService->performOnlineCheckin(
            tenant: $this->tenant(),
            bookingIdentifier: $validated['booking_identifier']
        );

        return response()->json([
            'message' => 'Check-in successful. Client has entered the reception queue.',
            'data' => $result,
        ]);
    }

    /**
     * Preview / lookup an online booking for kiosk display (no check-in performed).
     */
    public function onlineLookup(Request $request, string $identifier): JsonResponse
    {
        $result = $this->intakeService->lookupOnlineBooking(
            tenant: $this->tenant(),
            bookingIdentifier: $identifier
        );

        return response()->json([
            'message' => 'Online booking preview retrieved.',
            'data' => $result,
        ]);
    }
}
