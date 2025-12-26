<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Inventory;   
use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\QueryException;
class SalesController extends Controller
{
    public function index()
    {
        $sales = Sale::with([
            'customer:id,customer,email,mobile_no',
            'items:id,sale_id,serial_no,quantity,product_id',
            'items.inventory:id,serial_no,tested_status',
            'items.product:id,name'
        ])
        ->whereNull('deleted_at')
        ->orderBy('id', 'desc')
        ->get();

        return response()->json($sales);
    }

    public function customers()
    {
        return response()->json(
            Customer::whereNull('deleted_at')->get()
        );
    }

    public function addedSerials()
    {
        return response()->json(
            SaleItem::whereNull('deleted_at')->pluck('serial_no')->map('trim')
        );
    }

    public function getTestingData(Request $request)
    {
        $query = Inventory::with(['product', 'tester'])
            ->whereRaw('LOWER(TRIM(tested_status)) = ?', ['pass'])
            ->whereNull('deleted_at')
            ->whereNotIn('serial_no', function ($q) {
                $q->select('serial_no')->from('sale_items')->whereNull('deleted_at');
            });

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        if ($request->filled('serial_from')) {
            $query->where('serial_no', '>=', trim($request->serial_from));
        }

        if ($request->filled('serial_to')) {
            $query->where('serial_no', '<=', trim($request->serial_to));
        }

        return response()->json($query->get());
    }

    
public function store(Request $request)
{
    try {

        $validated = $request->validate([
            'customer_id' => [
                'required',
                function ($attribute, $value, $fail) {
                    $exists = Customer::where('id', $value)
                        ->whereNull('deleted_at')
                        ->exists();

                    if (!$exists) {
                        $fail("Customer not found.");
                    }
                },
            ],

            'challan_no' => [
                'required',
                function ($attribute, $value, $fail) {
                    if (Sale::where('challan_no', $value)->exists()) {
                        $fail("Challan No already exists.");
                    }
                }
            ],

            'challan_date'   => 'required|date|before_or_equal:today',
            'shipment_date'  => 'required|date|before_or_equal:today',
            'shipment_name'  => 'nullable|string',
            'notes'          => 'nullable|string',

            'existing_receipts'   => 'nullable|array',
            'existing_receipts.*' => 'string',

            'receipt_files'       => 'nullable|array',
            'receipt_files.*'     => 'file|mimes:jpg,jpeg,png,pdf|max:102400',

            'items'               => 'required|array|min:1',
            'items.*.quantity'    => 'required|integer|min:1',
            'items.*.serial_no'   => 'required|string',
        ]);

        return DB::transaction(function () use ($request, $validated) {

            $receiptFiles = [];

            if ($request->filled('existing_receipts')) {
                foreach ($request->input('existing_receipts') as $name) {
                    $receiptFiles[] = [
                        'file_name' => $name,
                        'file_path' => 'uploads/receipts/' . $name,
                    ];
                }
            }

            if ($request->hasFile('receipt_files')) {
                foreach ($request->file('receipt_files') as $file) {

                    if (!$file || !$file->isValid()) {
                        continue;
                    }

                    $storedPath = $file->store('uploads/receipts', 'public');

                    $receiptFiles[] = [
                        'file_name' => $file->getClientOriginalName(),
                        'file_path' => $storedPath,
                    ];
                }
            }

            $sale = Sale::create([
                'customer_id'   => $validated['customer_id'],
                'challan_no'    => $validated['challan_no'],
                'challan_date'  => $validated['challan_date'],
                'shipment_date' => $validated['shipment_date'],
                'shipment_name' => $validated['shipment_name'] ?? null,
                'notes'         => $validated['notes'] ?? null,
                'receipt_files' => $receiptFiles,
                'created_by'    => Auth::id(),
            ]);

            $addedSerials = [];

            foreach ($validated['items'] as $item) {

                $serial = trim($item['serial_no']);

                if (in_array($serial, $addedSerials)) {
                    continue;
                }

                $inventory = Inventory::whereRaw(
                        'LOWER(TRIM(serial_no)) = ?',
                        [strtolower($serial)]
                    )
                    ->whereRaw('LOWER(TRIM(tested_status)) = ?', ['pass'])
                    ->whereNull('deleted_at')
                    ->whereNotIn('serial_no', function ($q) {
                        $q->select('serial_no')
                          ->from('sale_items')
                          ->whereNull('deleted_at');
                    })
                    ->first();

                if (!$inventory) {
                    throw new \Exception("Serial {$serial} invalid or already assigned.");
                }

                $sale->items()->create([
                    'quantity'   => $item['quantity'],
                    'serial_no'  => $serial,
                    'product_id' => $inventory->product_id,
                    'created_by' => Auth::id(),
                ]);

                $addedSerials[] = $serial;
            }

            return response()->json(
                $sale->load('items.inventory'),
                201
            );
        });

    } catch (QueryException $e) {

        if ($e->getCode() === '23505') {
            return response()->json([
                'message' => 'Challan No already exists.'
            ], 422);
        }

        throw $e;
    }
}





public function show($id)
{
    $sale = Sale::with([
        'customer:id,customer,email,mobile_no'
    ])
    ->whereNull('deleted_at')
    ->find($id);

    if (!$sale) {
        return response()->json(['message' => 'Sale not found'], 404);
    }

    $items = \App\Models\SaleItem::where('sale_id', $sale->id)
        ->with('product:id,name')
        ->get();

    $receipts = collect();


    if (!empty($sale->receipt_files)) {

        $files = is_string($sale->receipt_files)
            ? json_decode($sale->receipt_files, true)
            : $sale->receipt_files;

        if (is_array($files)) {
            $receipts = collect($files)->map(function ($file) {

                // 🔹 If stored as object {file_path, file_name}
                if (is_array($file)) {
                    return [
                        'file_path' => asset($file['file_path'] ?? ''),
                        'file_name' => $file['file_name'] ?? '',
                    ];
                }

                // 🔹 If stored as string
                if (is_string($file)) {
                    return [
                        'file_path' => asset($file),
                        'file_name' => basename($file),
                    ];
                }

                return null;
            })->filter()->values();
        }
    }

   
    if (method_exists($sale, 'receipts') && $sale->receipts->count()) {
        $receipts = $sale->receipts->map(function ($r) {
            return [
                'file_path' => asset($r->file_path),
                'file_name' => $r->file_name,
            ];
        })->values();
    }

    return response()->json([
        'sale'     => $sale,
        'products' => $items,
        'receipts' => $receipts
    ]);
}



public function update(Request $request, $id)
{
    $sale = Sale::whereNull('deleted_at')->find($id);

    if (!$sale) {
        return response()->json(['message' => 'Sale not found'], 404);
    }

    $request->validate([
        'challan_no' => [
            'sometimes',
            function ($attribute, $value, $fail) use ($id) {
                $exists = Sale::where('challan_no', $value)
                    ->where('id', '!=', $id)
                    ->whereNull('deleted_at')
                    ->exists();

                if ($exists) {
                    $fail("Challan No already exists.");
                }
            }
        ],
        'customer_id'   => 'sometimes|integer',
        'challan_date'  => 'sometimes|date|before_or_equal:today',
        'shipment_date' => 'sometimes|date|before_or_equal:today',
        'shipment_name' => 'nullable|string',
        'notes'         => 'nullable|string',
        'receipt_files.*' => 'file|mimes:jpg,jpeg,png,pdf|max:102400',
        'items' => 'nullable|array',
    ]);

    return DB::transaction(function () use ($request, $sale) {

        $existingFiles = is_array($sale->receipt_files)
            ? $sale->receipt_files
            : json_decode($sale->receipt_files ?? '[]', true);

        $removedFiles = array_map(function($f) {
            return basename($f); 
        }, json_decode($request->removed_receipt_files ?? '[]', true));

        if (!empty($removedFiles)) {
            $existingFiles = array_values(array_filter($existingFiles, function ($file) use ($removedFiles) {
                if (is_array($file) && isset($file['file_path'])) {
                    return !in_array(basename($file['file_path']), $removedFiles);
                }
                if (is_string($file)) {
                    return !in_array(basename($file), $removedFiles);
                }
                return true;
            }));
        }

        if ($request->hasFile('receipt_files')) {
            foreach ($request->file('receipt_files') as $file) {
                $existingFiles[] = $file->store('uploads/receipts', 'public');
            }
        }

        // Update sale
        $sale->update([
            'customer_id'   => $request->customer_id ?? $sale->customer_id,
            'challan_no'    => $request->challan_no ?? $sale->challan_no,
            'challan_date'  => $request->challan_date ?? $sale->challan_date,
            'shipment_date' => $request->shipment_date ?? $sale->shipment_date,
            'shipment_name' => $request->shipment_name ?? $sale->shipment_name,
            'notes'         => $request->notes ?? $sale->notes,
            'receipt_files' => $existingFiles,
            'updated_by'    => Auth::id(),
        ]);

        // Handle sale items
        if ($request->has('items')) {

            $existingIds = collect($request->items)->pluck('id')->filter();

            // Soft delete removed items
            $sale->items()
                ->whereNotIn('id', $existingIds)
                ->update([
                    'deleted_at' => now(),
                    'deleted_by' => Auth::id(),
                ]);

            // Update or create items
            foreach ($request->items as $item) {

                $serialRaw = $item['serial_no'] ?? null;
                if (is_array($serialRaw)) {
                    $serialRaw = $serialRaw[0] ?? null;
                }

                $serial = trim((string) $serialRaw);
                if ($serial === '') {
                    throw new \Exception('Serial number is required');
                }

                $inventory = Inventory::whereRaw(
                        'LOWER(TRIM(serial_no)) = ?',
                        [strtolower($serial)]
                    )
                    ->whereRaw('LOWER(TRIM(tested_status)) = ?', ['pass'])
                    ->whereNull('deleted_at')
                    ->whereNotIn('serial_no', function ($q) use ($sale) {
                        $q->select('serial_no')
                          ->from('sale_items')
                          ->where('sale_id', '!=', $sale->id)
                          ->whereNull('deleted_at');
                    })
                    ->first();

                if (!$inventory) {
                    throw new \Exception("Serial {$serial} invalid or already assigned.");
                }

                if (!empty($item['id'])) {
                    SaleItem::find($item['id'])->update([
                        'quantity'   => $item['quantity'] ?? 1,
                        'serial_no'  => $serial,
                        'product_id' => $inventory->product_id,
                        'updated_by' => Auth::id(),
                    ]);
                } else {
                    $sale->items()->create([
                        'quantity'   => $item['quantity'] ?? 1,
                        'serial_no'  => $serial,
                        'product_id' => $inventory->product_id,
                        'created_by' => Auth::id(),
                    ]);
                }
            }
        }

        return response()->json($sale->load('items.inventory'), 200);
    });
}



