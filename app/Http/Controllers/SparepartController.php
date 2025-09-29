<?php

namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Models\Sparepart;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SparepartController extends Controller
{
     public function store(Request $request)
    {

        $validated = $request->validate([
    'name' => [
        'required',
        'string',
        'max:255',
        function ($attribute, $value, $fail) {
            if (Sparepart::whereRaw('LOWER(name) = ?', [strtolower($value)])->exists()) {
                $fail('The '.$attribute.' has already been taken.');
            }
        },
    ],
            'sparepart_type' => 'required|string|max:255',
        ]);

        $sparepart = Sparepart::create($validated);

        return response()->json([
            'message'   => 'Sparepart created successfully!',
            'sparepart' => $sparepart
        ], 201);
    }

    public function edit($id)
{
    $sparepart = Sparepart::find($id);

    if (!$sparepart) {
        return response()->json([
            'message' => 'Sparepart not found!'
        ], 404);
    }

    return response()->json([
        'sparepart' => $sparepart
    ], 200);
}

public function update(Request $request, $id)
{
    // Find the sparepart by ID
    $sparepart = Sparepart::find($id);

    if (!$sparepart) {
        return response()->json([
            'message' => 'Sparepart not found!'
        ], 404);
    }

    // Validate input
    $validated = $request->validate([
        'name'           => 'required|string|max:255',
        'sparepart_type' => 'required|string|max:255',
    ]);

    // Update the sparepart
    $sparepart->update($validated);

    return response()->json([
        'message'   => 'Sparepart updated successfully!',
        'sparepart' => $sparepart
    ], 200);
}
    public function destroy($id)
    {
        $sparepart = Sparepart::find($id);

        if (!$sparepart) {
            return response()->json([
                'message' => 'Sparepart not found!'
            ], 404);
        }

        $sparepart->delete();

        return response()->json([
            'message' => 'Sparepart deleted successfully!'
        ], 200);
    }

        public function index()
    {
        $spareparts = Sparepart::all();

        return response()->json([
            'spareparts' => $spareparts
        ], 200);
    }

}

