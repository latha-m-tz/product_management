<?php

namespace App\Http\Controllers;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\DeletedSerial;
use Illuminate\Http\Request;
use App\Models\SaleItem;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\Sparepart;
use App\Models\SparepartPurchaseItem; 
class InventoryController extends Controller
{
    public function getAllItems()
    {
       $items = Inventory::whereNull('deleted_at')
    ->with([
        'product:id,name',
        'productType:id,name'  
    ])
    ->get();
        return response()->json($items);
    }

    public function index(Request $request)
    {
        $query = Inventory::with(['product', 'tester', 'productType']);

        // Filter by serial number range
        if ($request->filled('serial_from')) {
            $query->where('serial_no', '>=', $request->serial_from);
        }
        if ($request->filled('serial_to')) {
            $query->where('serial_no', '<=', $request->serial_to);
        }

        // Filter by tested status
        if ($request->filled('tested_status')) {
            $query->where('tested_status', $request->tested_status);
        }

        // Filter by product
        if ($request->filled('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        // Filter by product type
        if ($request->filled('product_type_id')) {
            $query->whereHas('productType', function ($q) use ($request) {
                $q->where('id', $request->product_type_id);
            });
        }

        $soldSerials = SaleItem::pluck('serial_no')->toArray();
        if (!empty($soldSerials)) {
            $query->whereNotIn('serial_no', $soldSerials);
        }

        $inventory = $query->paginate(10);

        return response()->json($inventory);
    }

  public function SerialNumbers()
    {
        $soldSerials = SaleItem::pluck('serial_no')->toArray();
        
        $serials = Inventory::with('product:id,name')
        ->select('id', 'serial_no','product_id','tested_status')
        ->where('tested_status', 'PASS')
        ->whereNotIn('serial_no', $soldSerials)
        ->get()
        ->map(function ($item) {
            return [
                'id'         => $item->id,
                'serial_no'  => $item->serial_no,
                'product'    => $item->product ? $item->product->name : null,
                'tested_status' => $item->tested_status,
            ];
        });
        return response()->json($serials);
    }

    // public function show($id)
    // {
    //     $item = Inventory::with(['product', 'tester', 'productType'])->findOrFail($id);
    //     return response()->json($item);
    // }
public function store(Request $request)
{
    try {
        \Log::info('Inventory payload:', $request->all());

        $validated = $request->validate([
            'product_id' => [
                'required', 'integer',
                function ($attribute, $value, $fail) {
                    if (!\App\Models\Product::where('id', $value)->whereNull('deleted_at')->exists()) {
                        $fail("Invalid product.");
                    }
                },
            ],
            'firmware_version' => 'nullable|string|max:100',
            'tested_date'      => 'nullable|date',

            'items'            => 'required|array|min:1',
            'items.*.serial_no'     => 'required|string|max:100',
            'items.*.tested_by'     => 'nullable|string|max:100',
            'items.*.tested_status' => 'nullable|string',
            'items.*.test_remarks'  => 'nullable|string|max:255',
            'items.*.tested_date'   => 'nullable|date',
        ]);

        $userId = Auth::id() ?? 1;
        $results = [];

        $serialNumbers = collect($validated['items'])
            ->pluck('serial_no')
            ->map(fn($s) => (int)preg_replace('/\D/', '', $s))
            ->sort()
            ->values();

        if ($serialNumbers->isEmpty()) {
            return response()->json(['message' => 'No valid serial numbers found.'], 422);
        }

        $fromSerial = str_pad($serialNumbers->first(), 6, '0', STR_PAD_LEFT);
        $toSerial   = str_pad($serialNumbers->last(), 6, '0', STR_PAD_LEFT);

        // Detect if serials are consecutive
        $isConsecutive = true;
        for ($i = 1; $i < count($serialNumbers); $i++) {
            if ($serialNumbers[$i] !== $serialNumbers[$i - 1] + 1) {
                $isConsecutive = false;
                break;
            }
        }

        $quantity = $isConsecutive
            ? ($serialNumbers->last() - $serialNumbers->first() + 1)
            : count($serialNumbers);

        foreach ($validated['items'] as $item) {

            $serial = trim($item['serial_no']);

            $isPurchased = \App\Models\SparepartPurchaseItem::where('serial_no', $serial)
                ->whereNull('deleted_at')
                ->exists();

            if (!$isPurchased) {
                $results[] = [
                    'serial_no' => $serial,
                    'status'    => 'not_purchased',
                    'message'   => 'This serial is not purchased for the selected product.',
                ];
                continue;
            }

            $isInService = DB::table('service_vci_items')
                ->where('vci_serial_no', $serial)
                ->whereIn('status', ['Inward', 'Testing'])
                ->whereNull('deleted_at')
                ->exists();

            if ($isInService) {
                $results[] = [
                    'serial_no' => $serial,
                    'status'    => 'in_service',
                    'message'   => 'This serial number is currently in service and cannot be assembled.',
                ];
                continue;
            }

            $isDeletedSerial = \App\Models\DeletedSerial::where('serial_no', $serial)
                ->where('product_id', $validated['product_id'])
                ->exists();

            if ($isDeletedSerial) {
                $results[] = [
                    'serial_no' => $serial,
                    'status'    => 'skipped',
                    'message'   => 'Marked deleted — skipped.',
                ];
                continue;
            }

            $exists = \App\Models\Inventory::where('product_id', $validated['product_id'])
                ->where('serial_no', $serial)
                ->whereNull('deleted_at')
                ->exists();

            if ($exists) {
                $results[] = [
                    'serial_no' => $serial,
                    'status'    => 'exists',
                    'message'   => 'Already exists in inventory.',
                ];
                continue;
            }

            \App\Models\Inventory::create([
                'product_id'       => $validated['product_id'],
                'firmware_version' => $validated['firmware_version'] ?? null,
                'tested_date'      => $item['tested_date'] ?? $validated['tested_date'] ?? null,
                'serial_no'        => $serial,
                'tested_by'        => $item['tested_by'] ?? null,
'tested_status' => (isset($item['tested_status']) && $item['tested_status'] !== '')
    ? $item['tested_status']
    : null,
                'test_remarks'     => $item['test_remarks'] ?? null,
                'from_serial'      => $fromSerial,
                'to_serial'        => $toSerial,
                'quantity'         => $quantity,
                'created_by'       => $userId,
            ]);

            $results[] = [
                'serial_no' => $serial,
                'status'    => 'added',
                'message'   => 'Inventory item added successfully.',
            ];
        }

        return response()->json([
            'message'          => 'Inventory process completed',
            'product_id'       => $validated['product_id'],
            'firmware_version' => $validated['firmware_version'] ?? null,
            'tested_date'      => $validated['tested_date'] ?? null,
            'from_serial'      => $fromSerial,
            'to_serial'        => $toSerial,
            'quantity'         => $quantity,
            'items'            => $results,
        ]);

    } catch (\Illuminate\Validation\ValidationException $ve) {
        return response()->json([
            'message' => 'Validation failed',
            'errors'  => $ve->errors()
        ], 422);

    } catch (\Exception $e) {
        \Log::error('Inventory store error: '.$e->getMessage());
        return response()->json([
            'message' => 'Failed to add inventory',
            'error'   => $e->getMessage(),
        ], 500);
    }
}




public function update(Request $request, $id)
{
    try {
        $item = \App\Models\Inventory::findOrFail($id);

        $validated = $request->validate([
            'product_id'       => 'required|integer|exists:products,id',
            'product_type_id'  => 'required|integer|exists:product_types,id',
            'serial_no'        => 'required|string|max:100|unique:inventory,serial_no,' . $id,
            'firmware_version' => 'nullable|string|max:100',
            'tested_status'    => 'nullable|string|in:PASS,FAIL,IN_PROGRESS,PENDING',
            'tested_by'        => 'nullable|string|max:255',
            'tested_date'      => 'nullable|date',
            'test_remarks'     => 'nullable|string|max:255',
            'from_serial'      => 'required|string|max:100',
            'to_serial'        => 'required|string|max:100',
            'quantity'         => 'required|integer|min:1',
        ]);

        // 🧠 Normalize serials to numeric (if they contain prefixes)
        $fromNum = (int) filter_var($validated['from_serial'], FILTER_SANITIZE_NUMBER_INT);
        $toNum   = (int) filter_var($validated['to_serial'], FILTER_SANITIZE_NUMBER_INT);

       
        if (
            !empty($validated['from_serial']) &&
            !empty($validated['to_serial']) &&
            $validated['from_serial'] !== $validated['to_serial'] &&
            $fromNum > $toNum
        ) {
            return response()->json([
                'message' => 'From Serial cannot be greater than To Serial.',
                'status'  => 'invalid_range',
            ], 422);
        }

        // ✅ Ensure serial was purchased
        $isPurchased = \App\Models\SparepartPurchaseItem::where('product_id', $validated['product_id'])
            ->where('serial_no', $validated['serial_no'])
            ->exists();

        if (!$isPurchased) {
            return response()->json([
                'message' => 'This serial number has not been purchased for the selected product.',
                'status'  => 'not_purchased',
            ], 422);
        }

        // ✅ Ensure serial is not deleted
        $isDeletedSerial = \App\Models\DeletedSerial::where('serial_no', $validated['serial_no'])
            ->where('product_id', $validated['product_id'])
            ->where('product_type_id', $validated['product_type_id'])
            ->exists();

        if ($isDeletedSerial) {
            return response()->json([
                'message' => 'This serial number is marked as deleted and cannot be updated.',
                'status'  => 'deleted_serial',
            ], 422);
        }

        // ✅ Update inventory item safely
        $validated['updated_by'] = auth()->id();
        $item->update($validated);

        return response()->json([
            'message' => 'Inventory item updated successfully',
            'data'    => $item,
            'status'  => 'updated',
        ]);

    } catch (\Illuminate\Validation\ValidationException $ve) {
        return response()->json([
            'message' => 'Validation failed',
            'errors'  => $ve->errors(),
        ], 422);
    } catch (\Exception $e) {
        \Log::error('Inventory update error: ' . $e->getMessage());
        return response()->json([
            'message' => 'Failed to update inventory item',
            'error'   => $e->getMessage(),
        ], 500);
    }
}

public function getAllActiveSerials(Request $request)
{
    try {
        $query = \App\Models\Inventory::with('product:id,name')
            ->select('id', 'product_id', 'serial_no', 'tested_status', 'tested_by', 'created_at')
            ->whereNull('deleted_by')
            ->whereNull('deleted_at');

        // ✅ Optional filter by product (series) name
        if ($request->has('series')) {
            $series = urldecode($request->query('series'));

            // Remove text inside parentheses if present (like "VCI 5-SERIES-12V (TYPE A)")
            if (preg_match('/^(.*)\((.*)\)$/', $series, $matches)) {
                $series = trim($matches[1]);
            }

            // Find product by name
            $product = \App\Models\Product::where('name', $series)
                ->whereNull('deleted_at')
                ->first();

            if (!$product) {
                return response()->json([
                    'message' => "Product '{$series}' not found",
                    'exists'  => false,
                    'data'    => [],
                ], 404);
            }

            // Filter inventories by found product_id
            $query->where('product_id', $product->id);
        }

        $inventories = $query->orderBy('id', 'desc')->get();

        if ($inventories->isEmpty()) {
            return response()->json([
                'message' => 'No active serial numbers found',
                'exists'  => false,
                'data'    => [],
            ], 404);
        }

        $data = $inventories->map(function ($inventory) {
            return [
                'id'            => $inventory->id,
                'product_name'  => $inventory->product?->name ?? 'N/A',
                'serial_no'     => $inventory->serial_no,
                'tested_status' => $inventory->tested_status,
                'tested_by'     => $inventory->tested_by,
                'created_at'    => $inventory->created_at->format('Y-m-d H:i:s'),
            ];
        });

        return response()->json([
            'message' => 'Active inventory serials retrieved successfully',
            'exists'  => true,
            'data'    => $data,
        ]);

    } catch (\Exception $e) {
        \Log::error('Inventory getAllActiveSerials error: ' . $e->getMessage());

        return response()->json([
            'message' => 'Failed to retrieve active inventory serials',
            'error'   => $e->getMessage(),
        ], 500);
    }
}


public function deleteSerial($serial_no)
{
    try {
        \Log::info("🗑️ Deleting serial number: {$serial_no}");

        // Find by serial number
        $inventory = \App\Models\Inventory::where('serial_no', $serial_no)
            ->whereNull('deleted_at')
            ->first();

        if (!$inventory) {
            return response()->json([
                'message' => "Serial number {$serial_no} not found or already deleted.",
                'status'  => false,
            ], 404);
        }

        // Soft delete
        $inventory->deleted_by = auth()->id() ?? null;
        $inventory->deleted_at = now();
        $inventory->save();

        return response()->json([
            'message' => "Serial number {$serial_no} deleted successfully.",
            'status'  => true,
        ], 200);
    } catch (\Exception $e) {
        \Log::error("❌ Inventory delete error: " . $e->getMessage());
        return response()->json([
            'message' => 'Failed to delete serial number.',
            'error'   => $e->getMessage(),
        ], 500);
    }
}

    public function destroy($id)
    {
        $item = Inventory::findOrFail($id);
        $item->deleted_by = auth()->id();
        $item->save();
        $item->delete();

        return response()->json(['message' => 'Inventory item deleted successfully']);
    }
// public function serialranges(Request $request)
// {
//     $query = Inventory::whereNull('deleted_at');

//     if ($request->filled('product_id')) {
//         $query->where('product_id', $request->product_id);
//     }

//     if ($request->filled('product_type_id')) {
//         $query->where('product_type_id', $request->product_type_id);
//     }

//     $items = $query->with(['product:id,name', 'productType:id,name'])->get();

//     // Group by product_id, product_type_id, from_serial, to_serial
//     $grouped = $items->groupBy(function ($item) {
//         return $item->product_id . '_' . $item->product_type_id . '_' . $item->from_serial . '_' . $item->to_serial;
//     });

//     $result = $grouped->map(function ($group) {
//         $fromSerial = $group[0]->from_serial;
//         $toSerial   = $group[0]->to_serial;
//         $productId  = $group[0]->product_id;
//         $typeId     = $group[0]->product_type_id;

//         // ✅ Count how many serials actually exist in DB between from and to
//         $serialCount = Inventory::where('product_id', $productId)
//             ->where('product_type_id', $typeId)
//             ->whereNull('deleted_at')
//             ->whereBetween('serial_no', [$fromSerial, $toSerial])
//             ->count();

//         return [
//             'from_serial'  => $fromSerial,
//             'to_serial'    => $toSerial,
//             'product'      => $group[0]->product,
//             'product_type' => $group[0]->productType,
//             'quantity'     => $serialCount, // ✅ actual count from DB
//             'items'        => $group->map(function ($item) {
//                 return [
//                     'id'            => $item->id,
//                     'serial_no'     => $item->serial_no,
//                     'tested_by'     => $item->tested_by,
//                     'tested_status' => $item->tested_status,
//                     'test_remarks'  => $item->test_remarks,
//                 ];
//             })->values(),
//         ];
//     })->values();

//     return response()->json($result);
// }
    




// public function serialrangeItems(Request $request, $from_serial, $to_serial)
// {
//     $from = min((int)$from_serial, (int)$to_serial);
//     $to   = max((int)$from_serial, (int)$to_serial);

//     // Fetch inventory items in the given range that are NOT deleted
//     $items = Inventory::whereNull('deleted_at')
//         ->whereBetween('serial_no', [$from_serial, $to_serial])
//         ->with(['product:id,name', 'productType:id,name'])
//         ->orderBy('serial_no')
//         ->get();

//     if ($items->isEmpty()) {
//         return response()->json(['message' => 'No records found for this range.'], 404);
//     }

//     // Prepare response
//     $resultItems = $items->map(function ($item) {
//         return [
//             'serial_no'     => $item->serial_no,
//             'product_name'  => $item->product->name ?? null,
//             'product_type'  => $item->productType->name ?? null,
//             'tested_by'     => $item->tested_by,
//             'tested_status' => $item->tested_status,
//             'test_remarks'  => $item->test_remarks,
//             'tested_date'   => $item->tested_date
//                 ? \Carbon\Carbon::parse($item->tested_date)->format('Y-m-d')
//                 : null,
//         ];
//     });

//     $first = $items->first();

//     return response()->json([
//         'from_serial'      => $from_serial,
//         'to_serial'        => $to_serial,
//         'product'          => $first->product,
//         'product_type'     => $first->productType,
//         'firmware_version' => $first->firmware_version,
//         'tested_date'      => $first->tested_date,
//         'quantity'         => $resultItems->count(),
//         'items'            => $resultItems,
//     ]);
// }





    public function updateSerialRange(Request $request, $from_serial, $to_serial)
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', function($attribute, $value, $fail) {
                if (!\App\Models\Product::where('id', $value)->whereNull('deleted_at')->exists()) {
                    $fail("Invalid product.");
                }
            }],
            // 'product_type_id' => ['required', 'integer', function($attribute, $value, $fail) {
            //     if (!\App\Models\ProductType::where('id', $value)->whereNull('deleted_at')->exists()) {
            //         $fail("Invalid product type.");
            //     }
            // }],
            'items' => 'required|array|min:1',
            'items.*.from_serial' => 'required|string|max:100',
            'items.*.to_serial' => 'required|string|max:100',
            'items.*.serial_no' => 'nullable|string',
            'items.*.tested_by' => 'nullable|string|max:255',
            'items.*.tested_status' => 'nullable|string',
            'items.*.test_remarks' => 'nullable|string',
            'items.*.quantity' => 'nullable|integer|min:1',
            'firmware_version' => 'nullable|string|max:100',
            'tested_date' => 'nullable|date',
        ]);

        $userId = auth()->id() ?? 1;

        $inputSerials = collect($validated['items'])
            ->pluck('serial_no')
            ->filter()
            ->toArray();

        $toDelete = \App\Models\Inventory::where('from_serial', '<=', $from_serial)
            ->where('to_serial', '>=', $to_serial)
            ->when(!empty($inputSerials), function ($query) use ($inputSerials) {
                $query->whereNotIn('serial_no', $inputSerials);
            })
            ->get();

        foreach ($toDelete as $item) {
            \App\Models\DeletedSerial::create([
                'inventory_id' => $item->id,
                'product_id' => $item->product_id,
                'product_type_id' => $item->product_type_id,
                'serial_no' => $item->serial_no,
                'deleted_at' => now(),
            ]);

            $item->forceDelete(); // remove from inventory
        }

        $updatedCount = 0;
        $createdCount = 0;
        $notPurchased = [];

        foreach ($validated['items'] as $itemData) {
            $serialNo = $itemData['serial_no'] ?? null;

            $isPurchased = false;
            if ($serialNo) {
                $isPurchased = \App\Models\SparepartPurchaseItem::where('product_id', $validated['product_id'])
                    ->where('serial_no', $serialNo)
                    ->exists();
            }

            if (!$isPurchased && $serialNo) {
                $notPurchased[] = $serialNo;
            }

            $existing = \App\Models\Inventory::where('serial_no', $serialNo)
                ->where('from_serial', '<=', $from_serial)
                ->where('to_serial', '>=', $to_serial)
                ->first();

            if ($existing) {
                $existing->update([
                    'product_id'       => $validated['product_id'],
                    // 'product_type_id'  => $validated['product_type_id'],
                    'firmware_version' => $validated['firmware_version'] ?? $existing->firmware_version,
                    'tested_date'      => $validated['tested_date'] ?? $existing->tested_date,
                    'tested_by'        => $itemData['tested_by'] ?? $existing->tested_by,
                    'tested_status'    => $itemData['tested_status'] ?? $existing->tested_status,
                    'test_remarks'     => $itemData['test_remarks'] ?? $existing->test_remarks,
                    'from_serial'      => $itemData['from_serial'],
                    'to_serial'        => $itemData['to_serial'],
                    'quantity'         => $itemData['quantity'] ?? $existing->quantity,
                    'updated_by'       => $userId,
                    'is_purchased'     => $isPurchased,
                ]);
                $updatedCount++;
            } else {
                \App\Models\Inventory::create([
                    'product_id'       => $validated['product_id'],
                    // 'product_type_id'  => $validated['product_type_id'],
                    'firmware_version' => $validated['firmware_version'] ?? null,
                    'tested_date'      => $validated['tested_date'] ?? null,
                    'serial_no'        => $serialNo,
                    'tested_by'        => $itemData['tested_by'] ?? null,
                    'tested_status'    => $itemData['tested_status'] ?? null,
                    'test_remarks'     => $itemData['test_remarks'] ?? null,
                    'from_serial'      => $itemData['from_serial'],
                    'to_serial'        => $itemData['to_serial'],
                    'quantity'         => $itemData['quantity'] ?? 1,
                    'updated_by'       => $userId,
                    'is_purchased'     => $isPurchased,
                ]);
                $createdCount++;
            }
        }

        // --- Response with purchase check status ---
        $message = 'Serial range updated successfully';
        $status = 'success';

        if (!empty($notPurchased)) {
            $message = 'Some serials were not found in purchase records.';
            $status = 'partial';
        }

        return response()->json([
            'status' => $status,
            'message' => $message,
            'updated_count' => $updatedCount,
            'created_count' => $createdCount,
            'not_purchased_serials' => $notPurchased,
            'from_serial' => $from_serial,
            'to_serial' => $to_serial,
        ]);
    }