    public function destroy($id)
    {
        $sale = Sale::whereNull('deleted_at')->find($id);

        if (!$sale) {
            return response()->json(['message' => 'Sale not found'], 404);
        }

        $sale->items()->update([
            'deleted_at' => now(),
            'deleted_by' => Auth::id()
        ]);

        $sale->update([
            'deleted_at' => now(),
            'deleted_by' => Auth::id()
        ]);

        return response()->json(['message' => 'Sale soft deleted successfully']);
    }
public function getSoldAndNotSoldSerials($productId)
{
    $assembled = Inventory::where('product_id', $productId)
        ->whereNull('deleted_at')
        ->get();

    if ($assembled->isEmpty()) {
        return response()->json([
            'message' => 'No assembled serials found for this product.',
            'sold' => [],
            'not_sold' => []
        ]);
    }

    // Normalize serials
    $assembledSerials = $assembled->pluck('serial_no')
        ->map(fn($s) => strtolower(trim($s)))
        ->toArray();

    // Fetch sold serials
    $soldSerials = SaleItem::where('product_id', $productId)
        ->whereNull('deleted_at')
        ->pluck('serial_no')
        ->map(fn($s) => strtolower(trim($s)))
        ->toArray();

    // Sold items with tested status
    $soldRows = SaleItem::where('product_id', $productId)
        ->whereIn('serial_no', $soldSerials)
        ->with([
            'sale:id,challan_no,challan_date,customer_id',
            'sale.customer:id,customer,email,mobile_no'
        ])
        ->select('id','serial_no','sale_id','product_id')
        ->orderBy('serial_no')
        ->get()
        ->map(function($saleItem) use ($assembled) {
            $inv = $assembled->firstWhere('serial_no', strtolower(trim($saleItem->serial_no)));
            return [
                'id' => $saleItem->id,
                'serial_no' => $saleItem->serial_no,
                'sale_id' => $saleItem->sale_id,
                'product_id' => $saleItem->product_id,
                'tested_status' => $inv->tested_status ?? 'Fail',
                'tested_by' => $inv->tested_by ?? null,
                'tested_date' => $inv->tested_date ?? null,
            ];
        });

    // Unsold serials with tested status
    $notSoldSerials = array_diff($assembledSerials, $soldSerials);

    $unsoldRows = collect($notSoldSerials)->map(function($serial) use ($assembled) {
        $inv = $assembled->firstWhere('serial_no', strtolower(trim($serial)));
        return [
            'id' => $inv->id,
            'serial_no' => $inv->serial_no,
            'tested_status' => $inv->tested_status ?? 'Fail',
            'tested_by' => $inv->tested_by ?? null,
            'tested_date' => $inv->tested_date ?? null,
        ];
    })->values();

    return response()->json([
        'product_id' => $productId,
        'sold_count' => count($soldSerials),
        'unsold_count' => count($notSoldSerials),
        'sold' => $soldRows,
        'not_sold' => $unsoldRows,
        'message' => 'Sold and unsold serials fetched successfully.'
    ]);
}


public function getProductSaleSummary()
{
    // Fetch all products
    $products = \App\Models\Product::whereNull('deleted_at')->get();

    $summary = [];

    foreach ($products as $product) {

        // Assembled (Inventory count)
        $assembled = Inventory::where('product_id', $product->id)
            ->whereNull('deleted_at')
            ->count();

        // Sold (SaleItems count)
        $sold = SaleItem::where('product_id', $product->id)
            ->whereNull('deleted_at')
            ->count();

        // Available to Sale
        $available = max($assembled - $sold, 0);

        $summary[] = [
            'product_id'     => $product->id,
            'product_name'   => $product->name,
            'assembled_qty'  => $assembled,
            'sold_qty'       => $sold,
            'available_qty'  => $available,
        ];
    }

    return response()->json($summary);
}
public function getSaleSerials($productId)
{
    $serials = \DB::table('sale_items')
        ->where('product_id', $productId)
        ->whereNull('deleted_at')   // ✅ Only active (not deleted)
        ->select('id', 'serial_no')
        ->get();

    return response()->json($serials);
}
    public function getProductSerials($productId)
    {
        return response()->json(
            Inventory::where('product_id', $productId)
                ->whereNull('deleted_at')
                ->whereRaw('LOWER(TRIM(tested_status)) = ?', ['pass'])
                ->select('id', 'serial_no', 'product_id', 'tested_status')
                ->get()
        );
    }
   public function getLastFourSales()
{
    $sales = Sale::with([
        'customer:id,customer',
        'items:id,sale_id,product_id,quantity',
        'items.product:id,name'
    ])
    ->whereNull('deleted_at') // remove this if your sales table does not have soft deletes
    ->orderBy('id', 'desc')
    ->limit(4)
    ->get();

    if ($sales->isEmpty()) {
        // Try without deleted_at filter
        $sales = Sale::with([
            'customer:id,customer',
            'items:id,sale_id,product_id,quantity',
            'items.product:id,name'
        ])
        ->orderBy('id', 'desc')
        ->limit(4)
        ->get();
    }

    $formatted = $sales->map(function ($sale) {
        return [
            'sale_id'       => $sale->id,
            'customer'      => $sale->customer->customer ?? 'N/A',
            'shipment_date' => $sale->shipment_date,
            'products'      => $sale->items->map(function ($item) {
                return [
                    'product_name' => $item->product->name ?? 'N/A',
                    'quantity'     => $item->quantity,
                ];
            }),
        ];
    });

    return response()->json([
        'success' => true,
        'message' => 'Last 4 sales fetched successfully',
        'data'    => $formatted
    ]);
}
 public function getSalesSummary()
{
    // Total Sold Count
    $totalSold = SaleItem::whereNull('sale_items.deleted_at')
        ->whereIn('sale_items.sale_id', function ($q) {
            $q->select('id')->from('sales')->whereNull('deleted_at');
        })
        ->count();

    $yearly = SaleItem::selectRaw("
            EXTRACT(YEAR FROM sale_items.created_at) AS year,
            COUNT(*) AS total_quantity
        ")
        ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
        ->whereNull('sale_items.deleted_at')
        ->whereNull('sales.deleted_at')
        ->groupByRaw("EXTRACT(YEAR FROM sale_items.created_at)")
        ->orderByRaw("EXTRACT(YEAR FROM sale_items.created_at)")
        ->get();

    $monthly = SaleItem::selectRaw("
            TO_CHAR(sale_items.created_at, 'FMMonth') AS month_name,
            EXTRACT(MONTH FROM sale_items.created_at) AS month_number,
            EXTRACT(YEAR FROM sale_items.created_at) AS year,
            COUNT(*) AS total_quantity
        ")
        ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
        ->whereNull('sale_items.deleted_at')
        ->whereNull('sales.deleted_at')
        ->groupByRaw("
            month_name,
            month_number,
            year
        ")
        ->orderByRaw("year ASC, month_number ASC")
        ->get();

    return response()->json([
        'success'              => true,
        'total_products_sold'  => $totalSold,
        'yearly_sales'         => $yearly,
        'monthly_sales'        => $monthly,
    ]);
}

public function getTotalProductSalesCount()
{
    $count = SaleItem::whereNull('deleted_at')
        ->whereIn('sale_id', function ($q) {
            $q->select('id')
              ->from('sales')
              ->whereNull('deleted_at');
        })
        ->count();

    return response()->json([
        'success' => true,
        'message' => 'Total product sales count fetched successfully',
        'count' => $count,
    ]);
}
public function getSalesWithTotals()
{
    $sales = Sale::with([
        'customer:id,customer',
        'items:id,sale_id,product_id,quantity,serial_no',
        'items.product:id,name',
        'items.inventory:id,serial_no'
    ])
    ->whereNull('deleted_at')
    ->orderBy('id', 'desc')
    ->limit(20)
    ->get();

    $formatted = $sales->map(function ($sale) {
        return [
            'sale_id'       => $sale->id,
            'customer'      => $sale->customer->customer ?? 'N/A',
            'shipment_date' => $sale->shipment_date,
            'products'      => $sale->items->map(function ($item) {
                return [
                    'product_name' => $item->product->name ?? 'N/A',
                    'quantity'     => $item->quantity,
                    'serial_no'    => $item->inventory->serial_no ?? null,
                ];
            })->values(),
        ];
    });

    return response()->json([
        'success' => true,
        'message' => 'Last sales list fetched successfully',
        'data'    => $formatted
    ]);
}





// public function getSalesGraph()
// {
//     // YEARLY DATA
//     $yearly = SaleItem::selectRaw("
//             EXTRACT(YEAR FROM sale_items.created_at) AS year,
//             SUM(sale_items.quantity) AS total_quantity
//         ")
//         ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
//         ->whereNull('sale_items.deleted_at')
//         ->whereNull('sales.deleted_at')
//         ->groupByRaw("EXTRACT(YEAR FROM sale_items.created_at)")
//         ->orderByRaw("EXTRACT(YEAR FROM sale_items.created_at)")
//         ->get();

//     // MONTHLY DATA
//     $monthly = SaleItem::selectRaw("
//             TO_CHAR(sale_items.created_at, 'Month') AS month_name,
//             EXTRACT(MONTH FROM sale_items.created_at) AS month_number,
//             EXTRACT(YEAR FROM sale_items.created_at) AS year,
//             SUM(sale_items.quantity) AS total_quantity
//         ")
//         ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
//         ->whereNull('sale_items.deleted_at')
//         ->whereNull('sales.deleted_at')
//         ->groupByRaw("
//             month_name,
//             month_number,
//             EXTRACT(YEAR FROM sale_items.created_at)
//         ")
//         ->orderByRaw("year ASC, month_number ASC")
//         ->get();

//     return response()->json([
//         'success' => true,
//         'yearly'  => $yearly,
//         'monthly' => $monthly,
//     ]);
// }

}
