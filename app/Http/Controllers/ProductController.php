<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Product;
use Illuminate\Support\Facades\Auth;

class ProductController extends Controller
{
    // Get all products (with their types if needed)
    public function index()
    {
        // If you want product_types included:
        return Product::whereNull('deleted_at')
                      ->with('productTypes') // because Product hasMany ProductType
                      ->get();
        
        // If you want products only, remove ->with('productTypes')
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
            'name' => 'required|unique:product,name',
        ]);

        $validated['created_by'] = Auth::id() ?? 1;

        $product = Product::create($validated);

        return response()->json($product, 201);
    }

    // Update product
    public function update(Request $request, $id)
    {
        $product = Product::where('id', $id)->whereNull('deleted_at')->first();

        if (!$product) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $validated = $request->validate([
            'name' => 'required|unique:product,name,' . $id,
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
}