// public function deleteSerial($serial_no)
// {
//     $serial = Inventory::where('from_serial', '<=', $serial_no)
//         ->where('to_serial', '>=', $serial_no)
//         ->whereNull('deleted_at')
//         ->first();

//     if (!$serial) {
//         return response()->json(['message' => 'Serial not found in any range'], 404);
//     }

//     // Record deleted serial
//     \DB::table('deleted_serials')->insert([
//         'inventory_id'   => $serial->id,
//         'product_id'     => $serial->product_id,
//         // 'product_type_id'=> $serial->product_type_id,
//         'serial_no'      => $serial_no,
//         'deleted_at'     => now(),
//     ]);

//     return response()->json(['message' => "Serial {$serial_no} deleted successfully."]);
// }




// public function deleteSerialRange(Request $request, $from_serial, $to_serial)
// {
//     // --- Validate request ---
//     $request->validate([
//         'product_id'      => 'required|integer',
//         // 'product_type_id' => 'required|integer',
//     ]);

//     $productId     = $request->product_id;
//     // $productTypeId = $request->product_type_id;
//     $userId        = Auth::id() ?? 1;

//     // --- Fetch matching items ---
//     $items = Inventory::whereNull('deleted_at')
//         ->where('product_id', $productId)
//         // ->where('product_type_id', $productTypeId)
//         ->whereBetween('serial_no', [$from_serial, $to_serial])
//         ->get();

