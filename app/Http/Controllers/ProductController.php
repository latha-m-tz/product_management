<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Product;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function index()
    {
        return Product::whereNull('deleted_at')
                      ->with('productTypes') // because Product hasMany ProductType
                      ->get();
        
    }

    public function show($id)
    {
        $product = Product::where('id', $id)
                          ->whereNull('deleted_at')
                          ->with('productTypes')
                          ->first();

        if (!$product) {
            return response()->json(['error' => 'Not found'], 404);
        }

        return response()->json($product);
    }

    // Create new product
    public function store(Request $request)
{
    $validated = $request->validate([
        'name' => [
            'required',
            function ($attribute, $value, $fail) {

        
                $exists = Product::where('name', $value)
                    ->whereNull('deleted_at') 
                    ->exists();

                //      Log::info('is Exists', [
                //     'exists' => $exists,
                // ]);

                if ($exists) {
                    $fail("The $attribute has already been taken.");
                }
            },
        ],
    ]);

    $validated['created_by'] = auth()->id() ?? 1;

    $product = Product::create($validated);

    return response()->json($product, 201);
}

  public function update(Request $request, $id)
{
    $product = Product::where('id', $id)->whereNull('deleted_at')->first();

    if (!$product) {
        return response()->json(['error' => 'Not found'], 404);
    }

    $validated = $request->validate([
        'name' => [
            'required',
            Rule::unique('product', 'name')
                ->ignore($id) // exclude current product
                ->whereNull('deleted_at'), // respect soft delete
        ],
    ]);

    $validated['updated_by'] = Auth::id() ?? 1;

    $product->update($validated);

    return response()->json($product);
}



    // Soft delete product
    public function destroy($id)
    {
        $product = Product::where('id', $id)->whereNull('deleted_at')->first();

        if (!$product) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $product->deleted_by = Auth::id() ?? 1;
        $product->save();
        $product->delete();

        return response()->json(['message' => 'Product deleted successfully']);
    }
public function getProductCount()
{
    try {
        $totalCount = Product::whereNull('deleted_at')->count();

        return response()->json([
            'success' => true,
            'data' => [], // no grouping by series
            'total_count' => $totalCount,
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Failed to fetch product count',
            'error' => $e->getMessage(),
        ], 500);
    }
}

// Get all product types for a given product
public function getTypesByProduct($productId)
{
    $product = \App\Models\Product::where('id', $productId)
        ->whereNull('deleted_at')
        ->with(['productTypes' => function ($query) {
            $query->whereNull('deleted_at'); // only active types
        }])
        ->first();

    if (!$product) {
        return response()->json(['error' => 'Product not found'], 404);
    }

    return response()->json([
        'product_id' => $product->id,
        'product_name' => $product->name,
        'product_types' => $product->productTypes
    ]);
}

}
