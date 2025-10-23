<?php

namespace App\Http\Controllers;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\DeletedSerial;
use Illuminate\Http\Request;
use App\Models\SaleItem;
use Illuminate\Support\Facades\Auth;

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

        // Exclude sold items
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

    public function show($id)
    {
        $item = Inventory::with(['product', 'tester', 'productType'])->findOrFail($id);
        return response()->json($item);
    }
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
            'product_type_id' => [
                'required', 'integer',
                function ($attribute, $value, $fail) {
                    if (!\App\Models\ProductType::where('id', $value)->whereNull('deleted_at')->exists()) {
                        $fail("Invalid product type.");
                    }
                },
            ],
            'firmware_version' => 'nullable|string|max:100',
            'tested_date'      => 'nullable|date',
            'items'            => 'required|array|min:1',
            'items.*.serial_no' => 'required|string|max:100',
            'items.*.tested_by'    => 'nullable|string|max:100',
            'items.*.tested_status'=> 'nullable|string',
            'items.*.test_remarks' => 'nullable|string|max:255',
            'items.*.from_serial'  => 'nullable|string|max:100',
            'items.*.to_serial'    => 'nullable|string|max:100',
            'items.*.quantity'     => 'nullable|integer|min:1',
            'items.*.tested_date'  => 'nullable|date',
        ]);

        $userId = Auth::id() ?? 1;
        $results = [];

        foreach ($validated['items'] as $item) {
            $serial = $item['serial_no'];

            // 1️⃣ Check if serial is purchased for this product
            $isPurchased = \App\Models\SparepartPurchaseItem::where('product_id', $validated['product_id'])
                ->where('serial_no', $serial)
                ->exists();

            if (!$isPurchased) {
                $results[] = [
                    'serial_no' => $serial,
                    'status' => 'not_purchased',
                    'message' => 'This serial is not purchased for the selected product.',
                ];
                continue; // Skip adding to inventory
            }

            // 2️⃣ Skip if marked deleted
            $isDeletedSerial = \App\Models\DeletedSerial::where('serial_no', $serial)
                ->where('product_id', $validated['product_id'])
                ->where('product_type_id', $validated['product_type_id'])
                ->exists();

            if ($isDeletedSerial) {
                $results[] = [
                    'serial_no' => $serial,
                    'status' => 'skipped',
                    'message' => 'Marked deleted — skipped.',
                ];
                continue;
            }

            // 3️⃣ Skip if already in inventory
            $exists = \App\Models\Inventory::where('product_id', $validated['product_id'])
                ->where('product_type_id', $validated['product_type_id'])
                ->where('serial_no', $serial)
                ->whereNull('deleted_at')
                ->exists();

            if ($exists) {
                $results[] = [
                    'serial_no' => $serial,
                    'status' => 'exists',
                    'message' => 'Already exists in inventory.',
                ];
                continue;
            }

            // 4️⃣ Add to inventory
            $inventory = \App\Models\Inventory::create([
                'product_id'       => $validated['product_id'],
                'product_type_id'  => $validated['product_type_id'],
                'firmware_version' => $validated['firmware_version'] ?? null,
                'tested_date'      => $item['tested_date'] ?? $validated['tested_date'] ?? null,
                'serial_no'        => $serial,
                'tested_by'        => $item['tested_by'] ?? null,
                'tested_status'    => $item['tested_status'] ?? 'PASS',
                'test_remarks'     => $item['test_remarks'] ?? null,
                'from_serial'      => $item['from_serial'] ?? $serial,
                'to_serial'        => $item['to_serial'] ?? $serial,
                'quantity'         => $item['quantity'] ?? 1,
                'created_by'       => $userId,
            ]);

            $results[] = [
                'serial_no' => $serial,
                'status' => 'added',
                'message' => 'Inventory item added successfully.',
            ];
        }

        return response()->json([
            'message' => 'Inventory process completed',
            'product_id' => $validated['product_id'],
            'product_type_id' => $validated['product_type_id'],
            'firmware_version' => $validated['firmware_version'] ?? null,
            'tested_date' => $validated['tested_date'] ?? null,
            'items' => $results,
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
            'error' => $e->getMessage(),
        ], 500);
    }
}


