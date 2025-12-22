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
                ->whereNull('deleted_at')   
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

    $validated['required_per_vci'] = $validated['required_per_vci'] ?? 1;

    $validated['updated_by'] = auth()->id();
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
        $isUsedInPurchase = DB::table('sparepart_purchase_items') // 👈 CHANGE ONLY IF NEEDED
            ->where('sparepart_id', $sparepart->id)
            ->exists();

        if ($isUsedInPurchase) {
            return response()->json([
                'success' => false,
                'message' => 'This component is used in Purchase and cannot be deleted.'
            ], 409);
        }

        // Soft delete
        $sparepart->deleted_by = auth()->id();
        $sparepart->save();
        $sparepart->delete();

        return response()->json([
            'success' => true,
            'message' => 'Sparepart deleted successfully!'
        ], 200);

    } catch (\Exception $e) {

        Log::error('Sparepart delete failed', [
            'sparepart_id' => $id,
            'error' => $e->getMessage(),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Failed to delete spare part.'
        ], 500);
    }
}



private function calculateSparepartStock()
{
    $purchased = DB::table('sparepart_purchase_items as pi')
        ->leftJoin('spareparts as sp', 'pi.sparepart_id', '=', 'sp.id')
        ->whereNull('pi.deleted_at')
        ->whereNull('pi.deleted_by')
        ->select(
            'pi.sparepart_id',
            'sp.name as sparepart_name',
            DB::raw('SUM(pi.quantity) as purchased_quantity')
        )
        ->groupBy('pi.sparepart_id', 'sp.name')
        ->get()
        ->keyBy('sparepart_id');

    if ($purchased->isEmpty()) {
        return collect();
    }

    $purchasedSerials = DB::table('sparepart_purchase_items')
        ->whereNull('deleted_at')
        ->whereNull('deleted_by')
        ->whereNotNull('serial_no')
        ->select('sparepart_id', 'serial_no')
        ->get()
        ->groupBy('sparepart_id')
        ->map(fn ($rows) =>
            $rows->pluck('serial_no')->map(fn ($s) => trim($s))->unique()->values()->toArray()
        );

    $assembledSerials = DB::table('inventory')
        ->whereNotNull('serial_no')
        ->whereNull('deleted_at')
        ->pluck('serial_no')
        ->map(fn ($x) => trim($x))
        ->unique()
        ->toArray();

    /* =========================
       ASSEMBLED COUNTS
    ========================== */
    $assembledCounts = DB::table('inventory')
        ->whereNull('deleted_at')
        ->whereNull('deleted_by')
        ->select('product_id', DB::raw('COUNT(*) as qty'))
        ->groupBy('product_id')
        ->pluck('qty', 'product_id');

    $productRequirements = DB::table('product')
        ->whereNull('deleted_at')
        ->select('id', 'sparepart_requirements')
        ->get()
        ->map(function ($row) {
            $row->sparepart_requirements = json_decode($row->sparepart_requirements, true) ?? [];
            return $row;
        })
        ->keyBy('id');

    /* =========================
       SERVICE DATA
    ========================== */
    $pcbInService = DB::table('service_vci_items')
        ->whereNotNull('vci_serial_no')
        ->whereIn('status', ['Inward', 'Testing'])
        ->whereNull('deleted_at')
        ->get()
        ->groupBy('sparepart_id');

    $pcbDelivered = DB::table('service_vci_items')
        ->where('status', 'Delivered')
        ->whereNotNull('vci_serial_no')
        ->whereNull('deleted_at')
        ->pluck('vci_serial_no')
        ->map(fn ($x) => trim($x))
        ->toArray();

    $nonPcbReturns = DB::table('service_vci_items')
        ->where('status', 'Return')
        ->whereNotNull('quantity')
        ->whereNull('deleted_at')
        ->groupBy('sparepart_id')
        ->select('sparepart_id', DB::raw('SUM(quantity) as qty'))
        ->pluck('qty', 'sparepart_id');

    /* =========================
       FINAL CALCULATION
    ========================== */
    return $purchased->map(function ($row) use (
        $purchasedSerials,
        $assembledSerials,
        $assembledCounts,
        $productRequirements,
        $pcbInService,
        $pcbDelivered,
        $nonPcbReturns
    ) {
        $id = $row->sparepart_id;
        $name = strtolower($row->sparepart_name);

        /* ---------- PCB ---------- */
        if (str_contains($name, 'pcb')) {

            $serviceSerials = collect($pcbInService[$id] ?? [])
                ->pluck('vci_serial_no')
                ->map(fn ($x) => trim($x))
                ->toArray();

            $allPurchased = $purchasedSerials[$id] ?? [];

            $available = array_diff(
                $allPurchased,
                $assembledSerials,
                $pcbDelivered,
                $serviceSerials
            );

            return [
                'sparepart_id'       => $id,
                'purchased_quantity' => count($allPurchased),
                'used_quantity'      => count($assembledSerials),
                'service_quantity'   => count($serviceSerials),
                'available_quantity' => count($available),
            ];
        }

        /* ---------- NON-PCB ---------- */
        $totalUsed = 0;

        foreach ($productRequirements as $productId => $pr) {
            $assembledQty = $assembledCounts[$productId] ?? 0;

            $required = collect($pr->sparepart_requirements)
                ->firstWhere('id', $id)['required_quantity'] ?? 0;

            $totalUsed += $assembledQty * $required;
        }

        $serviceQty = $nonPcbReturns[$id] ?? 0;

        return [
            'sparepart_id'       => $id,
            'purchased_quantity' => (int) $row->purchased_quantity,
            'used_quantity'      => $totalUsed,
            'service_quantity'   => $serviceQty,
            'available_quantity' => max(
                $row->purchased_quantity - $totalUsed - $serviceQty,
                0
            ),
        ];
    });
}



public function index()
{
    $stock = $this->calculateSparepartStock()->keyBy('sparepart_id');

    $spareparts = \App\Models\Sparepart::whereNull('deleted_at')->get();

    $result = $spareparts->map(function ($part) use ($stock) {

        $calculated = $stock[$part->id] ?? [
            'purchased_quantity' => 0,
            'used_quantity' => 0,
            'service_quantity' => 0,
            'available_quantity' => 0,
        ];

        return [
            'id' => $part->id,
            'code' => $part->code,
            'name' => $part->name,
            'sparepart_type' => $part->sparepart_type,

            'purchased_quantity' => $calculated['purchased_quantity'],
            'used_quantity' => $calculated['used_quantity'],
            'service_quantity' => $calculated['service_quantity'],
            'available_quantity' => $calculated['available_quantity'],

            'created_at' => $part->created_at,
            'updated_at' => $part->updated_at,
        ];
    });

    return response()->json([
        'spareparts' => $result->values()
    ]);
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