//     if ($items->isEmpty()) {
//         return response()->json([
//             'message' => 'No records found for this range.'
//         ], 404);
//     }

//     // --- Update deleted_by and soft delete ---
//     foreach ($items as $item) {
//         $item->deleted_by = $userId;
//         $item->save();
//         $item->delete(); // this triggers SoftDelete (sets deleted_at)
//     }

//     return response()->json([
//         'message'         => 'Serial range deleted successfully.',
//         'deleted_count'   => $items->count(),
//         'from_serial'     => $from_serial,
//         'to_serial'       => $to_serial,
//         'product_id'      => $productId,
//         // 'product_type_id' => $productTypeId,
//     ]);
// }

// public function countBySeries()
// {
//     // Exclude deleted items and sold items
//      $soldSerials = SaleItem::pluck('serial_no')->toArray();

//     $items = Inventory::whereNull('deleted_at')
//         ->whereNotIn('serial_no', $soldSerials)
//         ->get();

//     // Initialize counters
//     $seriesCounts = [
//         '0 Series' => 0,
//         '7 Series' => 0,
//         '8 Series' => 0,
//         '9 Series' => 0,
//     ];

//     foreach ($items as $item) {
//         // Determine series from the first character of serial_no
//         $firstChar = substr($item->serial_no, 0, 1);