public function update(Request $request, $id)
{
    $item = Inventory::findOrFail($id);

 $validated = $request->validate([
    'product_id'       => 'required|integer|exists:product,id',
    'product_type_id'  => 'required|integer|exists:product_type,id', 
    'serial_no'        => 'required|string|max:100|unique:inventory,serial_no,' . $id,
    'firmware_version' => 'required|string|max:100',
    'tested_status'    => 'required|string|in:PASS,FAIL,IN_PROGRESS,PENDING',
    'tested_by'        => 'required|string|max:255',
    'tested_date'      => 'required|date',
    'test_remarks'     => 'nullable|string',
    'from_serial'      => 'required|string|max:100',
    'to_serial'        => 'required|string|max:100',
    'quantity'         => 'required|integer|min:1',
]);

$validated['updated_by'] = auth()->id();

$item->update($validated);

    return response()->json([
        'message' => 'Inventory item updated successfully',
        'data'    => $item
    ]);
}


    public function destroy($id)
    {
        $item = Inventory::findOrFail($id);
        $item->deleted_by = auth()->id();
        $item->save();
        $item->delete();

        return response()->json(['message' => 'Inventory item deleted successfully']);
    }
public function serialranges(Request $request)
{
    $query = Inventory::whereNull('deleted_at');

    if ($request->filled('product_id')) {
        $query->where('product_id', $request->product_id);
    }

    if ($request->filled('product_type_id')) {
        $query->where('product_type_id', $request->product_type_id);
    }

    $items = $query->with(['product:id,name', 'productType:id,name'])->get();

    // Group by product_id, product_type_id, from_serial, to_serial
    $grouped = $items->groupBy(function ($item) {
        return $item->product_id . '_' . $item->product_type_id . '_' . $item->from_serial . '_' . $item->to_serial;
    });

    $result = $grouped->map(function ($group) {
        return [
            'from_serial'  => $group[0]->from_serial,
            'to_serial'    => $group[0]->to_serial,
            'product'      => $group[0]->product,
            'product_type' => $group[0]->productType,
            'quantity'     => $group->sum('quantity'),
            'items'        => $group->map(function($item) {
                return [
                    'id'            => $item->id,
                    'serial_no'     => $item->serial_no,
                    'tested_by'     => $item->tested_by,
                    'tested_status' => $item->tested_status,
                    'test_remarks'  => $item->test_remarks,
                ];
            }),
        ];
    })->values();

    return response()->json($result);
}



public function serialrangeItems(Request $request, $from_serial, $to_serial)
{
    $from = min((int)$from_serial, (int)$to_serial);
    $to   = max((int)$from_serial, (int)$to_serial);

    // Fetch inventory items in the given range that are NOT deleted
    $items = Inventory::whereNull('deleted_at')
        ->whereBetween('serial_no', [$from_serial, $to_serial])
        ->with(['product:id,name', 'productType:id,name'])
        ->orderBy('serial_no')
        ->get();

    if ($items->isEmpty()) {
        return response()->json(['message' => 'No records found for this range.'], 404);
    }

    // Prepare response
    $resultItems = $items->map(function ($item) {
        return [
            'serial_no'     => $item->serial_no,
            'product_name'  => $item->product->name ?? null,
            'product_type'  => $item->productType->name ?? null,
            'tested_by'     => $item->tested_by,
            'tested_status' => $item->tested_status,
            'test_remarks'  => $item->test_remarks,
            'tested_date'   => $item->tested_date
                ? \Carbon\Carbon::parse($item->tested_date)->format('Y-m-d')
                : null,
        ];
    });

    $first = $items->first();

    return response()->json([
        'from_serial'      => $from_serial,
        'to_serial'        => $to_serial,
        'product'          => $first->product,
        'product_type'     => $first->productType,
        'firmware_version' => $first->firmware_version,
        'tested_date'      => $first->tested_date,
        'quantity'         => $resultItems->count(),
        'items'            => $resultItems,
    ]);
}





