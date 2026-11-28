<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The authenticated customer's own profile.
 *
 * A customer account is linked to a `customers` row through `customers.user_id`.
 * They may create or edit only their own record; the account id is taken from
 * the authenticated user, never from the request body.
 */
class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->ownCustomer($request),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (Customer::query()->where('user_id', $user->id)->exists()) {
            throw ValidationException::withMessages([
                'name' => ['A customer profile already exists for this account.'],
            ]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string'],
        ]);

        $customer = new Customer($validated);
        $customer->tenant_id = $this->tenantId();
        $customer->user_id = $user->id;
        $customer->save();

        return response()->json([
            'message' => 'Customer profile created successfully.',
            'data' => $customer,
        ], 201);
    }

    public function update(Request $request): JsonResponse
    {
        $customer = $this->ownCustomer($request);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string'],
        ]);

        $customer->update($validated);

        return response()->json([
            'message' => 'Customer profile updated successfully.',
            'data' => $customer,
        ]);
    }

    /**
     * The authenticated customer's own record, or 404.
     */
    private function ownCustomer(Request $request): Customer
    {
        return Customer::query()
            ->where('tenant_id', $this->tenantId())
            ->where('user_id', $request->user()->id)
            ->firstOrFail();
    }
}
