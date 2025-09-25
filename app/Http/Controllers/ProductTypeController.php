<?php

namespace App\Http\Controllers;

use App\Models\ProductType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Log;

class ProductTypeController extends Controller
{
    // Get all product types with product
    public function index()
    {
        return ProductType::whereNull('deleted_at')->with('product')->get();
    }

    // Get single product type
    public function show($id)
    {
        $type = ProductType::where('id', $id)
            ->whereNull('deleted_at')
            ->with('product')
            ->first();

        if (!$type) {
            return response()->json(['error' => 'Not found'], 404);
        }

        return response()->json($type);
    }

    // Create product type
      public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                function ($attribute, $value, $fail) use ($request) {
                    $exists = ProductType::where('name', $value)
                        ->where('product_id', $request->product_id)
                        ->whereNull('deleted_at')
                        ->exists();

                    Log::info('Store check duplicate', [
                        'exists' => $exists,
                        'product_id' => $request->product_id,
                    ]);

                    if ($exists) {
                        $fail("The $attribute has already been taken for this product.");
                    }
                },
            ],
            'product_id' => 'required|exists:product,id',
        ]);

        $validated['created_by'] = Auth::id() ?? 1;

        $type = ProductType::create($validated);

        return response()->json($type, 201);
    }

    // Update product type
    public function update(Request $request, $id)
    {
        $type = ProductType::where('id', $id)->whereNull('deleted_at')->first();

        if (!$type) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                function ($attribute, $value, $fail) use ($request, $id) {
                    $exists = ProductType::where('name', $value)
                        ->where('product_id', $request->product_id)
                        ->where('id', '!=', $id) // exclude current row
                        ->whereNull('deleted_at')
                        ->exists();

                    Log::info('Update check duplicate', [
                        'exists' => $exists,
                        'product_id' => $request->product_id,
                        'ignore_id' => $id,
                    ]);

                    if ($exists) {
                        $fail("The $attribute has already been taken for this product.");
                    }
                },
            ],
            'product_id' => 'required|exists:product,id',
        ]);

        $validated['updated_by'] = Auth::id() ?? 1;

        $type->update($validated);

        return response()->json($type);
    }


    // Soft delete product type
    public function destroy($id)
    {
        $type = ProductType::where('id', $id)->whereNull('deleted_at')->first();

        if (!$type) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $type->deleted_by = Auth::id() ?? 1;
        $type->save();
        $type->delete();

        return response()->json(['message' => 'Product type deleted successfully']);
    }
}
