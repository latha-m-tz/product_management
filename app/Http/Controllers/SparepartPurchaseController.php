<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Sparepart;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Models\SparepartPurchase;
use App\Models\SparepartPurchaseItem;
use App\Models\Vendor;
use App\Models\ContactPerson;
use App\Models\Product;
use App\Models\ProductType;

class SparepartPurchaseController extends Controller
{
    
// public function store(Request $request)
// {

//     $request->validate([
//         'vendor_id' => 'required|integer|exists:vendors,id',
//         'challan_no' => 'required|string|max:50',
//         'challan_date' => 'required|date',
//         'items' => 'required|array|min:1',
//         'items.*.product_id' => 'nullable|integer|exists:product,id',
//         'items.*.sparepart_id' => 'required|integer|exists:spareparts,id',
//         'items.*.quantity' => 'required|integer|min:1',
//         'items.*.warranty_status' => 'nullable|string|max:50',
//        'items.*.serial_no' => 'nullable|string|max:100|unique:sparepart_purchase_items,serial_no',
      
//         'items.*.from_serial' => 'nullable|string|max:50',      
//         'items.*.to_serial' => 'nullable|string|max:50',        
//     ]);

    

//     $purchase = SparepartPurchase::create([
//         'vendor_id' => $request->vendor_id,
//         'challan_no' => $request->challan_no,
//         'challan_date' => $request->challan_date,
//         'quantity' => collect($request->items)->sum('quantity'),
//         'created_by' => auth()->id(),
         
//     ]);

//     $allItems = []; 

//     foreach ($request->items as $item) {

//         if (!empty($item['from_serial']) && !empty($item['to_serial'])) {

//             preg_match('/^(.*?)(\d+)$/', $item['from_serial'], $fromParts);
//             preg_match('/^(.*?)(\d+)$/', $item['to_serial'], $toParts);

//             $prefix = $fromParts[1] ?? '';
//             $fromNum = (int) ($fromParts[2] ?? 0);
//             $toNum = (int) ($toParts[2] ?? 0);

//             for ($i = $fromNum; $i <= $toNum; $i++) {
//                 $serial = $prefix . $i;

//                 $savedItem = SparepartPurchaseItem::create([
//                     'purchase_id' => $purchase->id,
//                     'product_id' => $item['product_id'] ?? null,
//                     'sparepart_id' => $item['sparepart_id'],
//                     'quantity' => 1,
//                     'warranty_status' => $item['warranty_status'] ?? null,
//                     'serial_no' => $serial,
//                     'from_serial' => $item['from_serial'],
//                     'to_serial' => $item['to_serial'],
//                       'created_by' => auth()->id(),
          
//                 ]);

//                 $allItems[] = $savedItem;
//             }
//         }
   
//         elseif (!empty($item['serial_no'])) {
//             $serials = explode(',', $item['serial_no']);
//             foreach ($serials as $serial) {
//                 $serial = trim($serial);
//                 if ($serial === '') continue;

//                 $savedItem = SparepartPurchaseItem::create([
//                     'purchase_id' => $purchase->id,
//                     'product_id' => $item['product_id'] ?? null,
//                     'sparepart_id' => $item['sparepart_id'],
//                     'quantity' => 1,
//                     'warranty_status' => $item['warranty_status'] ?? null,
//                     'serial_no' => $serial,
//                     'from_serial' => null,
//                     'to_serial' => null,
//                 ]);

//                 $allItems[] = $savedItem;
//             }
//         }
     
//         else {
//             $savedItem = SparepartPurchaseItem::create([
//                 'purchase_id' => $purchase->id,
//                 'product_id' => $item['product_id'] ?? null,
//                 'sparepart_id' => $item['sparepart_id'],
//                 'quantity' => $item['quantity'],
//                 'warranty_status' => $item['warranty_status'] ?? null,
//                 'serial_no' => null,
//                 'from_serial' => null,
//                 'to_serial' => null,
//             ]);


//             $allItems[] = $savedItem;
//         }
//     }

//     return response()->json([
//         'message' => 'Spare part purchase saved successfully',
//         'purchase_id' => $purchase->id,
//         'items' => $allItems,
//     ], 201);
// }

public function store(Request $request)
{
    $request->validate([
        'vendor_id' => 'required|integer|exists:vendors,id',
         'challan_no' => 'required|string|max:50|unique:sparepart_purchase,challan_no',
    'challan_date' => 'required|date|before_or_equal:today',
        'items' => 'required|array|min:1',
        'items.*.product_id' => 'nullable|integer|exists:product,id',
        'items.*.sparepart_id' => 'nullable|integer|exists:spareparts,id',
        'items.*.quantity' => 'required|integer|min:1',
        'items.*.warranty_status' => 'nullable|string|max:50',
        'items.*.serial_no' => 'nullable|string|max:100',
        'items.*.from_serial' => 'nullable|string|max:50',
        'items.*.to_serial' => 'nullable|string|max:50',
    ]);

    $allSerials = [];

    // collect all serials from request (both comma separated and range)
    foreach ($request->items as $item) {
        if (!empty($item['serial_no'])) {
            $serials = array_map('trim', explode(',', $item['serial_no']));
            foreach ($serials as $s) {
                if ($s !== '') {
                    $allSerials[] = strtoupper($s);
                }
            }
        }

        if (!empty($item['from_serial']) && !empty($item['to_serial'])) {
            preg_match('/^(.*?)(\d+)$/', $item['from_serial'], $fromParts);
            preg_match('/^(.*?)(\d+)$/', $item['to_serial'], $toParts);

            $prefix   = $fromParts[1] ?? '';
            $fromNum  = (int)($fromParts[2] ?? 0);
            $toNum    = (int)($toParts[2] ?? 0);
            $padLength = strlen($fromParts[2] ?? '0'); // preserve leading zeros

            for ($i = $fromNum; $i <= $toNum; $i++) {
                $serial = $prefix . str_pad($i, $padLength, '0', STR_PAD_LEFT);
                $allSerials[] = strtoupper($serial);
            }
        }
    }

    // check duplicates inside request
    $duplicates = array_diff_assoc($allSerials, array_unique($allSerials));
    if (!empty($duplicates)) {
        return response()->json([
            'errors' => [
                'items' => ["Duplicate serial(s) in request: " . implode(', ', $duplicates)]
            ]
        ], 422);
    }

    // check against DB
    if (!empty($allSerials)) {
        $exists = DB::table('sparepart_purchase_items')
            ->whereIn(DB::raw('UPPER(serial_no)'), $allSerials)
            ->pluck('serial_no')
            ->map(fn($s) => strtoupper($s))
            ->toArray();

        if (!empty($exists)) {
            return response()->json([
                'errors' => [
                    'items' => ["Serial(s) already exist in DB: " . implode(', ', $exists)]
                ]
            ], 422);
        }
    }

    // create purchase
    $purchase = SparepartPurchase::create([
        'vendor_id'   => $request->vendor_id,
        'challan_no'  => $request->challan_no,
        'challan_date'=> $request->challan_date,
        'quantity'    => collect($request->items)->sum('quantity'),
        'created_by'  => auth()->id(),
    ]);

    $allItems = [];

    // ✅ FIXED: loop through request items while saving
    foreach ($request->items as $item) {
        if (!empty($item['from_serial']) && !empty($item['to_serial'])) {
            preg_match('/^(.*?)(\d+)$/', $item['from_serial'], $fromParts);
            preg_match('/^(.*?)(\d+)$/', $item['to_serial'], $toParts);

            $prefix   = $fromParts[1] ?? '';
            $fromNum  = (int)($fromParts[2] ?? 0);
            $toNum    = (int)($toParts[2] ?? 0);
            $padLength = strlen($fromParts[2] ?? '0');

            for ($i = $fromNum; $i <= $toNum; $i++) {
                $serial = $prefix . str_pad($i, $padLength, '0', STR_PAD_LEFT);

                $savedItem = SparepartPurchaseItem::create([
                    'purchase_id'     => $purchase->id,
                    'product_id'      => $item['product_id'] ?? null,
                    'sparepart_id'    => $item['sparepart_id'],
                    'quantity'        => 1,
                    'warranty_status' => $item['warranty_status'] ?? null,
                    'serial_no'       => strtoupper($serial),
                    'from_serial'     => $item['from_serial'],
                    'to_serial'       => $item['to_serial'],
                    'created_by'      => auth()->id(),
                ]);

                $allItems[] = $savedItem;
            }
        }
        elseif (!empty($item['serial_no'])) {
            $serials = explode(',', $item['serial_no']);
            foreach ($serials as $serial) {
                $serial = trim($serial);
                if ($serial === '') continue;

                $savedItem = SparepartPurchaseItem::create([
                    'purchase_id'     => $purchase->id,
                    'product_id'      => $item['product_id'] ?? null,
                    'sparepart_id'    => $item['sparepart_id'],
                    'quantity'        => 1,
                    'warranty_status' => $item['warranty_status'] ?? null,
                    'serial_no'       => strtoupper($serial),
                    'from_serial'     => null,
                    'to_serial'       => null,
                    'created_by'      => auth()->id(),
                ]);

                $allItems[] = $savedItem;
            }
        } else {
            $savedItem = SparepartPurchaseItem::create([
                'purchase_id'     => $purchase->id,
                'product_id'      => $item['product_id'] ?? null,
                'sparepart_id'    => $item['sparepart_id'],
                'quantity'        => $item['quantity'],
                'warranty_status' => $item['warranty_status'] ?? null,
                'serial_no'       => null,
                'from_serial'     => null,
                'to_serial'       => null,
                'created_by'      => auth()->id(),
            ]);

            $allItems[] = $savedItem;
        }
    }

    return response()->json([
        'message' => 'Spare part purchase saved successfully',
        'purchase_id' => $purchase->id,
        'items' => $allItems,
    ], 201);
}


public function getAvailableSpareparts(Request $request)
{ 
    
$spareParts = DB::table('spareparts as s')
    ->select('s.id', 's.name', 's.sparepart_type')
    ->get();

    $vendors = Vendor::with('contactPersons')->get();
$categories = DB::table('product')

    ->select('id', 'name')

    ->whereNull('deleted_by')

    ->get();

 

    return response()->json([
        'spareparts' => $spareParts,
        'vendors'    => $vendors,
        'categories' => $categories
    ]);
}


public function index(Request $request)
{
   $query = DB::table('sparepart_purchase as p')
    ->leftJoin('vendors as v', 'p.vendor_id', '=', 'v.id')

    ->leftJoin('sparepart_purchase_items as pi', 'p.id', '=', 'pi.purchase_id')
    ->leftJoin('product as c', 'pi.product_id', '=', 'c.id')
    ->leftJoin('spareparts as s', 'pi.sparepart_id', '=', 's.id')
    ->select(
        'p.id as purchase_id',
        'p.challan_no',
        'p.challan_date',
        'v.id as vendor_id',
        // 'v.vendor as vendor_name',
                DB::raw("COALESCE(v.vendor, 'Deleted Vendor') as vendor_name"),
        'pi.id as item_id',
        'pi.quantity as item_quantity',
        'pi.serial_no',
        'pi.warranty_status',
        'c.id as product_id',
        'c.name as product_name',
        's.id as sparepart_id',
        's.name as sparepart_name',
        DB::raw('SUM(pi.quantity) OVER (PARTITION BY p.id) as total_quantity') 
    )
    ->orderBy('p.id', 'desc');


    $results = $query->get();

    // Group items under purchase
    $grouped = $results->groupBy('purchase_id')->map(function ($items) {
        $first = $items->first();
        return [
            'purchase_id'   => $first->purchase_id,
            'challan_no'    => $first->challan_no,
            'challan_date'  => $first->challan_date,
            'total_quantity'=> $first->total_quantity,
            'vendor' => [
                'id'   => $first->vendor_id,
                'name' => $first->vendor_name,
            ],
            'items' => $items->map(function ($item) {
                return [
                    'item_id'        => $item->item_id,
                    'product_id'     => $item->product_id,
                    'product_name'   => $item->product_name,
                    'sparepart_id'   => $item->sparepart_id,
                    'sparepart_name' => $item->sparepart_name,
                    'quantity'       => $item->item_quantity,
                    'serial_no'      => $item->serial_no,
                    'warranty_status'=> $item->warranty_status,
                ];
            })->values(),
        ];
    })->values();

    return response()->json($grouped);
}

public function edit($id)
{
    $purchase = SparepartPurchase::with('items')->findOrFail($id);

    return response()->json([
        'purchase' => $purchase
    ]);
}


// public function update(Request $request, $id)
// {
//     $request->validate([
//         'vendor_id' => 'required|integer|exists:vendors,id',
//         'challan_no' => 'required|string|max:50',
//         'challan_date' => 'required|date',
//         'items' => 'required|array|min:1',
//         'items.*.product_id' => 'nullable|integer|exists:product,id',
//         'items.*.sparepart_id' => 'required|integer|exists:spareparts,id',
//         'items.*.quantity' => 'required|integer|min:1',
//         'items.*.warranty_status' => 'nullable|string|max:50',
//         'items.*.serial_no' => 'nullable|string|max:100',      
//         'items.*.from_serial' => 'nullable|string|max:50',      
//         'items.*.to_serial' => 'nullable|string|max:50',
//     ]);

//  $purchase = SparepartPurchase::findOrFail($id);

// // Update main purchase
// $purchase->update([
//     'vendor_id' => $request->vendor_id,
//     'challan_no' => $request->challan_no,
//     'challan_date' => $request->challan_date,
//     'quantity' => collect($request->items)->sum('quantity'),
// ]);

// // Delete all previous items for this purchase
// $purchase->items()->delete(); // or SparepartPurchaseItem::where('purchase_id', $purchase->id)->delete();

// $allItems = [];

// foreach ($request->items as $item) {

//     // Serial range
//     if (!empty($item['from_serial']) && !empty($item['to_serial'])) {
//         preg_match('/^(.*?)(\d+)$/', $item['from_serial'], $fromParts);
//         preg_match('/^(.*?)(\d+)$/', $item['to_serial'], $toParts);

//         $prefix = $fromParts[1] ?? '';
//         $fromNum = (int) ($fromParts[2] ?? 0);
//         $toNum = (int) ($toParts[2] ?? 0);

//         for ($i = $fromNum; $i <= $toNum; $i++) {
//             $serial = $prefix . $i;
//             $allItems[] = SparepartPurchaseItem::create([
//                 'purchase_id' => $purchase->id,
//                 'product_id' => $item['product_id'] ?? null,
//                 'sparepart_id' => $item['sparepart_id'],
//                 'quantity' => 1,
//                 'warranty_status' => $item['warranty_status'] ?? null,
//                 'serial_no' => $serial,
//                 'from_serial' => $item['from_serial'],
//                 'to_serial' => $item['to_serial'],
//             ]);
//         }
//     }
//     // Individual serials
//     elseif (!empty($item['serial_no'])) {
//         $serials = array_filter(array_map('trim', explode(',', $item['serial_no'])));
//         foreach ($serials as $serial) {
//             $allItems[] = SparepartPurchaseItem::create([
//                 'purchase_id' => $purchase->id,
//                 'product_id' => $item['product_id'] ?? null,
//                 'sparepart_id' => $item['sparepart_id'],
//                 'quantity' => 1,
//                 'warranty_status' => $item['warranty_status'] ?? null,
//                 'serial_no' => $serial,
//                 'from_serial' => null,
//                 'to_serial' => null,
//             ]);
//         }
//     }
//     // Quantity only
//     else {
//         $allItems[] = SparepartPurchaseItem::create([
//             'purchase_id' => $purchase->id,
//             'product_id' => $item['product_id'] ?? null,
//             'sparepart_id' => $item['sparepart_id'],
//             'quantity' => $item['quantity'],
//             'warranty_status' => $item['warranty_status'] ?? null,
//             'serial_no' => null,
//             'from_serial' => null,
//             'to_serial' => null,
//         ]);
//     }
// }

// return response()->json([
//     'message' => 'Spare part purchase updated successfully',
//     'purchase_id' => $purchase->id,
//     'items' => $allItems,
// ], 200);
// }


public function update(Request $request, $id)
{
    $request->validate([
        'vendor_id' => 'required|integer|exists:vendors,id',
        'challan_no' => 'required|string|max:50',
        'challan_date' => 'required|date|before_or_equal:today',
        'items' => 'nullable|array|min:1',
        // 'items.*.id' => 'nullable|integer|exists:sparepart_purchase_items,id',
        'items.*.id' => 'nullable|integer', // remove exists

        'items.*.product_id' => 'nullable|integer|exists:product,id',
        'items.*.sparepart_id' => 'nullable|integer|exists:spareparts,id',
        'items.*.quantity' => 'required|integer|min:1',
        'items.*.warranty_status' => 'nullable|string|max:50',
        'items.*.serials' => 'nullable|array',
    ]);

    $purchase = SparepartPurchase::findOrFail($id);

    // Update purchase main details
    $purchase->update([
        'vendor_id' => $request->vendor_id,
        'challan_no' => $request->challan_no,
        'challan_date' => $request->challan_date,
    ]);

       if (!empty($request->deleted_sparepart_ids)) {
        SparepartPurchaseItem::whereIn('id', $request->deleted_sparepart_ids)->delete();
    }

    $totalQuantity = 0;

    foreach ($request->items as $item) {
        $serials = $item['serials'] ?? [];
        $serials = array_filter($serials);

        /** -------------------------
         *  1. Check duplicates in request
         * ------------------------- */
        $duplicateInRequest = collect($serials)
            ->duplicates()
            ->all();

        if (!empty($duplicateInRequest)) {
            return response()->json([
                'errors' => [
                    'items' => ['Duplicate serial(s) in request: ' . implode(', ', $duplicateInRequest)]
                ]
            ], 422);
        }

        /** -------------------------
         *  2. Check duplicates in DB (exclude current purchase)
         * ------------------------- */
        if (count($serials) > 0) {
            $existingSerials = SparepartPurchaseItem::whereIn('serial_no', $serials)
                ->where('purchase_id', '!=', $purchase->id)
                ->pluck('serial_no')
                ->toArray();

            if (!empty($existingSerials)) {
                return response()->json([
                    'errors' => [
                        'items' => ['Serial(s) already exist in DB: ' . implode(', ', $existingSerials)]
                    ]
                ], 422);
            }
        }

        /** -------------------------
         *  3. Delete removed serials
         * ------------------------- */
        SparepartPurchaseItem::where('purchase_id', $purchase->id)
            ->where('sparepart_id', $item['sparepart_id'])
            ->when($item['product_id'], function($q) use ($item) {
                return $q->where('product_id', $item['product_id']);
            })
            ->whereNotIn('serial_no', $serials)
            ->delete();

        /** -------------------------
         *  4. Insert or Update serials
         * ------------------------- */
        if (count($serials) > 0) {
            foreach ($serials as $serial) {
                SparepartPurchaseItem::updateOrCreate(
                    [
                        'purchase_id' => $purchase->id,
                        'sparepart_id' => $item['sparepart_id'],
                        'product_id' => $item['product_id'] ?? null,
                        'serial_no' => $serial,
                    ],
                    [
                        'quantity' => 1,
                        'warranty_status' => $item['warranty_status'] ?? null,
                    ]
                );
            }
            $totalQuantity += count($serials);
        } else {
            // Quantity-only items
            $purchaseItem = SparepartPurchaseItem::updateOrCreate(
                [
                    'purchase_id' => $purchase->id,
                    'sparepart_id' => $item['sparepart_id'],
                    'product_id' => $item['product_id'] ?? null,
                    'serial_no' => null,
                ],
                [
                    'quantity' => $item['quantity'] ?? 0,
                    'warranty_status' => $item['warranty_status'] ?? null,
                ]
            );
            $totalQuantity += $item['quantity'] ?? 0;
        }
    }

    /** -------------------------
     *  5. Update purchase total qty
     * ------------------------- */
    $purchase->update(['quantity' => $totalQuantity]);

    /** -------------------------
     *  6. Reload items cleanly
     * ------------------------- */
    $purchase->load('items');

    $purchaseItems = $purchase->items
        ->groupBy(function($item) {
            return $item->sparepart_id . '_' . ($item->product_id ?? 'null');
        })
        ->map(function($group) {
            $first = $group->first();
            $serials = $group->pluck('serial_no')
                ->filter(fn($s) => $s !== null)
                ->sort()
                ->values()
                ->toArray();

            return [
                'id' => $first->id,
                'sparepart_id' => $first->sparepart_id,
                'product_id' => $first->product_id,
                'qty' => $serials ? count($serials) : $first->quantity,
                'warranty_status' => $first->warranty_status,
                'from_serial' => $serials[0] ?? null,
                'to_serial' => $serials[count($serials)-1] ?? null,
                'serials' => $serials,
            ];
        })->values();

    return response()->json([
        'message' => 'Spare part purchase updated successfully',
        'purchase_id' => $purchase->id,
        'quantity' => $totalQuantity,
        'items' => $purchaseItems,
    ]);
}



public function destroy($id)
{
    DB::transaction(function () use ($id) {
        SparepartPurchaseItem::where('purchase_id', $id)->delete();
        SparepartPurchase::findOrFail($id)->delete();
    });

    return response()->json([
        'message' => 'Purchase and its items deleted successfully'
    ]);
}





// public function update(Request $request, $id)
// {
//     $request->validate([
//         'vendor_id' => 'required|integer|exists:vendors,id',
//         'challan_no' => 'required|string|max:50',
//         'challan_date' => 'required|date',
//         'items' => 'required|array|min:1',
//         'items.*.product_id' => 'nullable|integer|exists:product,id',
//         'items.*.sparepart_id' => 'required|integer|exists:spareparts,id',
//         'items.*.quantity' => 'required|integer|min:1',
//         'items.*.warranty_status' => 'nullable|string|max:50',
//         'items.*.serial_no' => 'nullable|string|max:100',      
//         'items.*.from_serial' => 'nullable|string|max:50',      
//         'items.*.to_serial' => 'nullable|string|max:50',
//     ]);

//     $purchase = SparepartPurchase::findOrFail($id);

//     // Update main purchase info
//     $purchase->update([
//         'vendor_id' => $request->vendor_id,
//         'challan_no' => $request->challan_no,
//         'challan_date' => $request->challan_date,
//         'quantity' => collect($request->items)->sum('quantity'),
//     ]);

//     $allItems = [];

//     foreach ($request->items as $item) {

//         // // Delete only items for this sparepart
//         // SparepartPurchaseItem::where('purchase_id', $purchase->id)
//         //     ->where('sparepart_id', $item['sparepart_id'])
//         //     ->delete();

//         // 1️⃣ Serial range
//         if (!empty($item['from_serial']) && !empty($item['to_serial'])) {
//             preg_match('/^(.*?)(\d+)$/', $item['from_serial'], $fromParts);
//             preg_match('/^(.*?)(\d+)$/', $item['to_serial'], $toParts);

//             $prefix = $fromParts[1] ?? '';
//             $fromNum = (int) ($fromParts[2] ?? 0);
//             $toNum = (int) ($toParts[2] ?? 0);

//             for ($i = $fromNum; $i <= $toNum; $i++) {
//                 $serial = $prefix . $i;

//                 $savedItem = SparepartPurchaseItem::create([
//                     'purchase_id' => $purchase->id,
//                     'product_id' => $item['product_id'] ?? null,
//                     'sparepart_id' => $item['sparepart_id'],
//                     'quantity' => 1,
//                     'warranty_status' => $item['warranty_status'] ?? null,
//                     'serial_no' => $serial,
//                     'from_serial' => $item['from_serial'],
//                     'to_serial' => $item['to_serial'],
//                 ]);

//                 $allItems[] = $savedItem;
//             }
//         } 
//         // 2️⃣ Individual serials (from modal)
//         elseif (!empty($item['serial_no'])) {
//             $serials = array_filter(array_map('trim', explode(',', $item['serial_no'])));
//             foreach ($serials as $serial) {
//                 $savedItem = SparepartPurchaseItem::create([
//                     'purchase_id' => $purchase->id,
//                     'product_id' => $item['product_id'] ?? null,
//                     'sparepart_id' => $item['sparepart_id'],
//                     'quantity' => 1,
//                     'warranty_status' => $item['warranty_status'] ?? null,
//                     'serial_no' => $serial,
//                     'from_serial' => null,
//                     'to_serial' => null,
//                 ]);

//                 $allItems[] = $savedItem;
//             }
//         } 
//         // 3️⃣ Only quantity-based (no serials)
//         else {
//             if (!empty($item['quantity']) && $item['quantity'] > 0) {
//                 $savedItem = SparepartPurchaseItem::create([
//                     'purchase_id' => $purchase->id,
//                     'product_id' => $item['product_id'] ?? null,
//                     'sparepart_id' => $item['sparepart_id'],
//                     'quantity' => $item['quantity'],
//                     'warranty_status' => $item['warranty_status'] ?? null,
//                     'serial_no' => null,
//                     'from_serial' => null,
//                     'to_serial' => null,
//                 ]);

//                 $allItems[] = $savedItem;
//             }
//         }
//     }

//     return response()->json([
//         'message' => 'Spare part purchase updated successfully',
//         'purchase_id' => $purchase->id,
//         'items' => $allItems,
//     ], 200);
// }

// App\Http\Controllers\SparepartController.php
public function availableSerials(Request $request)
{
    $purchaseId  = $request->query('purchase_id');   // 👈 add this
    $sparepartId = $request->query('sparepart_id');
    $productId   = $request->query('product_id');

    $query = \DB::table('sparepart_purchase_items')
        ->where('sparepart_id', $sparepartId)
        ->where('product_id', $productId);

    if ($purchaseId) {
        $query->where('purchase_id', $purchaseId);  // 👈 filter correctly
    }

    $serials = $query->orderBy('serial_no', 'asc')
        ->pluck('serial_no')
        ->toArray();

    return response()->json([
        'serials' => $serials
    ]);
}
   public function show($id)
{
 $purchase = SparepartPurchase::with(['vendor', 'items.product', 'items.sparepart'])->find($id);


    if (!$purchase) {
        return response()->json([
            'message' => 'Purchase not found'
        ], 404);
    }
    $totalQuantity = $purchase->items->sum('quantity');

    $response = [
        'id' => $purchase->id,
        'vendor' => $purchase->vendor->vendor ?? null, 
        'challan_no' => $purchase->challan_no,
        'challan_date' => $purchase->challan_date,
        'total_quantity' => $totalQuantity,
'items' => $purchase->items->map(function ($item) {
    return [
        'id' => $item->id,
        'product' => $item->product->name ?? null,        // product name
        'sparepart' => $item->sparepart->name ?? null,    // sparepart name
        'serial_numbers' => $item->serial_no 
            ? explode(',', $item->serial_no) 
            : [],
        'quantity' => $item->quantity,
    ];
}),

    ];

    return response()->json($response);
}


public function view()
{
    // Get latest 10 purchases with vendor + items + sparepart
    $purchases = SparepartPurchase::with(['vendor', 'items.sparepart'])
        ->orderBy('challan_date', 'desc')
        ->take(10)
        ->get();

    // Flatten the purchases into rows (vendor, challan, product, quantity)
    $response = [];

    foreach ($purchases as $purchase) {
        foreach ($purchase->items as $item) {
            $response[] = [
                'id' => $purchase->id,
                'vendor' => $purchase->vendor->vendor ?? null,
                'challan_date' => $purchase->challan_date,
                'product' => $item->sparepart->name ?? null,
                'quantity' => $item->quantity,
            ];
        }
    }

    return response()->json($response);
}

