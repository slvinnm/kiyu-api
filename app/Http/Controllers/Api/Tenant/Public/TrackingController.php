<?php

namespace App\Http\Controllers\Api\Tenant\Public;

use App\Http\Controllers\Controller;
use App\Services\QueueSessionService;
use Illuminate\Http\JsonResponse;

/**
 * Tokenised public tracking of a single queue session.
 *
 * Used by the kiosk and by a customer viewing their own ticket. The token is
 * the capability; the response carries no PII beyond the ticket the token was
 * issued for, and never other tenants' data.
 */
class TrackingController extends Controller
{
    public function __construct(
        private readonly QueueSessionService $sessions,
    ) {}

    public function show(string $token): JsonResponse
    {
        return response()->json([
            'data' => $this->sessions->getTrackingData($token),
        ]);
    }
}
