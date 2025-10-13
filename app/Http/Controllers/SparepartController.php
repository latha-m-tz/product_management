<?php

namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Models\Sparepart;
use App\Models\SparepartPurchaseItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\QueryException;

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
                $normalizedName = strtolower(str_replace(' ', '', $value));
                if (Sparepart::whereRaw("REPLACE(LOWER(name), ' ', '') = ?", [$normalizedName])->exists()) {
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

    // Normalize the input name
    $normalizedName = strtolower(str_replace(' ', '', $request->name));

    // Check if a different sparepart with the same normalized name exists
    $exists = Sparepart::whereRaw("REPLACE(LOWER(name), ' ', '') = ?", [$normalizedName])
        ->where('id', '!=', $id)
        ->exists();

    if ($exists) {
        return response()->json([
            'message' => 'Sparepart with this name already exists!'
        ], 422);
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
            'success' => false,
            'message' => 'Sparepart not found!'
        ], 404);
    }
    try {
        $sparepart->delete();

        return response()->json([
            'success' => true,
            'message' => 'Sparepart deleted successfully!'
        ], 200);

    } catch (QueryException $e) {
       Log::info('Sparepart deletion failed', [
                        'error' => $e->getMessage(),
                        'code' => $e->getCode(),
                        'product_id' => $sparepart->id,
                    ]);

        if ($e->getCode() === '23503') { 
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete: This spare part is linked to purchase records.'
            ], 409);
        }

        // fallback for other database errors
        return response()->json([
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ], 500);
    } catch (\Exception $e) {
        Log::error("Unexpected error deleting sparepart ID {$id}: {$e->getMessage()}", [
            'trace' => $e->getTraceAsString()
        ]);

        return response()->json([
            'message' => 'Unexpected error occurred while deleting spare part.'
        ], 500);
    }
}

    public function index()
    {
        $spareparts = Sparepart::all();

        return response()->json([
            'spareparts' => $spareparts
        ], 200);
    }

public function deleteItem($purchase_id, $sparepart_id)
{
    $deleted = SparepartPurchaseItem::where('purchase_id', $purchase_id)
        ->where('sparepart_id', $sparepart_id)
        ->delete(); // deletes all matched rows

    if ($deleted === 0) {
        return response()->json(['message' => 'No items found'], 404);
    }

    return response()->json(['message' => 'Sparepart row deleted successfully']);
}



}

