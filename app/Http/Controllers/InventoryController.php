<?php

namespace App\Http\Controllers;

use App\Models\Inventory;
use Illuminate\Http\Request;
use App\Models\SaleItem;

class InventoryController extends Controller
{
     public function index(Request $request)
    {
        $query = Inventory::with(['product', 'tester']);

        if ($request->filled('serial_from')) {
            $query->where('serial_no', '>=', $request->serial_from);
        }
        if ($request->filled('serial_to')) {
            $query->where('serial_no', '<=', $request->serial_to);
        }

        if ($request->filled('tested_status')) {
            $query->where('tested_status', $request->tested_status);
        }

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->product_id);
        }


        $soldIds = SaleItem::pluck('testing_id')->toArray();
        if (!empty($soldIds)) {
            $query->whereNotIn('id', $soldIds);
        }

        $inventory = $query->paginate(10);

        return response()->json($inventory);
    }

    public function SerialNumbers()
    {
        $serials = Inventory::select('id', 'serial_no')->get();
        return response()->json($serials);
    }

    public function show($id)
    {
        $item = Inventory::with(['product', 'tester'])->findOrFail($id);
        return response()->json($item);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|integer|exists:product,id',
            'firmware_version' => 'required|string|max:100',
            'tested_date' => 'required|date',
            'items' => 'required|array|min:1',
            'items.*.serial_no' => 'required|string|unique:inventory,serial_no',
            'items.*.tested_by' => 'required|integer|exists:users,id',
            'items.*.tested_status' => 'required|string|in:PASS,FAIL',
            'items.*.test_remarks' => 'required|string',
        ]);

        foreach ($validated['items'] as $item) {
            Inventory::create([
                'product_id'       => $validated['product_id'],
                'firmware_version' => $validated['firmware_version'],
                'tested_date'      => $validated['tested_date'],
                'serial_no'        => $item['serial_no'],
                'tested_by'        => $item['tested_by'],
                'tested_status'    => $item['tested_status'],
                'test_remarks'     => $item['test_remarks'],
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
            'serial_no'        => 'required|string|max:100|unique:inventory,serial_no,' . $id,
            'firmware_version' => 'required|string|max:100',
            'tested_status'    => 'required|string|in:PASS,FAIL,IN_PROGRESS,PENDING',
            'tested_by'        => 'required|integer|exists:users,id',
            'tested_date'      => 'required|date',
            'test_remarks'     => 'required|string',
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