//         switch ($firstChar) {
//             case '0':
//                 $seriesCounts['0 Series'] += $item->quantity;
//                 break;
//             case '7':
//                 $seriesCounts['7 Series'] += $item->quantity;
//                 break;
//             case '8':
//                 $seriesCounts['8 Series'] += $item->quantity;
//                 break;
//             case '9':
//                 $seriesCounts['9 Series'] += $item->quantity;
//                 break;
//         }
//     }

//     // Total VCI count (sum of all series)
//     $total = array_sum($seriesCounts);

//     return response()->json([
//         'message'       => 'Product count by series',
//         'total_vcis'    => $total,
//         'series_counts' => $seriesCounts,
//     ]);
// }

// public function getMissingSerials($from_serial, $to_serial)
// {
//     if (!is_numeric($from_serial) || !is_numeric($to_serial)) {
//         return response()->json(['message' => 'Serial range must be numeric.'], 400);
//     }

//     if ((int)$from_serial > (int)$to_serial) {
//         return response()->json(['message' => 'Invalid range: from_serial cannot be greater than to_serial.'], 400);
//     }

//     $from_serial = (int)$from_serial;
//     $to_serial = (int)$to_serial;

//     $fullRange = range($from_serial, $to_serial);

//     $productName = '-';
//     $productTypeName = 'vci'; // always VCI as per your request

