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
    
public function store(Request $request)
{

    $request->validate([
        'vendor_id' => 'required|integer|exists:vendors,id',
        'challan_no' => 'required|string|max:50',
        'challan_date' => 'required|date',
        'items' => 'required|array|min:1',
        'items.*.product_id' => 'nullable|integer|exists:product,id',
        'items.*.sparepart_id' => 'required|integer|exists:spareparts,id',
        'items.*.quantity' => 'required|integer|min:1',
        'items.*.warranty_status' => 'nullable|string|max:50',
        'items.*.serial_no' => 'nullable|string|max:100',      
        'items.*.from_serial' => 'nullable|string|max:50',      
        'items.*.to_serial' => 'nullable|string|max:50',        
    ]);

    $purchase = SparepartPurchase::create([
        'vendor_id' => $request->vendor_id,
        'challan_no' => $request->challan_no,
        'challan_date' => $request->challan_date,
        'quantity' => collect($request->items)->sum('quantity'),
        'created_by' => auth()->id(),
         
    ]);

    $allItems = []; 

    foreach ($request->items as $item) {

        if (!empty($item['from_serial']) && !empty($item['to_serial'])) {

            preg_match('/^(.*?)(\d+)$/', $item['from_serial'], $fromParts);
            preg_match('/^(.*?)(\d+)$/', $item['to_serial'], $toParts);

            $prefix = $fromParts[1] ?? '';
            $fromNum = (int) ($fromParts[2] ?? 0);
            $toNum = (int) ($toParts[2] ?? 0);

            for ($i = $fromNum; $i <= $toNum; $i++) {
                $serial = $prefix . $i;

                $savedItem = SparepartPurchaseItem::create([
                    'purchase_id' => $purchase->id,
                    'product_id' => $item['product_id'] ?? null,
                    'sparepart_id' => $item['sparepart_id'],
                    'quantity' => 1,
                    'warranty_status' => $item['warranty_status'] ?? null,
                    'serial_no' => $serial,
                    'from_serial' => $item['from_serial'],
                    'to_serial' => $item['to_serial'],
                      'created_by' => auth()->id(),
          
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
                    'purchase_id' => $purchase->id,
                    'product_id' => $item['product_id'] ?? null,
                    'sparepart_id' => $item['sparepart_id'],
                    'quantity' => 1,
                    'warranty_status' => $item['warranty_status'] ?? null,
                    'serial_no' => $serial,
                    'from_serial' => null,
                    'to_serial' => null,
                ]);

                $allItems[] = $savedItem;
            }
        }
     
        else {
            $savedItem = SparepartPurchaseItem::create([
                'purchase_id' => $purchase->id,
                'product_id' => $item['product_id'] ?? null,
                'sparepart_id' => $item['sparepart_id'],
                'quantity' => $item['quantity'],
                'warranty_status' => $item['warranty_status'] ?? null,
                'serial_no' => null,
                'from_serial' => null,
                'to_serial' => null,
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
    ->join('vendors as v', 'p.vendor_id', '=', 'v.id')
    ->leftJoin('sparepart_purchase_items as pi', 'p.id', '=', 'pi.purchase_id')
    ->leftJoin('product as c', 'pi.product_id', '=', 'c.id')
    ->leftJoin('spareparts as s', 'pi.sparepart_id', '=', 's.id')
    ->select(
        'p.id as purchase_id',
        'p.challan_no',
        'p.challan_date',
        'v.id as vendor_id',
        'v.vendor as vendor_name',
        'pi.id as item_id',
        'pi.quantity as item_quantity',
        'pi.serial_no',
        'pi.warranty_status',
        'c.id as product_id',
        'c.name as product_name',
        's.id as sparepart_id',
        's.name as sparepart_name',
        DB::raw('SUM(pi.quantity) OVER (PARTITION BY p.id) as total_quantity') // ✅ aggregate
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


public function update(Request $request, $id)
{
    $request->validate([
        'vendor_id' => 'required|integer|exists:vendors,id',
        'challan_no' => 'required|string|max:50',
        'challan_date' => 'required|date',
        'items' => 'required|array|min:1',
        'items.*.product_id' => 'nullable|integer|exists:product,id',
        'items.*.sparepart_id' => 'required|integer|exists:spareparts,id',
        'items.*.quantity' => 'required|integer|min:1',
        'items.*.warranty_status' => 'nullable|string|max:50',
        'items.*.serial_no' => 'nullable|string|max:100',      
        'items.*.from_serial' => 'nullable|string|max:50',      
        'items.*.to_serial' => 'nullable|string|max:50',
    ]);

    $purchase = SparepartPurchase::findOrFail($id);

    // Update purchase
    $purchase->update([
        'vendor_id' => $request->vendor_id,
        'challan_no' => $request->challan_no,
        'challan_date' => $request->challan_date,
        'quantity' => collect($request->items)->sum('quantity'),
    ]);

    $purchase->items()->delete();

    $allItems = [];

    foreach ($request->items as $item) {

        if (!empty($item['from_serial']) && !empty($item['to_serial'])) {

            preg_match('/^(.*?)(\d+)$/', $item['from_serial'], $fromParts);
            preg_match('/^(.*?)(\d+)$/', $item['to_serial'], $toParts);

            $prefix = $fromParts[1] ?? '';
            $fromNum = (int) ($fromParts[2] ?? 0);
            $toNum = (int) ($toParts[2] ?? 0);

            for ($i = $fromNum; $i <= $toNum; $i++) {
                $serial = $prefix . $i;

                $savedItem = SparepartPurchaseItem::create([
                    'purchase_id' => $purchase->id,
                    'product_id' => $item['product_id'] ?? null,
                    'sparepart_id' => $item['sparepart_id'],
                    'quantity' => 1,
                    'warranty_status' => $item['warranty_status'] ?? null,
                    'serial_no' => $serial,
                    'from_serial' => $item['from_serial'],
                    'to_serial' => $item['to_serial'],
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
                    'purchase_id' => $purchase->id,
                    'product_id' => $item['product_id'] ?? null,
                    'sparepart_id' => $item['sparepart_id'],
                    'quantity' => 1,
                    'warranty_status' => $item['warranty_status'] ?? null,
                    'serial_no' => $serial,
                    'from_serial' => null,
                    'to_serial' => null,
                ]);

                $allItems[] = $savedItem;
            }
        }

        else {
            $savedItem = SparepartPurchaseItem::create([
                'purchase_id' => $purchase->id,
                'product_id' => $item['product_id'] ?? null,
                'sparepart_id' => $item['sparepart_id'],
                'quantity' => $item['quantity'],
                'warranty_status' => $item['warranty_status'] ?? null,
                'serial_no' => null,
                'from_serial' => null,
                'to_serial' => null,
            ]);

            $allItems[] = $savedItem;
        }
    }

    return response()->json([
        'message' => 'Spare part purchase updated successfully',
        'purchase_id' => $purchase->id,
        'items' => $allItems,
    ], 200);
}




}
