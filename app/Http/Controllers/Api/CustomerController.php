<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InvalidStateException;
use App\Http\Controllers\Api\Concerns\HandlesTableQuery;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ClientController extends Controller
{
    use HandlesTableQuery;

    /**
     * Search and list clients.
     */
    public function index(Request $request): JsonResponse
    {
        // Clients must not enumerate the client directory; they read their
        // own profile through /auth/me or GET /clients/{id}.
        abort_if($this->isClient($request), 403, 'Forbidden.');

        $validated = $this->validateTableQuery($request, [
            'nik' => ['nullable', 'string', 'max:50'],
            'mrn' => ['nullable', 'string', 'max:50'],
        ]);

        $query = Client::query()->where('tenant_id', $this->tenantId());

        if (! empty($validated['search'])) {
            $search = $validated['search'];
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('mrn', 'like', "%{$search}%")
                    ->orWhere('nik', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if (! empty($validated['nik'])) {
            $query->where('nik', $validated['nik']);
        }

        if (! empty($validated['mrn'])) {
            $query->where('mrn', $validated['mrn']);
        }

        $this->applyTableSort($query, $validated, ['name', 'mrn', 'nik', 'created_at', 'id']);

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

        return response()->json([
            'data' => [
                'total' => Client::query()->where('tenant_id', $this->tenantId())->count(),
            ],
        ]);
    }

    /**
     * Create a client.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'nik' => ['nullable', 'string', 'max:50'],
            'mrn' => ['nullable', 'string', 'max:50'],
            'date_of_birth' => ['nullable', 'date'],
            'gender' => ['nullable', 'string', 'max:10'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        // A client may only create their own profile. Without this, any
        // authenticated account could attach a client record to another user.
        if ($this->isClient($request)) {
            $userId = $request->user()->id;

            if (! empty($validated['user_id']) && (int) $validated['user_id'] !== $userId) {
                abort(403, 'Forbidden.');
            }

            if (Client::query()->where('user_id', $userId)->exists()) {
                throw new InvalidStateException('A client profile already exists for this account.');
            }

            $validated['user_id'] = $userId;
        }

        // When a client is linked to a user account, that user must belong to
        // this tenant. `exists:users,id` alone would accept another clinic's
        // user, so the tenant is checked here.
        if (! empty($validated['user_id'])) {
            $this->assertUserInTenant((int) $validated['user_id']);
        }

        $mrn = $validated['mrn'] ?? ('MRN-' . strtoupper(Str::random(8)));

        $client = new Client([
            'name' => $validated['name'],
            'nik' => $validated['nik'] ?? null,
            'mrn' => $mrn,
            'date_of_birth' => $validated['date_of_birth'] ?? null,
            'gender' => $validated['gender'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'email' => $validated['email'] ?? null,
            'address' => $validated['address'] ?? null,
            'user_id' => $validated['user_id'] ?? null,
        ]);

        $client->tenant_id = $this->tenantId();
        $client->save();

        return response()->json([
            'message' => 'Client created successfully.',
            'data' => $client,
        ], 201);
    }

    /**
     * Show a client.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $this->authorizeClientRecord($request, $id);

        // Visit history is paginated through /clients/{id}/visits; embedding
        // every visit here would grow without bound for a long-standing client.
        $client = Client::query()
            ->where('tenant_id', $this->tenantId())
            ->findOrFail($id);

        return response()->json([
            'data' => $client,
        ]);
    }

    /**
     * Update a client.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $this->authorizeClientRecord($request, $id);

        $client = Client::query()
            ->where('tenant_id', $this->tenantId())
            ->findOrFail($id);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'nik' => ['nullable', 'string', 'max:50'],
            'mrn' => ['nullable', 'string', 'max:50'],
            'date_of_birth' => ['nullable', 'date'],
            'gender' => ['nullable', 'string', 'max:10'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string'],
        ]);

        $client->update($validated);

        return response()->json([
            'message' => 'Client updated successfully.',
            'data' => $client,
        ]);
    }

    /**
     * Get visits for a client.
     */
    public function visits(Request $request, int $id): JsonResponse
    {
        $this->authorizeClientRecord($request, $id);

        $client = Client::query()
            ->where('tenant_id', $this->tenantId())
            ->findOrFail($id);

        $validated = $this->validateTableQuery($request);

        return response()->json([
            'data' => $client->visits()
                ->where('tenant_id', $this->tenantId())
                ->with(['department', 'workflow', 'activeQueueTicket'])
                ->latest('id')
                ->paginate($this->tablePerPage($validated)),
        ]);
    }

    /**
     * Deny a client account access to another client's record.
     *
     * Staff operations (reception, admin) are unrestricted here and keep their
     * existing behaviour; only the client role is scoped to its own record.
     */
    private function authorizeClientRecord(Request $request, int $clientId): void
    {
        if (! $this->isClient($request)) {
            return;
        }

        abort_unless($this->clientId($request) === $clientId, 403, 'Forbidden.');
    }

    /**
     * Abort unless the given user belongs to the current tenant.
     */
    private function assertUserInTenant(int $userId): void
    {
        $belongs = User::query()
            ->whereKey($userId)
            ->where('tenant_id', $this->tenantId())
            ->exists();

        abort_unless($belongs, 422, 'The linked user does not belong to this clinic.');
    }
}