//     $firstDigit = substr((string)$from_serial, 0, 1);
//     switch ($firstDigit) {
//         case '5':
//             $productName = '5-series';
//             break;
//         case '7':
//             $productName = '7-series';
//             break;
//         case '8':
//             $productName = '8-series';
//             break;
//         case '9':
//             $productName = '9-series';
//             break;
//         case '0':
//             $productName = '0-series';
//             break;
//         default:
//             $productName = 'Unknown Series';
//     }

//     $existingSerials = Inventory::whereNull('deleted_at')
//         ->whereBetween('serial_no', [$from_serial, $to_serial])
//         ->with(['product:id,name', 'productType:id,name'])
//         ->get(['serial_no', 'product_id', 'product_type_id'])
//         ->keyBy('serial_no');

//     $deletedSerials = \DB::table('deleted_serials')
//         ->leftJoin('product', 'deleted_serials.product_id', '=', 'product.id')
//         ->leftJoin('product_type', 'deleted_serials.product_type_id', '=', 'product_type.id')
//         ->whereBetween('deleted_serials.serial_no', [$from_serial, $to_serial])
//         ->select(
//             'deleted_serials.serial_no',
//             'deleted_serials.product_id',
//             'deleted_serials.product_type_id',
//             'product.name as product_name',
//             'product_type.name as product_type_name'
//         )
//         ->get()
//         ->keyBy('serial_no');

