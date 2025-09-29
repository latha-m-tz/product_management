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
}