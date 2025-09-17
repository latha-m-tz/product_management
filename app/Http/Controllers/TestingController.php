<?php
// app/Http/Controllers/TestingController.php
namespace App\Http\Controllers;

use App\Models\Testing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TestingController extends Controller
{
    public function index()
    {
        $data = Testing::with(['product', 'assemble', 'tester'])->latest()->get();
        return response()->json($data);
    }

    // Show one testing
    public function show($id)
    {
        $testing = Testing::with(['product', 'assemble', 'tester'])->findOrFail($id);
        return response()->json($testing);
    }

    // Store new test record
    public function store(Request $request)
    {
        $validated = $request->validate([
            'product_id'  => 'required|exists:product,id',
            'assemble_id' => 'required|exists:assemble,id',
            'tested_by'   => 'required|exists:users,id',
            'serial_no'   => 'required|string|max:100',
            'status'      => 'required|string|max:50',
            'remarks'     => 'nullable|string',
        ]);

        $validated['tested_date'] = now();
        $validated['created_by'] = Auth::id();

        $testing = Testing::create($validated);

        return response()->json([
            'message' => 'Testing record created successfully',
            'data' => $testing
        ], 201);
    }

    // Update
    public function update(Request $request, $id)
    {
        $testing = Testing::findOrFail($id);

        $validated = $request->validate([
            'status'  => 'sometimes|required|string|max:50',
            'remarks' => 'nullable|string',
        ]);

        $validated['updated_by'] = Auth::id();

        $testing->update($validated);

        return response()->json([
            'message' => 'Testing record updated successfully',
            'data' => $testing
        ]);
    }

    // Delete
    public function destroy($id)
    {
        $testing = Testing::findOrFail($id);
        $testing->delete();

        return response()->json([
            'message' => 'Testing record deleted successfully'
        ]);
    }
}
