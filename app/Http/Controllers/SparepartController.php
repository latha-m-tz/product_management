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
        'code' => [
            'nullable',
            'string',
            'max:50',
            function ($attribute, $value, $fail) {
                if ($value) {
                    $exists = Sparepart::where('code', $value)
                        ->whereNull('deleted_at')   // Soft delete check
                        ->exists();

                    if ($exists) {
                        $fail("The $attribute has already been taken.");
                    }
                }
            },
        ],

        'name' => [
            'required',
            'string',
            'max:255',
            function ($attribute, $value, $fail) {
                $normalized = strtolower(str_replace(' ', '', $value));

                $exists = Sparepart::whereRaw(
                    "REPLACE(LOWER(name), ' ', '') = ?", [$normalized]
                )
                ->whereNull('deleted_at')   // Soft delete check
                ->exists();

                if ($exists) {
                    $fail("The $attribute has already been taken.");
                }
            },
        ],

        'sparepart_type' => 'required|string|max:255',
        'sparepart_usages' => 'nullable|string|max:255',
        'required_per_vci' => 'nullable|integer|min:1',
    ]);

    // Default value if missing
    $validated['required_per_vci'] = $validated['required_per_vci'] ?? 1;

    // Add created_by
    $validated['created_by'] = auth()->id();

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
    $sparepart = Sparepart::find($id);

    if (!$sparepart) {
        return response()->json([
            'message' => 'Sparepart not found!'
        ], 404);
    }

    // Validate fields
    $validated = $request->validate([
        'code' => [
            'nullable',
            'string',
            'max:50',
            function ($attribute, $value, $fail) use ($id) {
                if ($value) {
                    $exists = Sparepart::where('code', $value)
                        ->whereNull('deleted_at')   // Soft delete safe
                        ->where('id', '!=', $id)
                        ->exists();

                    if ($exists) {
                        $fail("The $attribute has already been taken.");
                    }
                }
            },
        ],

        'name' => [
            'required',
            'string',
            'max:255',
            function ($attribute, $value, $fail) use ($id) {
                $normalized = strtolower(str_replace(' ', '', $value));

                $exists = Sparepart::whereRaw(
                    "REPLACE(LOWER(name), ' ', '') = ?", [$normalized]
                )
                ->whereNull('deleted_at')       // Soft delete safe
                ->where('id', '!=', $id)
                ->exists();

                if ($exists) {
                    $fail("The $attribute has already been taken.");
                }
            },
        ],

        'sparepart_type' => 'required|string|max:255',
        'sparepart_usages' => 'nullable|string|max:255',
        'required_per_vci' => 'nullable|integer|min:1',
    ]);

    // Set default value
    $validated['required_per_vci'] = $validated['required_per_vci'] ?? 1;

    $validated['updated_by'] = auth()->id();
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
        // Store deleted_by before soft delete
        $sparepart->deleted_by = auth()->id();
        $sparepart->save();

        // Perform soft delete
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

        // Foreign key constraint violation (linked to other records)
        if ($e->getCode() === '23503') {
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
            'success' => false,
            'message' => 'Unexpected error occurred while deleting spare part.'
        ], 500);
    }
}



public function index()
{
    // Fetch only active (non-deleted) spareparts
    $spareparts = \App\Models\Sparepart::whereNull('deleted_at')->get();

    $sparepartsWithAvailableQty = $spareparts->map(function ($part) {

        /** -----------------------------------------
         * 1. TOTAL PURCHASED QTY (soft delete safe)
         * ----------------------------------------- */
        $purchasedQty = (int) \DB::table('sparepart_purchase_items')
            ->where('sparepart_id', $part->id)
            ->whereNull('deleted_at')     // soft delete safe
            ->whereNull('deleted_by')     // soft delete safe
            ->sum('quantity');


        /** -----------------------------------------
         * 2. USED QUANTITY CALCULATION
         * ----------------------------------------- */
        $usedQty = 0;

        // sparepart_usages is JSON: [{"product_id":1,"required_quantity":2}, ...]
        $usages = json_decode($part->sparepart_usages, true);

        if (is_array($usages) && !empty($usages)) {

            // Sparepart has multiple usage rules
            foreach ($usages as $usage) {

                $productId = $usage['product_id'] ?? null;
                $requiredPerProduct = (int) ($usage['required_quantity'] ?? $part->required_per_vci ?? 1);

                if ($productId) {

                    // Count assembled VCIs for this product only
                    $assembledVCIs = \DB::table('inventory')
                        ->where('product_id', $productId)
                        ->whereNull('deleted_by')
                        ->whereNull('deleted_at')
                        ->count();

                    // Add used quantity
                    $usedQty += $assembledVCIs * $requiredPerProduct;
                }
            }

        } else {

            /** -----------------------------------------
             * 3. If no usage rules → fall back to generic rule
             * required_per_vci × ALL assembled VCIs
             * ----------------------------------------- */
            $assembledVCIs = \DB::table('inventory')
                ->whereNull('deleted_by')
                ->whereNull('deleted_at')
                ->count();

            $requiredPerVci = (int) ($part->required_per_vci ?? 1);

            $usedQty = $assembledVCIs * $requiredPerVci;
        }


        /** -----------------------------------------
         * 4. AVAILABLE QUANTITY (never negative)
         * ----------------------------------------- */
        $availableQty = $purchasedQty - $usedQty;
        if ($availableQty < 0) {
            $availableQty = 0;
        }


        /** -----------------------------------------
         * 5. FINAL OUTPUT STRUCTURE
         * ----------------------------------------- */
        return [
            'id' => $part->id,
            'code' => $part->code,
            'name' => $part->name,

            'sparepart_type' => $part->sparepart_type,
            'sparepart_usages' => $part->sparepart_usages,
            'required_per_vci' => (int) ($part->required_per_vci ?? 1),

            'purchased_quantity' => $purchasedQty,
            'used_quantity' => $usedQty,
            'available_quantity' => $availableQty,

            'created_at' => $part->created_at,
            'updated_at' => $part->updated_at,
        ];
    });

    return response()->json([
        'spareparts' => $sparepartsWithAvailableQty->values()
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
