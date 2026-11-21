<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Exceptions\InvalidStateException;
use App\Http\Controllers\Api\Concerns\HandlesTableQuery;
use App\Http\Controllers\Controller;
use App\Models\Station;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    use HandlesTableQuery;

    /**
     * List staff users.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $validated = $this->validateTableQuery($request, [
            'role' => ['nullable', 'string', Rule::in(array_column(UserRole::cases(), 'value'))],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'station_id' => ['nullable', 'integer', 'exists:stations,id'],
        ]);

        $query = User::query()->with(['department', 'station']);

        if (! empty($validated['search'])) {
            $search = $validated['search'];

            $query->where(function ($builder) use ($search): void {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        foreach (['role', 'department_id', 'station_id'] as $filter) {
            if (! empty($validated[$filter])) {
                $query->where($filter, $validated[$filter]);
            }
        }

        $this->applyTableSort($query, $validated, ['name', 'email', 'role', 'created_at', 'id']);

        return response()->json([
            'data' => $query->paginate($this->tablePerPage($validated)),
        ]);
    }

    /**
     * Create a staff user.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', 'string', Rule::in(array_column(UserRole::cases(), 'value'))],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'station_id' => ['nullable', 'integer', 'exists:stations,id'],
        ]);

        $this->validateStationDepartment($validated);

        $user = User::query()->create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'role' => $validated['role'],
            'department_id' => $validated['department_id'] ?? null,
            'station_id' => $validated['station_id'] ?? null,
        ]);

        return response()->json([
            'message' => 'User created successfully.',
            'data' => $user->load(['department', 'station']),
        ], 201);
    }

    /**
     * Show a staff user.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $this->authorizeAdmin($request);

        $user = User::query()->with(['department', 'station'])->findOrFail($id);

        return response()->json([
            'data' => $user,
        ]);
    }

    /**
     * Update a staff user.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $this->authorizeAdmin($request);

        $user = User::query()->findOrFail($id);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'password' => ['sometimes', 'nullable', 'string', 'min:8'],
            'role' => ['sometimes', 'required', 'string', Rule::in(array_column(UserRole::cases(), 'value'))],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'station_id' => ['nullable', 'integer', 'exists:stations,id'],
        ]);

        $this->validateStationDepartment($validated, $user);

        if (array_key_exists('password', $validated) && $validated['password'] === null) {
            unset($validated['password']);
        }

        $user->update($validated);

        return response()->json([
            'message' => 'User updated successfully.',
            'data' => $user->fresh(['department', 'station']),
        ]);
    }

    /**
     * Delete a staff user.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->authorizeAdmin($request);

        $user = User::query()->findOrFail($id);

        if ($request->user()->id === $user->id) {
            throw new InvalidStateException('You cannot delete your own account.');
        }

        if ($user->role === UserRole::Admin->value && User::query()->where('role', UserRole::Admin->value)->count() <= 1) {
            throw new InvalidStateException('Cannot delete the last administrator.');
        }

        $user->delete();

        return response()->json([
            'message' => 'User deleted successfully.',
        ]);
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()?->role === UserRole::Admin->value, 403, 'Forbidden.');
    }

    /**
     * Ensure a station belongs to the selected department when both are given.
     *
     * @param  array<string, mixed>  $data
     */
    private function validateStationDepartment(array $data, ?User $user = null): void
    {
        $stationId = $data['station_id'] ?? null;

        if ($stationId === null) {
            return;
        }

        $station = Station::query()->find($stationId);

        if (! $station) {
            return;
        }

        $departmentId = $data['department_id'] ?? $user?->department_id;

        if ($departmentId !== null && (int) $departmentId !== (int) $station->department_id) {
            throw ValidationException::withMessages([
                'station_id' => ['Station does not belong to the selected department.'],
            ]);
        }
    }
}
