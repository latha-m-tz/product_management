<?php

namespace App\Http\Controllers;

use App\Models\Inventory;
use Illuminate\Http\Request;
use App\Models\SaleItem;

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
        $serials = Inventory::with('product:id,name')
        ->select('id', 'serial_no','product_id')
        ->get()
        ->map(function ($item) {
            return [
                'id'         => $item->id,
                'serial_no'  => $item->serial_no,
                'product'    => $item->product ? $item->product->name : null,
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
   $validated = $request->validate([
    'product_id'         => 'required|integer|exists:product,id',
    'product_type_id' => 'required|integer|exists:product_type,id',
    'firmware_version'   => 'required|string|max:100',
    'tested_date'        => 'required|date',
    'items'              => 'required|array|min:1',
    'items.*.serial_no'  => 'required|string|unique:inventory,serial_no',
    'items.*.tested_by'  => 'required|string|max:255',
    'items.*.tested_status' => 'required|string|in:PASS,FAIL',
    'items.*.test_remarks'  => 'nullable|string',
   'items.*.from_serial' => 'required|string|max:100',
'items.*.to_serial'   => 'required|string|max:100',
'items.*.quantity'    => 'required|integer|min:1',
]);

foreach ($validated['items'] as $item) {
    Inventory::create([
        'product_id'       => $validated['product_id'],
        'product_type_id'  => $validated['product_type_id'],
        'firmware_version' => $validated['firmware_version'],
        'tested_date'      => $validated['tested_date'],
        'serial_no'        => $item['serial_no'],
        'tested_by'        => $item['tested_by'],
        'tested_status'    => $item['tested_status'],
        'test_remarks'     => $item['test_remarks'] ?? null,
        'from_serial'      => $item['from_serial'],
        'to_serial'        => $item['to_serial'],
        'quantity'         => $item['quantity'],
        'created_by'       => auth()->id(),
    ]);
}



    return response()->json(['message' => 'Inventory added successfully']);
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

    // Group by from_serial and to_serial
    $grouped = $items->groupBy(function ($item) {
        return $item->from_serial . '_' . $item->to_serial;
    });

    $result = $grouped->map(function ($group) {
        return [
            'from_serial' => $group[0]->from_serial,
            'to_serial'   => $group[0]->to_serial,
            'product'     => $group[0]->product,
            'product_type'=> $group[0]->productType,
            'quantity'    => $group->sum('quantity'),
            
            'items'       => $group->map(function($item) {
                return [
                    'id'           => $item->id,
                    'serial_no'    => $item->serial_no,
                    'tested_by'    => $item->tested_by,
                    'tested_status'=> $item->tested_status,
                    'test_remarks' => $item->test_remarks,
                ];
            }),
        ];
    })->values();

    return response()->json($result);
}


public function serialrangeItems(Request $request, $from_serial, $to_serial)
{
    // Fetch items for the given range from DB
    $items = Inventory::whereNull('deleted_at')
        ->where('from_serial', '<=', $from_serial)
        ->where('to_serial', '>=', $to_serial)
        ->with(['product:id,name', 'productType:id,name'])
        ->get();

    if ($items->isEmpty()) {
        return response()->json([
            'message' => 'No records found for this range.'
        ], 404);
    }

    $first = $items->first();

    // Map DB items directly
    $mappedItems = $items->map(function ($item) {
        return [
            'id'           => $item->id,
            'serial_no'    => $item->serial_no,
            'tested_by'    => $item->tested_by,
            'tested_status'=> $item->tested_status,
            'test_remarks' => $item->test_remarks,
        ];
    });

    return response()->json([
        'from_serial'     => $from_serial,
        'to_serial'       => $to_serial,
        'product'         => $first->product,
        'product_type'    => $first->productType,
        'firmware_version'=> $first->firmware_version,
        'quantity'        => $mappedItems->count(), // Only count actual DB items
        'items'           => $mappedItems,
    ]);
}

public function updateSerialRange(Request $request, $from_serial, $to_serial)
{
    $validated = $request->validate([
        'product_id'       => 'required|integer|exists:product,id',
        'product_type_id'  => 'required|integer|exists:product_type,id',
        'firmware_version' => 'required|string|max:100',
        'items'            => 'required|array|min:1',
        'items.*.serial_no'    => 'required|string',
        'items.*.tested_by'    => 'required|string|max:255',
        'items.*.tested_status'=> 'required|string|in:PASS,FAIL',
        'items.*.test_remarks' => 'nullable|string',
        'items.*.from_serial'  => 'required|string|max:100',
        'items.*.to_serial'    => 'required|string|max:100',
        'items.*.quantity'     => 'required|integer|min:1',
        'tested_date'      => 'required|date',
        'test_remarks'     => 'nullable|string',
    ]);

    $inputSerials = collect($validated['items'])->pluck('serial_no')->toArray();

    // Delete any serials in DB in the range but not in the input
    Inventory::where('from_serial', '<=', $from_serial)
        ->where('to_serial', '>=', $to_serial)
        ->whereNotIn('serial_no', $inputSerials)
        ->get()
        ->each(function($item) {
            $item->forceDelete();
        });

    $updatedCount = 0;
    $createdCount = 0;

    foreach ($validated['items'] as $itemData) {
        $item = Inventory::where('serial_no', $itemData['serial_no'])
            ->where('from_serial', '<=', $from_serial)
            ->where('to_serial', '>=', $to_serial)
            ->first();

        if ($item) {
            // Update existing item
            $item->update([
                'product_id'       => $validated['product_id'],
                'product_type_id'  => $validated['product_type_id'],
                'firmware_version' => $validated['firmware_version'],
                'tested_date'      => $validated['tested_date'],
                'tested_by'        => $itemData['tested_by'],
                'tested_status'    => $itemData['tested_status'],
                'test_remarks'     => $itemData['test_remarks'] ?? null,
                'from_serial'      => $itemData['from_serial'],
                'to_serial'        => $itemData['to_serial'],
                'quantity'         => $itemData['quantity'],
                'updated_by'       => auth()->id(),
            ]);
            $updatedCount++;
        } else {
            // Create new item
            Inventory::create([
                'product_id'       => $validated['product_id'],
                'product_type_id'  => $validated['product_type_id'],
                'firmware_version' => $validated['firmware_version'],
                'tested_date'      => $validated['tested_date'],
                'serial_no'        => $itemData['serial_no'],
                'tested_by'        => $itemData['tested_by'],
                'tested_status'    => $itemData['tested_status'],
                'test_remarks'     => $itemData['test_remarks'] ?? null,
                'from_serial'      => $itemData['from_serial'],
                'to_serial'        => $itemData['to_serial'],
                'quantity'         => $itemData['quantity'],
                'created_by'       => auth()->id(),
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
public function deleteSerialRange(Request $request, $from_serial, $to_serial)
{
    // Validate request
    $request->validate([
        'product_id'      => 'required|integer',
        'product_type_id' => 'required|integer',
    ]);

    $productId     = $request->product_id;
    $productTypeId = $request->product_type_id;

    // Fetch items in the range first
    $items = Inventory::whereNull('deleted_at')
        ->where('product_id', $productId)
        ->where('product_type_id', $productTypeId)
        ->where('serial_no', '>=', $from_serial)
        ->where('serial_no', '<=', $to_serial)
        ->get();

    if ($items->isEmpty()) {
        return response()->json([
            'message' => 'No records found for this range.'
        ], 404);
    }

    // Delete items
    $deletedCount = Inventory::where('product_id', $productId)
        ->where('product_type_id', $productTypeId)
        ->where('serial_no', '>=', $from_serial)
        ->where('serial_no', '<=', $to_serial)
        ->delete();

    return response()->json([
        'message' => 'Serial range deleted successfully.',
        'deleted' => $deletedCount,
        'from_serial' => $from_serial,
        'to_serial'   => $to_serial,
        'product_id'  => $productId,
        'product_type_id' => $productTypeId,
    ]);
}









}