//     $missingSerials = [];

//     foreach ($fullRange as $serial) {
//         if (isset($existingSerials[$serial])) continue;

//         $deleted = $deletedSerials[$serial] ?? null;

//         $missingSerials[] = [
//             'serial_no' => $serial,
//             'product' => [
//                 'name' => $deleted->product_name ?? $productName,
//             ],
//             'product_type' => [
//                 'name' => $deleted->product_type_name ?? $productTypeName,
//             ],
//         ];
//     }

//     usort($missingSerials, fn($a, $b) => $a['serial_no'] <=> $b['serial_no']);

//     return response()->json([
//         'message' => 'Missing or deleted serials in the range.',
//         'from_serial' => $from_serial,
//         'to_serial' => $to_serial,
//         'series' => $productName,
//         'product_type' => $productTypeName,
//         'missing_count' => count($missingSerials),
//         'missing_serials' => $missingSerials,
//     ]);
// }




public function checkSerialsPurchased(Request $request)
{
    $request->validate([
        'serials' => 'required|array',
        'serials.*' => 'required|string',
        'product_id' => 'required|integer',
    ]);

    $serials = array_map('trim', $request->serials);
    $productId = $request->product_id;
    $assembleCount = count($serials);


    $purchasedSerials = SparepartPurchaseItem::whereIn('serial_no', $serials)
        ->pluck('serial_no')
        ->map(fn($s) => trim($s))
        ->toArray();

    $existingInventorySerials = Inventory::whereIn('serial_no', $serials)
        ->where('product_id', $productId)
        ->pluck('serial_no')
        ->map(fn($s) => trim($s))
        ->toArray();

    $notPurchasedSerials = array_diff($serials, $purchasedSerials);

    $items = [];
    foreach ($serials as $s) {
        if (in_array($s, $existingInventorySerials)) {
            $items[] = [
                'serial_no' => $s,
                'status' => 'exists',
                'message' => 'Already exists in inventory.',
            ];
        } elseif (in_array($s, $notPurchasedSerials)) {
            $items[] = [
                'serial_no' => $s,
                'status' => 'not_purchased',
                'message' => 'Serial not purchased.',
            ];
        } else {
            $items[] = [
                'serial_no' => $s,
                'status' => 'purchased',
                'message' => 'Serial purchased and available to add.',
            ];
        }
    }


    $product = Product::find($productId);
    $requirements = $product->sparepart_requirements ?? [];

    $shortages = [];

    foreach ($requirements as $req) {

        $sparepartId = $req['id'];
        $requiredPerProduct = $req['required_quantity'];
        $neededTotal = $requiredPerProduct * $assembleCount;

        $purchasedQty = DB::table('sparepart_purchase_items')
            ->where('sparepart_id', $sparepartId)
            ->whereNull('deleted_by')
            ->whereNull('deleted_at')
            ->sum('quantity');

        if ($purchasedQty <= 0) continue;

        $assembledCount = DB::table('inventory')
            ->where('product_id', $productId)
            ->whereNull('deleted_by')
            ->whereNull('deleted_at')
            ->count();

        $usedQty = $assembledCount * $requiredPerProduct;

        $availableQty = max($purchasedQty - $usedQty, 0);

        if ($availableQty < $neededTotal) {
            $shortages[] = [
                'sparepart_id'   => $sparepartId,
                'sparepart_name' => Sparepart::find($sparepartId)->name,
                'required'       => $neededTotal,
                'available'      => $availableQty,
                'shortage'       => $neededTotal - $availableQty,
            ];
        }
    }


    return response()->json([
        'serial_validation' => [
            'purchased'       => array_values(array_diff($purchasedSerials, $existingInventorySerials)),
            'not_purchased'   => array_values($notPurchasedSerials),
            'items'           => $items,
        ],
        'sparepart_shortages' => $shortages,
        'can_assemble'        => count($shortages) === 0,
        'attempted_quantity'  => $assembleCount,
    ]);
}


public function availableProductCount()
{
    $soldSerials = \App\Models\SaleItem::pluck('serial_no')->toArray();

    $count = \App\Models\Inventory::whereNull('deleted_at')
        ->whereNotIn('serial_no', $soldSerials)
        ->count();

    return response()->json([
        'success' => true,
        'message' => 'Available product count fetched successfully',
        'count' => $count,
    ]);
}


}
