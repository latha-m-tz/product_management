<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Models\Product;
use App\Models\Sparepart;

class ProductController extends Controller
{
    // ✅ Fetch all products with types, spareparts + required qty per product
public function index()
{
    $products = Product::whereNull('deleted_at')
        ->with(['productTypes'])
        ->get();

    $productsWithSpareparts = $products->map(function ($product) {
        $sparepartRequirements = $product->sparepart_requirements ?? [];

        $sparepartIds = collect($sparepartRequirements)->pluck('id')->toArray();
        $spareparts = Sparepart::whereIn('id', $sparepartIds)->get();

        $sparepartsMapped = $spareparts->map(function ($part) use ($sparepartRequirements) {
            $required = collect($sparepartRequirements)
                ->firstWhere('id', $part->id)['required_quantity'] ?? 0;

            $purchasedQty = DB::table('sparepart_purchase_items')
                ->where('sparepart_id', $part->id)
                ->sum('quantity');

            $assembledVCIs = DB::table('inventory')
                ->whereNull('deleted_by')
                ->count();

            $usedQty = $assembledVCIs * $required;
            $availableQty = max($purchasedQty - $usedQty, 0);

            return [
                'id' => $part->id,
                'code' => $part->code,
                'name' => $part->name,
                'sparepart_type' => $part->sparepart_type,
                'sparepart_usages' => $part->sparepart_usages,
                'required_per_product' => $required,
                'available_quantity' => $availableQty,
                'used_quantity' => $usedQty,
            ];
        });

        // ✅ Prefer the DB column if relation is empty
        $typeName = $product->productTypes->isNotEmpty()
            ? $product->productTypes->pluck('name')->implode(', ')
            : ($product->product_type_name ?? '-');

        return [
            'id' => $product->id,
            'name' => $product->name,
            'requirement_per_product' => $product->requirement_per_product,
            'product_type_name' => $typeName,
            'product_types' => $product->productTypes,
            'spareparts' => $sparepartsMapped,
        ];
    });

    return response()->json($productsWithSpareparts, 200);
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

        $sparepartRequirements = $product->sparepart_requirements ?? [];
        $sparepartIds = collect($sparepartRequirements)->pluck('id')->toArray();
        $spareparts = Sparepart::whereIn('id', $sparepartIds)->get();

        $sparepartsMapped = $spareparts->map(function ($part) use ($sparepartRequirements) {
            $required = collect($sparepartRequirements)
                ->firstWhere('id', $part->id)['required_quantity'] ?? 0;

            $purchasedQty = DB::table('sparepart_purchase_items')
                ->where('sparepart_id', $part->id)
                ->sum('quantity');

            $assembledVCIs = DB::table('inventory')
                ->whereNull('deleted_by')
                ->count();

            $usedQty = $assembledVCIs * $required;
            $availableQty = max($purchasedQty - $usedQty, 0);

            return [
                'id' => $part->id,
                'code' => $part->code,
                'name' => $part->name,
                'sparepart_type' => $part->sparepart_type,
                'sparepart_usages' => $part->sparepart_usages,
                'required_per_product' => $required,
                'available_quantity' => $availableQty,
                'used_quantity' => $usedQty,
            ];
        });

        return response()->json([
            'id' => $product->id,
            'name' => $product->name,
            'requirement_per_product' => $product->requirement_per_product,
            'product_type_name' => $product->product_type_name,
            'product_types' => $product->productTypes,
            'spareparts' => $sparepartsMapped,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => [
                'required',
                function ($attribute, $value, $fail) {
                    $exists = Product::where('name', $value)
                        ->whereNull('deleted_at')
                        ->exists();
                    if ($exists) {
                        $fail("The $attribute has already been taken.");
                    }
                },
            ],
            'requirement_per_product' => 'nullable|numeric|min:0',
            'product_type_name' => 'nullable|string|max:255',
            'sparepart_requirements' => 'nullable|array', // [{id, required_quantity}]
        ]);

        $product = Product::create([
            'name' => $validated['name'],
            'requirement_per_product' => $validated['requirement_per_product'] ?? 0,
            'product_type_name' => $validated['product_type_name'] ?? null,
            'sparepart_requirements' => $validated['sparepart_requirements'] ?? [],
            'created_by' => Auth::id(),
        ]);

        return response()->json([
            'message' => 'Product created successfully',
            'data' => $product,
        ], 201);
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
            Rule::unique('product', 'name')->ignore($id)->whereNull('deleted_at'),
        ],
        'requirement_per_product' => 'nullable|numeric|min:0',
        'product_type_name' => 'nullable|string|max:255',
        'sparepart_requirements' => 'nullable|array',
    ]);

    $product->update([
        'name' => $validated['name'],
        'requirement_per_product' => $validated['requirement_per_product'] ?? 0,
        // ✅ simpler & safer assignment
        'product_type_name' => $validated['product_type_name'] ?? null,
        'sparepart_requirements' => $validated['sparepart_requirements'] ?? [],
        'updated_by' => Auth::id() ?? 1,
    ]);

    return response()->json([
        'message' => 'Product updated successfully',
        'data' => $product,
    ]);
}


    // ✅ Soft delete
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

    // ✅ Product count
    public function getProductCount()
    {
        try {
            $totalCount = Product::whereNull('deleted_at')->count();

            return response()->json([
                'success' => true,
                'data' => [],
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

    // ✅ Get types by product
    public function getTypesByProduct($productId)
    {
        $product = Product::where('id', $productId)
            ->whereNull('deleted_at')
            ->with(['productTypes' => function ($query) {
                $query->whereNull('deleted_at');
            }])
            ->first();

        if (!$product) {
            return response()->json(['error' => 'Product not found'], 404);
        }

        return response()->json([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_types' => $product->productTypes,
        ]);
    }
}
