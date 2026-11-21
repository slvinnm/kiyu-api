<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InvalidStateException;
use App\Http\Controllers\Api\Concerns\HandlesTableQuery;
use App\Http\Controllers\Controller;
use App\Models\Patient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PatientController extends Controller
{
    use HandlesTableQuery;

    /**
     * Search and list patients.
     */
    public function index(Request $request): JsonResponse
    {
        // Patients must not enumerate the patient directory; they read their
        // own profile through /auth/me or GET /patients/{id}.
        abort_if($this->isPatient($request), 403, 'Forbidden.');

        $validated = $this->validateTableQuery($request, [
            'nik' => ['nullable', 'string', 'max:50'],
            'mrn' => ['nullable', 'string', 'max:50'],
        ]);

        $query = Patient::query();

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
     * Create a patient.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'nik' => ['nullable', 'string', 'max:50', 'unique:patients,nik'],
            'mrn' => ['nullable', 'string', 'max:50', 'unique:patients,mrn'],
            'date_of_birth' => ['nullable', 'date'],
            'gender' => ['nullable', 'string', 'max:10'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        // A patient may only create their own profile. Without this, any
        // authenticated account could attach a patient record to another user.
        if ($this->isPatient($request)) {
            $userId = $request->user()->id;

            if (! empty($validated['user_id']) && (int) $validated['user_id'] !== $userId) {
                abort(403, 'Forbidden.');
            }

            if (Patient::query()->where('user_id', $userId)->exists()) {
                throw new InvalidStateException('A patient profile already exists for this account.');
            }

            $validated['user_id'] = $userId;
        }

        $mrn = $validated['mrn'] ?? ('MRN-' . strtoupper(Str::random(8)));

        $patient = Patient::query()->create([
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

        return response()->json([
            'message' => 'Patient created successfully.',
            'data' => $patient,
        ], 201);
    }

    /**
     * Show a patient.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $this->authorizePatientRecord($request, $id);

        $patient = Patient::query()->with(['visits.department', 'visits.activeQueueTicket'])->findOrFail($id);

        return response()->json([
            'data' => $patient,
        ]);
    }

    /**
     * Update a patient.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $this->authorizePatientRecord($request, $id);

        $patient = Patient::query()->findOrFail($id);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'nik' => ['nullable', 'string', 'max:50', 'unique:patients,nik,' . $id],
            'mrn' => ['nullable', 'string', 'max:50', 'unique:patients,mrn,' . $id],
            'date_of_birth' => ['nullable', 'date'],
            'gender' => ['nullable', 'string', 'max:10'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string'],
        ]);

        $patient->update($validated);

        return response()->json([
            'message' => 'Patient updated successfully.',
            'data' => $patient,
        ]);
    }

    /**
     * Get visits for a patient.
     */
    public function visits(Request $request, int $id): JsonResponse
    {
        $this->authorizePatientRecord($request, $id);

        $patient = Patient::query()->findOrFail($id);

        return response()->json([
            'data' => $patient->visits()->with(['department', 'workflow', 'activeQueueTicket'])->latest('id')->get(),
        ]);
    }

    /**
     * Deny a patient account access to another patient's record.
     *
     * Staff operations (reception, admin) are unrestricted here and keep their
     * existing behaviour; only the patient role is scoped to its own record.
     */
    private function authorizePatientRecord(Request $request, int $patientId): void
    {
        if (! $this->isPatient($request)) {
            return;
        }

        abort_unless($this->patientId($request) === $patientId, 403, 'Forbidden.');
    }
}
