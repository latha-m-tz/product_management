<?php

namespace App\Http\Controllers;

use App\Models\Assemble;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AssembleController extends Controller
{
    // Get all assembles with product + product type
    public function index()
    {
        $assembles = Assemble::whereNull('deleted_at')
            ->with(['product.productTypes'])
            ->get();

        return response()->json($assembles);
    }

    // Get single assemble with product + product type
    public function show($id)
    {
        $assemble = Assemble::where('id', $id)
            ->whereNull('deleted_at')
            ->with(['product.productTypes', 'sparepartItem'])
            ->first();

        if (!$assemble) {
            return response()->json(['error' => 'Not found'], 404);
        }

        return response()->json($assemble);
    }
// Create new assemble
public function store(Request $request)
{
    $validated = $request->validate([
        'product_id' => 'required|exists:product,id',
        'product_type_id' => 'required|exists:product_type,id',
        // 'sparepart_item_id' => 'required|exists:sparepart_purchase_items,id',
        'serial_no' => 'required|string|max:100|unique:assemble,serial_no',
        'tested_status' => 'nullable|in:PASS,FAIL,IN_PROGRESS,PENDING',
    ]);

    $validated['created_by'] = Auth::id() ?? 1;

    $assemble = Assemble::create($validated);

    return response()->json(
        $assemble->load(['product', 'productType']), // return with relations
        201
    );
}

    // Update assemble
    public function update(Request $request, $id)
    {
        $assemble = Assemble::where('id', $id)->whereNull('deleted_at')->first();

        if (!$assemble) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $validated = $request->validate([
            'product_id' => 'required|exists:product,id',
            'sparepart_item_id' => 'required|exists:sparepart_purchase_items,id',
            'serial_no' => 'required|string|max:100|unique:assemble,serial_no,' . $id,
            'tested_status' => 'nullable|in:PASS,FAIL,IN_PROGRESS,PENDING',
        ]);

        $validated['updated_by'] = Auth::id() ?? 1;

        $assemble->update($validated);

        return response()->json($assemble);
    }

    // Soft delete assemble
    public function destroy($id)
    {
        $assemble = Assemble::where('id', $id)->whereNull('deleted_at')->first();

        if (!$assemble) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $assemble->deleted_by = Auth::id() ?? 1;
        $assemble->save();
        $assemble->delete();

        return response()->json(['message' => 'Assemble deleted successfully']);
    }
}
