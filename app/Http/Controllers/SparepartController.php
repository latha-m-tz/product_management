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
    /**
     * Store a newly created sparepart.
     */
   public function store(Request $request)
{
    $validated = $request->validate([
        'code' => [
            'nullable',
            'string',
            'max:50',
            Rule::unique('spareparts', 'code'),
        ],
        'name' => [
            'required',
            'string',
            'max:255',
            function ($attribute, $value, $fail) {
                $normalizedName = strtolower(str_replace(' ', '', $value));
                if (Sparepart::whereRaw("REPLACE(LOWER(name), ' ', '') = ?", [$normalizedName])->exists()) {
                    $fail('The ' . $attribute . ' has already been taken.');
                }
            },
        ],
        'sparepart_type' => 'required|string|max:255',
        'sparepart_usages' => 'nullable|string|max:255',
        'required_per_vci' => 'nullable|integer|min:1', // NEW
    ]);

    // Set default value if not provided
    $validated['required_per_vci'] = $validated['required_per_vci'] ?? 1;

    $sparepart = Sparepart::create($validated);

    return response()->json([
        'message'   => 'Sparepart created successfully!',
        'sparepart' => $sparepart
    ], 201);
}

    /**
     * Edit a sparepart by ID.
     */
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

    /**
     * Update an existing sparepart.
     */
    public function update(Request $request, $id)
    {
        $sparepart = Sparepart::find($id);

        if (!$sparepart) {
            return response()->json([
                'message' => 'Sparepart not found!'
            ], 404);
        }

        // Normalize input name for duplicate check
        $normalizedName = strtolower(str_replace(' ', '', $request->name));

        $exists = Sparepart::whereRaw("REPLACE(LOWER(name), ' ', '') = ?", [$normalizedName])
            ->where('id', '!=', $id)
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'Sparepart with this name already exists!'
            ], 422);
        }

        // Validate fields
      $validated = $request->validate([
    'code' => [
        'nullable',
        'string',
        'max:50',
        Rule::unique('spareparts', 'code')->ignore($id),
    ],
    'name' => 'required|string|max:255',
    'sparepart_type' => 'required|string|max:255',
    'sparepart_usages' => 'nullable|string|max:255',
    'required_per_vci' => 'nullable|integer|min:1', // NEW
]);

// Set default if not provided
$validated['required_per_vci'] = $validated['required_per_vci'] ?? 1;

$sparepart->update($validated);

        return response()->json([
            'message'   => 'Sparepart updated successfully!',
            'sparepart' => $sparepart
        ], 200);
    }

    /**
     * Delete a sparepart.
     */
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
                'sparepart_id' => $sparepart->id,
            ]);

            if ($e->getCode() === '23503') { // foreign key violation
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete: This spare part is linked to purchase records.'
                ], 409);
            }

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
    // Fetch all spareparts once
    $spareparts = \App\Models\Sparepart::all();

    // Map and compute available quantities
    $sparepartsWithAvailableQty = $spareparts->map(function ($part) {
        // ✅ Total purchased quantity for this sparepart
        $purchasedQty = \DB::table('sparepart_purchase_items')
            ->where('sparepart_id', $part->id)
            ->sum('quantity');

        // ✅ Count assembled VCIs that use this sparepart
        // This assumes sparepart_usages or required_per_vci indicates how many are used per product
        $usedQty = 0;

        // If sparepart_usages is JSON array [{id, required_quantity}], decode and sum
        $usages = json_decode($part->sparepart_usages, true) ?? [];
        foreach ($usages as $usage) {
            $productId = $usage['product_id'] ?? null;
            $requiredPerProduct = $usage['required_quantity'] ?? $part->required_per_vci ?? 1;

            if ($productId) {
                $assembledVCIs = \DB::table('inventory')
                    ->whereNull('deleted_by')
                    ->whereNull('deleted_at')
                    ->where('product_id', $productId)
                    ->count();

                $usedQty += $assembledVCIs * $requiredPerProduct;
            }
        }

        // If no specific usages defined, fallback to required_per_vci * total assembled VCIs
        if (empty($usages)) {
            $assembledVCIs = \DB::table('inventory')
                ->whereNull('deleted_by')
                ->whereNull('deleted_at')
                ->count();

            $requiredPerVCI = $part->required_per_vci ?? 1;
            $usedQty = $assembledVCIs * $requiredPerVCI;
        }

        $availableQty = max($purchasedQty - $usedQty, 0);

        return [
            'id' => $part->id,
            'code' => $part->code,
            'name' => $part->name,
            'sparepart_type' => $part->sparepart_type,
            'sparepart_usages' => $part->sparepart_usages,
            'required_per_vci' => $part->required_per_vci,
            'available_quantity' => $availableQty,
            'created_at' => $part->created_at,
            'updated_at' => $part->updated_at,
        ];
    });

    // ✅ Send response back to frontend
    return response()->json([
        'spareparts' => $sparepartsWithAvailableQty->values(),
    ], 200);
}



    public function deleteItem($purchase_id, $sparepart_id)
    {
        $deleted = SparepartPurchaseItem::where('purchase_id', $purchase_id)
            ->where('sparepart_id', $sparepart_id)
            ->delete();

        if ($deleted === 0) {
            return response()->json(['message' => 'No items found'], 404);
        }

        return response()->json(['message' => 'Sparepart row deleted successfully']);
    }
}
