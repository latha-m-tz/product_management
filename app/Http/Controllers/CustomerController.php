<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Customer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class CustomerController extends Controller
{
    
public function store(Request $request)
{
    $validated = $request->validate([
        'customer' => [
            'required',
            'string',
            'max:100',
            function ($attribute, $value, $fail) {
                $exists = Customer::where('customer', $value)
                    ->whereNull('deleted_at')
                    ->exists();
                if ($exists) {
                    $fail("The $attribute has already been taken.");
                }
            }
        ],

        'email' => [
            'nullable', 'email', 'max:50',
            function ($attribute, $value, $fail) {
                if ($value) {
                    $exists = Customer::where('email', $value)
                        ->whereNull('deleted_at')
                        ->exists();
                    if ($exists) {
                        $fail("The $attribute has already been taken.");
                    }
                }
            }
        ],

        'gst_no' => [
            'nullable', 'string', 'max:15',
            function ($attribute, $value, $fail) {
                if ($value) {
                    $exists = Customer::where('gst_no', $value)
                        ->whereNull('deleted_at')
                        ->exists();
                    if ($exists) {
                        $fail("The $attribute has already been taken.");
                    }
                }
            }
        ],

        'mobile_no' => [
            'nullable',
            'string',
            'max:15',
            function ($attribute, $value, $fail) {
                if ($value) {
                    $exists = Customer::where('mobile_no', $value)
                        ->whereNull('deleted_at')
                        ->exists();
                    if ($exists) {
                        $fail("The mobile number has already been taken.");
                    }
                }
            }
        ],

        'pincode'   => 'nullable|digits:6',
        'city'      => 'required|string|max:100',
        'state'     => 'required|string|max:100',
        'district'  => 'required|string|max:100',
        'address'   => 'nullable|string',
        'status'    => 'nullable|in:active,inactive',
    ]);

    // Create customer
    $customer = Customer::create([
        'customer'   => $validated['customer'],
        'email'      => $validated['email'] ?? null,
        'gst_no'     => $validated['gst_no'] ?? null,
        'mobile_no'  => $validated['mobile_no'] ?? null,
        'pincode'    => $validated['pincode'] ?? null,
        'city'       => $validated['city'],
        'state'      => $validated['state'],
        'district'   => $validated['district'],
        'address'    => $validated['address'] ?? null,
        'status'     => $validated['status'] ?? 'active',
        'created_by' => Auth::id(),
    ]);

    return response()->json([
        'status' => 'success',
        'customer' => $customer,
    ], 201);
}



    public function edit($id)
{
    $customer = Customer::find($id);

    if (!$customer) {
        return response()->json([
            'status' => 'error',
            'message' => 'Customer not found'
        ], 404);
    }

    return response()->json([
        'status' => 'success',
        'customer' => $customer
    ], 200);
}

public function update(Request $request, $id)
{
    $customer = Customer::find($id);

    if (!$customer) {
        return response()->json([
            'status' => 'error',
            'message' => 'Customer not found'
        ], 404);
    }

    $validated = $request->validate([

        'customer' => [
            'required',
            'string',
            'max:100',
            function ($attribute, $value, $fail) use ($id) {
                $exists = Customer::where('customer', $value)
                    ->whereNull('deleted_at')
                    ->where('id', '!=', $id)
                    ->exists();
                if ($exists) {
                    $fail("The $attribute has already been taken.");
                }
            }
        ],

        'email' => [
            'nullable', 'email', 'max:50',
            function ($attribute, $value, $fail) use ($id) {
                if ($value) {
                    $exists = Customer::where('email', $value)
                        ->whereNull('deleted_at')
                        ->where('id', '!=', $id)
                        ->exists();
                    if ($exists) {
                        $fail("The $attribute has already been taken.");
                    }
                }
            }
        ],

        'gst_no' => [
            'nullable', 'string', 'max:15',
            function ($attribute, $value, $fail) use ($id) {
                if ($value) {
                    $exists = Customer::where('gst_no', $value)
                        ->whereNull('deleted_at')
                        ->where('id', '!=', $id)
                        ->exists();
                    if ($exists) {
                        $fail("The $attribute has already been taken.");
                    }
                }
            }
        ],

        'mobile_no' => [
            'nullable', 'string', 'max:15',
            function ($attribute, $value, $fail) use ($id) {
                if ($value) {
                    $exists = Customer::where('mobile_no', $value)
                        ->whereNull('deleted_at')
                        ->where('id', '!=', $id)
                        ->exists();
                    if ($exists) {
                        $fail("The $attribute has already been taken.");
                    }
                }
            }
        ],

        'pincode'   => 'nullable|digits:6',
        'city'      => 'required|string|max:100',
        'state'     => 'required|string|max:100',
        'district'  => 'required|string|max:100',
        'address'   => 'nullable|string',
        'status'    => 'nullable|in:active,inactive',
    ]);

    // Update customer
    $customer->update([
        'customer'   => $validated['customer'],
        'email'      => $validated['email'] ?? null,
        'gst_no'     => $validated['gst_no'] ?? null,
        'pincode'    => $validated['pincode'] ?? null,
        'city'       => $validated['city'],
        'state'      => $validated['state'],
        'district'   => $validated['district'],
        'address'    => $validated['address'] ?? null,
        'mobile_no'  => $validated['mobile_no'] ?? null,
        'status'     => $validated['status'] ?? 'active',
        'updated_by' => Auth::id(),
    ]);

    return response()->json([
        'status' => 'success',
        'customer' => $customer,
        'message' => 'Customer updated successfully'
    ], 200);
}



public function destroy($id)
{
    try {

        $customer = Customer::whereNull('deleted_at')->findOrFail($id);

        $userId = Auth::id();

        // Set who deleted it
        $customer->deleted_by = $userId;
        $customer->save();

        // Soft delete
        $customer->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Customer soft deleted successfully',
            'deleted_by' => $userId
        ], 200);

    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => 'Failed to delete customer: ' . $e->getMessage()
        ], 500);
    }
}


public function index()
    {
        $customers = Customer::all();
        return response()->json($customers);
    }

    public function show($id)
{
    $customer = Customer::find($id);

    if (!$customer) {
        return response()->json([
            'status' => 'error',
            'message' => 'Customer not found'
        ], 404);
    }

    return response()->json([
        'status' => 'success',
        'customer' => $customer
    ]);
}

 


public function customercount()
    {
        try {
            $count = Customer::count();

            return response()->json([
                'success' => true,
                'message' => 'Customer count fetched successfully',
                'count'   => $count
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching customer count: ' . $e->getMessage(),
            ], 500);
        }
    }
 
}