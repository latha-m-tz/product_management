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
use Carbon\Carbon;
use Illuminate\Validation\Rule; 
class SparepartPurchaseController extends Controller
{

public function store(Request $request)
{
    $request->validate([
        'vendor_id' => 'required|integer|exists:vendors,id',
        'challan_no' => 'required|string|max:50|unique:sparepart_purchase,challan_no',
        'tracking_number' => 'nullable|string|max:100',
        'challan_date' => ['required','before_or_equal:today'],
        'received_date' => ['required','before_or_equal:today'],
        'courier_name' => 'nullable|string|max:100',
        'items' => 'required|array|min:1',
        'items.*.product_id' => 'nullable|integer|exists:product,id',
        'items.*.sparepart_id' => 'nullable|integer|exists:spareparts,id',
        'items.*.quantity' => 'required|integer|min:1',
        'items.*.warranty_status' => 'nullable|string|max:50',
        'items.*.serial_no' => 'nullable|string|max:100',
        'items.*.from_serial' => ['nullable', 'regex:/^\d{1,6}$/'],
        'items.*.to_serial' => ['nullable', 'regex:/^\d{1,6}$/'],
            'document_recipient.*' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:2048',
    ]);
 
    DB::beginTransaction();
 
    try {
        $recipientFiles = [];
        $challanFiles = [];
 
        // Handle recipient documents
        if ($request->hasFile('document_recipient')) {
            foreach ($request->file('document_recipient') as $file) {
                $filename = time() . '_' . uniqid() . '_' . $file->getClientOriginalName();
                $file->move(public_path('sparepart_images/recipients'), $filename);
                $recipientFiles[] = 'sparepart_images/recipients/' . $filename;
            }
        }
 
        // Handle challan documents
        // if ($request->hasFile('document_challan')) {
        //     foreach ($request->file('document_challan') as $file) {
        //         $filename = time() . '_' . uniqid() . '_' . $file->getClientOriginalName();
        //         $file->move(public_path('sparepart_images/challans'), $filename);
        //         $challanFiles[] = 'sparepart_images/challans/' . $filename;
        //     }
        // }
 
        // Create the main purchase
        $purchase = SparepartPurchase::create([
            'vendor_id' => $request->vendor_id,
            'challan_no' => $request->challan_no,
            'tracking_number' => $request->tracking_number,
            'challan_date' => $request->challan_date,
            'received_date' => $request->received_date,
            'courier_name' => $request->courier_name,
          'document_recipient' => $recipientFiles,
 
            'created_by' => auth()->id(),
        ]);
 
        $serialsToInsert = [];
 
        foreach ($request->items as $item) {
            $from = $item['from_serial'] ?? null;
            $to = $item['to_serial'] ?? null;
 
            if ($from && $to) {
                for ($i = $from; $i <= $to; $i++) {
                    $serialsToInsert[] = [
                        'purchase_id' => $purchase->id,
                        'product_id' => $item['product_id'] ?? null,
                        'sparepart_id' => $item['sparepart_id'] ?? null,
                        'quantity' => 1,
                        'warranty_status' => $item['warranty_status'] ?? null,
                        'serial_no' => $i,
                        'created_by' => auth()->id(),
                    ];
                }
            } else {
                $serialsToInsert[] = [
                    'purchase_id' => $purchase->id,
                    'product_id' => $item['product_id'] ?? null,
                    'sparepart_id' => $item['sparepart_id'] ?? null,
                    'quantity' => $item['quantity'],
                    'warranty_status' => $item['warranty_status'] ?? null,
                    'serial_no' => $item['serial_no'] ?? null,
                    'created_by' => auth()->id(),
                ];
            }
        }
 
        // Check for duplicates in DB
        $serialNumbers = array_map(fn($s) => $s['serial_no'], $serialsToInsert);
        $duplicateSerials = SparepartPurchaseItem::whereIn('serial_no', $serialNumbers)->pluck('serial_no')->toArray();
 
        if (!empty($duplicateSerials)) {
            DB::rollBack();
            return response()->json([
                'message' => 'Duplicate serial numbers found',
                'duplicates' => $duplicateSerials,
            ], 422);
        }
 
        // Insert all items
        foreach ($serialsToInsert as $item) {
            SparepartPurchaseItem::create($item);
        }
 
        DB::commit();
 
        return response()->json([
            'message' => 'Spare part purchase saved successfully',
            'purchase_id' => $purchase->id,
            'tracking_number' => $purchase->tracking_number,
            'documents' => [
                'recipients' => $recipientFiles,
                // 'challans' => $challanFiles,
            ],
        ], 201);
 
    } catch (\Throwable $e) {
        DB::rollBack();
        return response()->json([
            'message' => 'Error saving purchase',
            'error' => $e->getMessage(),
        ], 500);
    }
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
                    // 'product_id'     => $item->product_id,
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
    $purchase = SparepartPurchase::with(['items.product', 'items.sparepart', 'vendor'])
        ->findOrFail($id);
 
    $response = [
        'id' => $purchase->id,
        'vendor_id' => $purchase->vendor_id,
        'vendor_name' => $purchase->vendor->vendor ?? null,
        'challan_no' => $purchase->challan_no,
        'tracking_number' => $purchase->tracking_number,
        'courier_name' => $purchase->courier_name,
        'challan_date' => $purchase->challan_date ? \Carbon\Carbon::parse($purchase->challan_date)->format('d-m-Y') : null,
        'received_date' => $purchase->received_date ? \Carbon\Carbon::parse($purchase->received_date)->format('d-m-Y') : null,
        'courier_name' => $purchase->courier_name,
 
    'document_recipient' => is_string($purchase->document_recipient)
        ? json_decode($purchase->document_recipient, true)
        : ($purchase->document_recipient ?? []),
 
    // 'document_challan' => is_string($purchase->document_challan)
    //     ? json_decode($purchase->document_challan, true)
    //     : ($purchase->document_challan ?? []),
 
 
        // Fetch items with related product and sparepart info
        'items' => $purchase->items->map(function ($item) {
            return [
                'id' => $item->id,
                // 'product_id' => $item->product_id,
                'product_name' => $item->product->name ?? null,
                'sparepart_id' => $item->sparepart_id,
                'sparepart_name' => $item->sparepart->name ?? null,
                'quantity' => $item->quantity,
                'warranty_status' => $item->warranty_status,
                'serial_no' => $item->serial_no,
                'from_serial' => $item->from_serial,
                'to_serial' => $item->to_serial,
            ];
        })->values(),
    ];
 
    return response()->json($response);
}


public function update(Request $request, $id)
{
    $purchase = SparepartPurchase::findOrFail($id);
 
    $request->validate([
        'vendor_id' => 'required|integer|exists:vendors,id',
        'challan_no' => [
            'required',
            'string',
            'max:50',
            Rule::unique('sparepart_purchase', 'challan_no')->ignore($purchase->id),
        ],
        'tracking_number' => 'nullable|string|max:100',
        'challan_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
        'received_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
        'courier_name' => 'nullable|string|max:100',
        'items' => 'nullable|array|min:1',
        'items.*.id' => 'nullable|integer',
        // 'items.*.product_id' => 'nullable|integer|exists:product,id',
        'items.*.sparepart_id' => 'nullable|integer|exists:spareparts,id',
        'items.*.quantity' => 'required|integer|min:1',
        'items.*.warranty_status' => 'nullable|string|max:50',
        'items.*.serials' => 'nullable|array',
        'deleted_ids' => 'nullable|array',
        'deleted_ids.*' => 'integer|exists:sparepart_purchase_items,id',
        'document_recipient' => 'nullable|array',
        'document_recipient.*' => 'file|mimes:jpg,jpeg,png,pdf|max:2048',
        'document_challan' => 'nullable|array',
'document_challan.*' => 'file|mimes:jpg,jpeg,png,pdf|max:2048', // max 10 MB per file
    ]);
 
    DB::beginTransaction();
 
    try {

$recipientFiles = $purchase->document_recipient ?? [];
$challanFiles = $purchase->document_challan ?? [];
 
if ($request->filled('removed_recipient_files')) {
    $removedRecipientFiles = json_decode($request->removed_recipient_files, true);
 
    $recipientFiles = array_values(array_diff($recipientFiles, $removedRecipientFiles));
}
 
if ($request->filled('removed_recipient_files')) {
    $removedRecipientFiles = json_decode($request->removed_recipient_files, true);
    $recipientFiles = array_values(array_diff($recipientFiles, $removedRecipientFiles));
}
 

 
// ✅ Handle new recipient documents (keep old + add new)
if ($request->hasFile('document_recipient')) {
    foreach ($request->file('document_recipient') as $file) {
        $filename = time() . '_' . uniqid() . '_' . $file->getClientOriginalName();
        $file->move(public_path('sparepart_images/recipients'), $filename);
        $recipientFiles[] = 'sparepart_images/recipients/' . $filename;
    }
}
 
// // ✅ Handle new challan documents (keep old + add new)
// if ($request->hasFile('document_challan')) {
//     foreach ($request->file('document_challan') as $file) {
//         $filename = time() . '_' . uniqid() . '_' . $file->getClientOriginalName();
//         $file->move(public_path('sparepart_images/challans'), $filename);
//         $challanFiles[] = 'sparepart_images/challans/' . $filename;
//     }
// }
 
        $purchase->update([
            'vendor_id' => $request->vendor_id,
            'challan_no' => $request->challan_no,
            'tracking_number' => $request->tracking_number,
            'challan_date' => Carbon::createFromFormat('Y-m-d', $request->challan_date)->format('Y-m-d'),
            'received_date' => Carbon::createFromFormat('Y-m-d', $request->received_date)->format('Y-m-d'),
            'courier_name' => $request->courier_name,
            'document_recipient' => $recipientFiles,
            'updated_by' => auth()->id(),
        ]);
 
        if (!empty($request->deleted_ids)) {
            SparepartPurchaseItem::whereIn('id', $request->deleted_ids)->delete();
        }
 
        $totalQuantity = 0;
 
        foreach ($request->items ?? [] as $item) {
            $serials = $item['serials'] ?? [];
            $serials = array_filter($serials); // remove empty strings
 
            // Check duplicates in request
            $duplicateInRequest = collect($serials)->duplicates()->all();
            if (!empty($duplicateInRequest)) {
                return response()->json([
                    'errors' => ['items' => ['Duplicate serial(s) in request: ' . implode(', ', $duplicateInRequest)]]
                ], 422);
            }
 
            // Check duplicates in DB excluding current purchase
            if (count($serials) > 0) {
                $existingSerials = SparepartPurchaseItem::whereIn('serial_no', $serials)
                    ->where('purchase_id', '!=', $purchase->id)
                    ->pluck('serial_no')
                    ->toArray();
 
                if (!empty($existingSerials)) {
                    return response()->json([
                        'errors' => ['items' => ['Serial(s) already exist in DB: ' . implode(', ', $existingSerials)]]
                    ], 422);
                }
            }
 
            SparepartPurchaseItem::where('purchase_id', $purchase->id)
                ->where('sparepart_id', $item['sparepart_id'])
                // ->when($item['product_id'], fn($q) => $q->where('product_id', $item['product_id']))
                ->whereNotIn('serial_no', $serials)
                ->delete();
 
            if (count($serials) > 0) {
                foreach ($serials as $serial) {
                    SparepartPurchaseItem::updateOrCreate(
                        [
                            'purchase_id' => $purchase->id,
                            'sparepart_id' => $item['sparepart_id'],
                            // 'product_id' => $item['product_id'] ?? null,
                            'serial_no' => $serial,
                        ],
                        [
                            'quantity' => 1,
                            'warranty_status' => $item['warranty_status'] ?? null,
                            'updated_by' => auth()->id(),
                        ]
                    );
                }
                $totalQuantity += count($serials);
            } else {
                SparepartPurchaseItem::updateOrCreate(
                    [
                        'purchase_id' => $purchase->id,
                        'sparepart_id' => $item['sparepart_id'],
                        'product_id' => $item['product_id'] ?? null,
                        'serial_no' => null,
                    ],
                    [
                        'quantity' => $item['quantity'] ?? 0,
                        'warranty_status' => $item['warranty_status'] ?? null,
                        'updated_by' => auth()->id(),
                    ]
                );
                $totalQuantity += $item['quantity'] ?? 0;
            }
        }
 
        $purchase->update(['quantity' => $totalQuantity]);
 
     
        $purchase->load('items.sparepart', 'items.product');
 
        $purchaseItems = $purchase->items
            ->groupBy(fn($item) => $item->sparepart_id . '_' . ($item->product_id ?? 'null'))
            ->map(fn($group) => [
                'id' => $group->first()->id,
                'sparepart_id' => $group->first()->sparepart_id,
                // 'product_id' => $group->first()->product_id,
                'qty' => $group->pluck('serial_no')->filter()->count() ?: $group->first()->quantity,
                'warranty_status' => $group->first()->warranty_status,
                'from_serial' => $group->pluck('serial_no')->filter()->sort()->values()->first() ?? null,
                'to_serial' => $group->pluck('serial_no')->filter()->sort()->values()->last() ?? null,
                'serials' => $group->pluck('serial_no')->filter()->sort()->values()->toArray(),
            ])->values();
 
        DB::commit();
 
        return response()->json([
            'message' => 'Spare part purchase updated successfully',
            // 'purchase_id' => $purchase->id,
            'quantity' => $totalQuantity,
            'items' => $purchaseItems,
            'documents' => [
                'recipients' => $recipientFiles,
                // 'challans' => $challanFiles,
            ],
        ], 200);
 
    } catch (\Throwable $e) {
        DB::rollBack();
        return response()->json(['message' => 'Update failed', 'error' => $e->getMessage()], 500);
    }
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


public function availableSerials(Request $request)
{
    $purchaseId  = $request->query('purchase_id');   
    $sparepartId = $request->query('sparepart_id');
    $productId   = $request->query('product_id');

    $query = \DB::table('sparepart_purchase_items')
        ->where('sparepart_id', $sparepartId)
        ->where('product_id', $productId);

    if ($purchaseId) {
        $query->where('purchase_id', $purchaseId);  
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
        'received_date' => $purchase->received_date, 
        'tracking_number' => $purchase->tracking_number,
        'courier_name' => $purchase->courier_name,
        'document_recipient' => $purchase->document_recipient,
        'items' => $purchase->items->map(function ($item) {
            return [
                'id' => $item->id,
                'product' => $item->product->name ?? null,
                'sparepart' => $item->sparepart->name ?? null,
                'serial_numbers' => $item->serial_no
                    ? explode(',', $item->serial_no)
                    : [],
                'quantity' => $item->quantity,
                'warranty_status' => $item->warranty_status,
                'from_serial' => $item->from_serial,
                'to_serial' => $item->to_serial,
            ];
        }),
    ];

    return response()->json($response);
}


public function view()
{
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
public function deleteItem($purchaseId, $itemId)
{
    $item = SparepartPurchaseItem::where('purchase_id', $purchaseId)
        ->where('id', $itemId)
        ->first();

    if (!$item) {
        return response()->json([
            'message' => 'No items found'
        ], 404);
    }

    $item->delete();

    return response()->json([
        'message' => 'Item deleted successfully',
        'deleted_item_id' => $itemId
    ]);
}

  public function components()
{
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
        'Pcb board'     => 1,
        'Bolt'          => 4,
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


// public function getSeriesSpareparts($series)
// {
//     $allSpareparts = Sparepart::all();

//     // Extract series core name: e.g., "7-series" from "vci(7-series)"
//     $seriesNameCore = preg_replace('/.*\((.*)\)/', '$1', $series);

//     // Get common spare parts
//     $commonParts = $allSpareparts->filter(fn($part) => $part->sparepart_usages && stripos($part->sparepart_usages, 'common') !== false);

//     // Get series-specific spare parts
//     $seriesParts = $allSpareparts->filter(fn($part) => $part->sparepart_usages && stripos($part->sparepart_usages, $seriesNameCore) !== false);

//     // Merge both
//     $partsToProcess = $commonParts->merge($seriesParts);

//     // Count assembled VCIs from inventory (not deleted)
//     $seriesPrefix = substr($seriesNameCore, 0, 1);
//     $assembledVCIs = \DB::table('inventory')
//         ->whereNull('deleted_by')
//         ->where('serial_no', 'LIKE', $seriesPrefix . '%')
//         ->count();

//     $parts = $partsToProcess->map(function ($part) use ($seriesNameCore, $assembledVCIs) {

//         // Total purchased quantity from sparepart_purchase_items
//         $purchasedQty = \DB::table('sparepart_purchase_items as spi')
//             ->where('spi.sparepart_id', $part->id)
//             ->sum('spi.quantity');

//         // Used quantity = assembled VCIs * required_per_vci
//         $requiredPerVCI = $part->required_per_vci ?? 1;
//         $usedQty = $assembledVCIs * $requiredPerVCI;

//         // Available quantity = purchased - used
//         $availableQty = max($purchasedQty - $usedQty, 0);

//         // Boards possible = how many more VCIs we can assemble with available quantity
//         $boardsPossible = $requiredPerVCI > 0 ? intdiv($availableQty, $requiredPerVCI) : 0;

//         return [
//             'id' => $part->id,
//             'name' => $part->name,
//             'purchased_quantity' => $purchasedQty,
//             'used_quantity' => $usedQty,
//             'available_quantity' => $availableQty,
//             'required_per_vci' => $requiredPerVCI,
//             'boards_possible' => $boardsPossible,
//             'sparepart_usages' => $part->sparepart_usages,
//         ];
//     });

//     return response()->json([
//         'series' => $series,
//         'spare_parts' => $parts->values(),
//         'max_vci_possible' => $parts->min('boards_possible'),
//         'shortages' => $parts->filter(fn($p) => $p['boards_possible'] === 0)->values(),
//     ]);
// }



public function getSeriesSpareparts($series)
{
    // Decode if frontend sends URL-encoded value like vci%285-series%29
    $series = urldecode($series);

    // Remove the product type in parentheses if present
    if (preg_match('/^(.*)\((.*)\)$/', $series, $matches)) {
        $series = trim($matches[1]); // Take only the name before parentheses
    }

    // Try to find the product by its name
    $product = Product::where('name', $series)
        ->whereNull('deleted_at')
        ->with('productTypes')
        ->first();

    if (!$product) {
        return response()->json(['message' => "Product '{$series}' not found"], 404);
    }

    // Get sparepart requirements JSON (array of {id, required_quantity})
    $sparepartRequirements = $product->sparepart_requirements ?? [];

    if (empty($sparepartRequirements)) {
        return response()->json([
            'product' => $product->name,
            'spare_parts' => [],
            'message' => 'No sparepart requirements found for this product.',
        ]);
    }

    // Fetch spareparts by IDs
    $sparepartIds = collect($sparepartRequirements)->pluck('id')->toArray();
    $spareparts = Sparepart::whereIn('id', $sparepartIds)->get();

    // Count all assembled VCIs (for usage calculation)
    $assembledVCIs = DB::table('inventory')
        ->whereNull('deleted_by')
        ->count();

    // Map spareparts with quantities
    $sparepartsMapped = $spareparts->map(function ($part) use ($sparepartRequirements, $assembledVCIs) {
        $requiredPerProduct = collect($sparepartRequirements)
            ->firstWhere('id', $part->id)['required_quantity'] ?? 0;

        $purchasedQty = DB::table('sparepart_purchase_items')
            ->where('sparepart_id', $part->id)
            ->sum('quantity');

        $usedQty = $assembledVCIs * $requiredPerProduct;
        $availableQty = max($purchasedQty - $usedQty, 0);

        return [
            'id' => $part->id,
            'code' => $part->code,
            'name' => $part->name,
            'sparepart_type' => $part->sparepart_type,
            'sparepart_usages' => $part->sparepart_usages,
            'required_per_vci' => $requiredPerProduct,
            'purchased_quantity' => $purchasedQty,
            'used_quantity' => $usedQty,
            'available_quantity' => $availableQty,
        ];
    });

    return response()->json([
        'series' => $product->name,
        'spare_parts' => $sparepartsMapped,
    ]);
}


}