  public function components()
{
    // Aggregate quantities of spareparts
    $parts = DB::table('sparepart_purchase_items as spi')
        ->join('spareparts as sp', 'spi.sparepart_id', '=', 'sp.id')
        ->select(
            'sp.id',
            'sp.name',
            DB::raw('SUM(spi.quantity) as total_quantity')
        )
        ->groupBy('sp.id', 'sp.name')
        ->get();

    if ($parts->isEmpty()) {
        return response()->json([
            'success' => true,
            'data' => [
                'spare_parts' => [],
                'available_vci_boards_possible' => 0,
                'max_vci_boards_possible' => 0
            ]
        ]);
    }

    // ✅ Requirement per VCI
    $requirements = [
        'PCB BOARD'     => 1,
        'BOLT'          => 4,
        'Screw'         => 4,
        'End Plate'     => 1,
        'Rubber Case'   => 1,
        'Nut'           => 4,
        'White Panel'   => 2,
        'OBD Connector' => 1,
        'Red Rubber'    => 1,
        'Mahle Sticker' => 1,
        'Side Sticker'  => 2,
    ];

    $spareParts = [];
    $boardsPossibleArr = [];

    foreach ($parts as $p) {
        $requiredQty = $requirements[$p->name] ?? 1; // default 1
        $boards_possible = intdiv($p->total_quantity, $requiredQty);

        $boardsPossibleArr[] = $boards_possible;

        $spareParts[] = [
            'id' => $p->id,
            'name' => $p->name,
            'total_quantity' => (int)$p->total_quantity,
            'required_per_vci' => $requiredQty,
            'boards_possible' => $boards_possible,
            'status' => $boards_possible > 0 ? 'Available' : 'Unavailable'
        ];
    }

    // 🔹 Max boards possible = bottleneck part
    $maxBoards = min($boardsPossibleArr);

    // 🔹 Available boards possible (ignoring unavailable parts)
    $availableBoards = collect($spareParts)
        ->where('boards_possible', '>', 0)
        ->min('boards_possible') ?? 0;

    return response()->json([
        'success' => true,
        'data' => [
            'spare_parts' => $spareParts,
            'available_vci_boards_possible' => $availableBoards,
            'max_vci_boards_possible' => $maxBoards
        ]
    ]);
}
public function getAllSeriesCounts()
{
    $allSeries = ['5-series', '7-series', '8-series', '9-series', '0-series'];

    $results = [];

    foreach ($allSeries as $series) {
        $results[] = $this->calculateSeriesData($series);
    }

    return response()->json([
        'success' => true,
        'data' => $results
    ]);
}

private function calculateSeriesData($series)
{
    $baseParts = [
        ['name' => 'BOLT', 'required_per_vci' => 4],
        ['name' => 'End Plate', 'required_per_vci' => 1],
        ['name' => 'Mahle Sticker', 'required_per_vci' => 1],
        ['name' => 'Nut', 'required_per_vci' => 4],
        ['name' => 'OBD Connector', 'required_per_vci' => 1],
    ];

    // Series-specific parts
    if (str_contains($series, '5')) {
        $baseParts[] = ['name' => 'PCB Board', 'required_per_vci' => 1];
        $baseParts[] = ['name' => 'Boot Rubber', 'required_per_vci' => 1];
        $baseParts[] = ['name' => 'Red Rubber', 'required_per_vci' => 1];
    } elseif (str_contains($series, '7')) {
        $baseParts[] = ['name' => 'PCB Board', 'required_per_vci' => 1];
        $baseParts[] = ['name' => 'Grey Boot Rubber', 'required_per_vci' => 1];
    }

    // Calculate available quantities based on series
    $parts = collect($baseParts)->map(function ($part) use ($series) {

        // Look for PCB Board purchased for that series only
        $query = \DB::table('sparepart_purchase_items as spi')
            ->join('spareparts as sp', 'spi.sparepart_id', '=', 'sp.id')
            ->leftJoin('product as p', 'spi.product_id', '=', 'p.id')
            ->whereRaw('LOWER(sp.name) = ?', [strtolower($part['name'])]);

        // Add series filter only for PCB Board (or any series-based part)
        if (strtolower($part['name']) === 'pcb board') {
            $query->where('p.name', 'LIKE', "%{$series}%");
        }

        $availableQty = $query->sum('spi.quantity');

        $boardsPossible = $part['required_per_vci'] > 0
            ? intdiv($availableQty, $part['required_per_vci'])
            : 0;

        return [
            'name' => $part['name'],
            'available_quantity' => $availableQty,
            'required_per_vci' => $part['required_per_vci'],
            'boards_possible' => $boardsPossible,
        ];
    });

    return [
        'series' => $series,
        'spare_parts' => $parts->values(),
        'max_vci_possible' => $parts->min('boards_possible'),
        'shortages' => [],
    ];
}








}
