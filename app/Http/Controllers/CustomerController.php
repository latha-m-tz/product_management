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
        
        $validator = Validator::make($request->all(), [
            'customer' => 'required|string|max:100',
            'email' => 'nullable|email|max:50|unique:customers,email',
            'gst_no' => 'nullable|string|max:15|unique:customers,gst_no',

            'pincode' => 'nullable|digits:6',
            'city' => 'required|string|max:100',
            'state' => 'required|string|max:100',
            'district' => 'required|string|max:100',
            'address' => 'nullable|string',
            'mobile_no' => 'nullable|string|max:15',
            'status' => 'nullable|in:active,inactive',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $customer = Customer::create([
            'customer' => $request->customer,
            'gst_no' => $request->gst_no,
            'email' => $request->email,
            'pincode' => $request->pincode,
            'city' => $request->city,
            'state' => $request->state,
            'district' => $request->district,
            'address' => $request->address,
            'mobile_no' => $request->mobile_no,
            'status' => $request->status ?? 'active',
            'created_by' => Auth::id() ?? 1, 
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

    $validator = Validator::make($request->all(), [
        'customer' => 'required|string|max:100',
        'email' => 'nullable|email|max:50|unique:customers,email,' . $id,
        'gst_no' => 'nullable|string|size:15|unique:customers,gst_no,' . $id,
        'pincode' => 'nullable|digits:6',
        'city' => 'required|string|max:100',
        'state' => 'required|string|max:100',
        'district' => 'required|string|max:100',
        'address' => 'nullable|string',
        'mobile_no' => 'nullable|string|max:15',
        'status' => 'nullable|in:active,inactive',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status' => 'error',
            'errors' => $validator->errors(),
        ], 422);
    }

    $customer->update([
        'customer' => $request->customer,
        'gst_no' => $request->gst_no,
        'email' => $request->email,
        'pincode' => $request->pincode,
        'city' => $request->city,
        'state' => $request->state,
        'district' => $request->district,
        'address' => $request->address,
        'mobile_no' => $request->mobile_no,
        'status' => $request->status ?? 'active',
        'updated_by' => Auth::id() ?? 1,
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
       
        $customer = Customer::findOrFail($id);

        $userId = auth()->id() ?? 1; 

        $customer->deleted_by = $userId;
        $customer->save();

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

 


}