public function updateSerialRange(Request $request, $from_serial, $to_serial)
{
    $validated = $request->validate([
        'product_id' => ['required', 'integer', function($attribute, $value, $fail) {
            if (!\App\Models\Product::where('id', $value)->whereNull('deleted_at')->exists()) {
                $fail("Invalid product.");
            }
        }],
        'product_type_id' => ['required', 'integer', function($attribute, $value, $fail) {
            if (!\App\Models\ProductType::where('id', $value)->whereNull('deleted_at')->exists()) {
                $fail("Invalid product type.");
            }
        }],
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

    // --- Delete inventory serials not in new input and track them in deleted_serials ---
    $toDelete = Inventory::where('from_serial', '<=', $from_serial)
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

    // --- Loop through items to update or create ---
    foreach ($validated['items'] as $itemData) {
        $existing = Inventory::where('serial_no', $itemData['serial_no'] ?? null)
            ->where('from_serial', '<=', $from_serial)
            ->where('to_serial', '>=', $to_serial)
            ->first();

        if ($existing) {
            $existing->update([
                'product_id'       => $validated['product_id'],
                'product_type_id'  => $validated['product_type_id'],
                'firmware_version' => $validated['firmware_version'] ?? $existing->firmware_version,
                'tested_date'      => $validated['tested_date'] ?? $existing->tested_date,
                'tested_by'        => $itemData['tested_by'] ?? $existing->tested_by,
                'tested_status'    => $itemData['tested_status'] ?? $existing->tested_status,
                'test_remarks'     => $itemData['test_remarks'] ?? $existing->test_remarks,
                'from_serial'      => $itemData['from_serial'],
                'to_serial'        => $itemData['to_serial'],
                'quantity'         => $itemData['quantity'] ?? $existing->quantity,
                'updated_by'       => $userId,
            ]);
            $updatedCount++;
        } else {
            Inventory::create([
                'product_id'       => $validated['product_id'],
                'product_type_id'  => $validated['product_type_id'],
                'firmware_version' => $validated['firmware_version'] ?? null,
                'tested_date'      => $validated['tested_date'] ?? null,
                'serial_no'        => $itemData['serial_no'] ?? null,
                'tested_by'        => $itemData['tested_by'] ?? null,
                'tested_status'    => $itemData['tested_status'] ?? null,
                'test_remarks'     => $itemData['test_remarks'] ?? null,
                'from_serial'      => $itemData['from_serial'],
                'to_serial'        => $itemData['to_serial'],
                'quantity'         => $itemData['quantity'] ?? 1,
                'updated_by'       => $userId,
            ]);
            $createdCount++;
        }
    }

    return response()->json([
        'message' => 'Serial range updated successfully',
        'updated_count' => $updatedCount,
        'created_count' => $createdCount,
        'from_serial' => $from_serial,
        'to_serial' => $to_serial,
    ]);
}


public function deleteSerial($serial_no)
{
    $serial = Inventory::where('from_serial', '<=', $serial_no)
        ->where('to_serial', '>=', $serial_no)
        ->whereNull('deleted_at')
        ->first();

    if (!$serial) {
        return response()->json(['message' => 'Serial not found in any range'], 404);
    }

    // Record deleted serial
    \DB::table('deleted_serials')->insert([
        'inventory_id'   => $serial->id,
        'product_id'     => $serial->product_id,
        'product_type_id'=> $serial->product_type_id,
        'serial_no'      => $serial_no,
        'deleted_at'     => now(),
    ]);

    return response()->json(['message' => "Serial {$serial_no} deleted successfully."]);
}




public function deleteSerialRange(Request $request, $from_serial, $to_serial)
{
    // --- Validate request ---
    $request->validate([
        'product_id'      => 'required|integer',
        'product_type_id' => 'required|integer',
    ]);

    $productId     = $request->product_id;
    $productTypeId = $request->product_type_id;
    $userId        = Auth::id() ?? 1;

    // --- Fetch matching items ---
    $items = Inventory::whereNull('deleted_at')
        ->where('product_id', $productId)
        ->where('product_type_id', $productTypeId)
        ->whereBetween('serial_no', [$from_serial, $to_serial])
        ->get();

    if ($items->isEmpty()) {
        return response()->json([
            'message' => 'No records found for this range.'
        ], 404);
    }

    // --- Update deleted_by and soft delete ---
    foreach ($items as $item) {
        $item->deleted_by = $userId;
        $item->save();
        $item->delete(); // this triggers SoftDelete (sets deleted_at)
    }

    return response()->json([
        'message'         => 'Serial range deleted successfully.',
        'deleted_count'   => $items->count(),
        'from_serial'     => $from_serial,
        'to_serial'       => $to_serial,
        'product_id'      => $productId,
        'product_type_id' => $productTypeId,
    ]);
}

public function countBySeries()
{
    // Exclude deleted items and sold items
     $soldSerials = SaleItem::pluck('serial_no')->toArray();

    $items = Inventory::whereNull('deleted_at')
        ->whereNotIn('serial_no', $soldSerials)
        ->get();

    // Initialize counters
    $seriesCounts = [
        '0 Series' => 0,
        '7 Series' => 0,
        '8 Series' => 0,
        '9 Series' => 0,
    ];

    foreach ($items as $item) {
        // Determine series from the first character of serial_no
        $firstChar = substr($item->serial_no, 0, 1);

        switch ($firstChar) {
            case '0':
                $seriesCounts['0 Series'] += $item->quantity;
                break;
            case '7':
                $seriesCounts['7 Series'] += $item->quantity;
                break;
            case '8':
                $seriesCounts['8 Series'] += $item->quantity;
                break;
            case '9':
                $seriesCounts['9 Series'] += $item->quantity;
                break;
        }
    }

    // Total VCI count (sum of all series)
    $total = array_sum($seriesCounts);

    return response()->json([
        'message'       => 'Product count by series',
        'total_vcis'    => $total,
        'series_counts' => $seriesCounts,
    ]);
}

public function getMissingSerials($from_serial, $to_serial)
{
    if (!is_numeric($from_serial) || !is_numeric($to_serial)) {
        return response()->json(['message' => 'Serial range must be numeric.'], 400);
    }

    if ((int)$from_serial > (int)$to_serial) {
        return response()->json(['message' => 'Invalid range: from_serial cannot be greater than to_serial.'], 400);
    }

    $from_serial = (int)$from_serial;
    $to_serial = (int)$to_serial;

    $fullRange = range($from_serial, $to_serial);

    // --- Identify the product name from the first serial ---
    $productName = '-';
    $productTypeName = 'vci'; // always VCI as per your request

    // Try to detect product series based on first serial (like 9-series)
    $firstDigit = substr((string)$from_serial, 0, 1);
    switch ($firstDigit) {
        case '5':
            $productName = '5-series';
            break;
        case '7':
            $productName = '7-series';
            break;
        case '8':
            $productName = '8-series';
            break;
        case '9':
            $productName = '9-series';
            break;
        case '0':
            $productName = '0-series';
            break;
        default:
            $productName = 'Unknown Series';
    }

    // --- Fetch existing inventory serials ---
    $existingSerials = Inventory::whereNull('deleted_at')
        ->whereBetween('serial_no', [$from_serial, $to_serial])
        ->with(['product:id,name', 'productType:id,name'])
        ->get(['serial_no', 'product_id', 'product_type_id'])
        ->keyBy('serial_no');

    // --- Fetch deleted serials ---
    $deletedSerials = \DB::table('deleted_serials')
        ->leftJoin('product', 'deleted_serials.product_id', '=', 'product.id')
        ->leftJoin('product_type', 'deleted_serials.product_type_id', '=', 'product_type.id')
        ->whereBetween('deleted_serials.serial_no', [$from_serial, $to_serial])
        ->select(
            'deleted_serials.serial_no',
            'deleted_serials.product_id',
            'deleted_serials.product_type_id',
            'product.name as product_name',
            'product_type.name as product_type_name'
        )
        ->get()
        ->keyBy('serial_no');

    // --- Build missing serials list ---
    $missingSerials = [];

    foreach ($fullRange as $serial) {
        if (isset($existingSerials[$serial])) continue;

        $deleted = $deletedSerials[$serial] ?? null;

        $missingSerials[] = [
            'serial_no' => $serial,
            'product' => [
                'name' => $deleted->product_name ?? $productName,
            ],
            'product_type' => [
                'name' => $deleted->product_type_name ?? $productTypeName,
            ],
        ];
    }

    usort($missingSerials, fn($a, $b) => $a['serial_no'] <=> $b['serial_no']);

    return response()->json([
        'message' => 'Missing or deleted serials in the range.',
        'from_serial' => $from_serial,
        'to_serial' => $to_serial,
        'series' => $productName,
        'product_type' => $productTypeName,
        'missing_count' => count($missingSerials),
        'missing_serials' => $missingSerials,
    ]);
}




}