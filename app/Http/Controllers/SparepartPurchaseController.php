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

class SparepartPurchaseController extends Controller
{

public function store(Request $request)
{
    $request->validate([
        'vendor_id' => 'required|integer|exists:vendors,id',
        'challan_no' => 'required|string|max:50|unique:sparepart_purchase,challan_no',
        'challan_date' => ['required', 'date_format:d-m-Y', 'before_or_equal:today'],
        'received_date' => ['nullable', 'date_format:d-m-Y', 'before_or_equal:today'],
        'items' => 'required|array|min:1',
        'items.*.product_id' => 'nullable|integer|exists:product,id',
        'items.*.sparepart_id' => 'nullable|integer|exists:spareparts,id',
        'items.*.quantity' => 'required|integer|min:1',
        'items.*.warranty_status' => 'nullable|string|max:50',
        'items.*.serial_no' => 'nullable|string|max:100',
        'items.*.from_serial' => ['nullable','regex:/^\d{1,6}$/'],
        'items.*.to_serial' => ['nullable','regex:/^\d{1,6}$/'],
        'image_recipient' => 'nullable|image|mimes:jpg,jpeg,png,gif|max:2048',
        'image_challan_1' => 'nullable|image|mimes:jpg,jpeg,png,gif|max:2048',
        'image_challan_2' => 'nullable|image|mimes:jpg,jpeg,png,gif|max:2048',
    ]);

    $allSerials = [];

    // Collect all serials
    foreach ($request->items as $item) {
        if (!empty($item['serial_no'])) {
            $serials = array_map('trim', explode(',', $item['serial_no']));
            foreach ($serials as $s) if ($s !== '') $allSerials[] = strtoupper($s);
        }

        if (!empty($item['from_serial']) && !empty($item['to_serial'])) {
            preg_match('/^(.*?)(\d+)$/', $item['from_serial'], $fromParts);
            preg_match('/^(.*?)(\d+)$/', $item['to_serial'], $toParts);

            $prefix = $fromParts[1] ?? '';
            $fromNum = (int)($fromParts[2] ?? 0);
            $toNum = (int)($toParts[2] ?? 0);
            $padLength = strlen($fromParts[2] ?? '0');

            for ($i = $fromNum; $i <= $toNum; $i++) {
                $allSerials[] = strtoupper($prefix . str_pad($i, $padLength, '0', STR_PAD_LEFT));
            }
        }
    }

    // Check duplicate serials in request
    $duplicates = array_diff_assoc($allSerials, array_unique($allSerials));
    if (!empty($duplicates)) {
        return response()->json(['errors' => ['items' => ["Duplicate serial(s) in request: " . implode(', ', $duplicates)]]], 422);
    }

    // Check against DB
    if (!empty($allSerials)) {
        $exists = DB::table('sparepart_purchase_items')
            ->whereIn(DB::raw('UPPER(serial_no)'), $allSerials)
            ->pluck('serial_no')
            ->map(fn($s) => strtoupper($s))
            ->toArray();

        if (!empty($exists)) {
            return response()->json(['errors' => ['items' => ["Serial(s) already exist in DB: " . implode(', ', $exists)]]], 422);
        }
    }

    // Handle images
    $images = [];
    foreach (['image_recipient', 'image_challan_1', 'image_challan_2'] as $img) {
        if ($request->hasFile($img)) {
            $file = $request->file($img);
            $filename = time() . '_' . $file->getClientOriginalName();
            $file->storeAs('sparepart_images', $filename, 'public');
            $images[$img] = 'storage/sparepart_images/' . $filename;
        }
    }

    // Create purchase
    $purchase = SparepartPurchase::create([
        'vendor_id' => $request->vendor_id,
        'challan_no' => $request->challan_no,
        'challan_date' => Carbon::createFromFormat('d-m-Y', $request->challan_date)->format('Y-m-d'),
        'received_date' => $request->received_date ? Carbon::createFromFormat('d-m-Y', $request->received_date)->format('Y-m-d') : null,
        'document_recipient' => $images['image_recipient'] ?? null,
        'document_challan_1' => $images['image_challan_1'] ?? null,
        'document_challan_2' => $images['image_challan_2'] ?? null,
        'quantity' => collect($request->items)->sum('quantity'),
        'created_by' => auth()->id(),
    ]);

    $allItems = [];

    // Save items
    foreach ($request->items as $item) {
        if (!empty($item['from_serial']) && !empty($item['to_serial'])) {
            preg_match('/^(.*?)(\d+)$/', $item['from_serial'], $fromParts);
            preg_match('/^(.*?)(\d+)$/', $item['to_serial'], $toParts);

            $prefix = $fromParts[1] ?? '';
            $fromNum = (int)($fromParts[2] ?? 0);
            $toNum = (int)($toParts[2] ?? 0);
            $padLength = strlen($fromParts[2] ?? '0');

            for ($i = $fromNum; $i <= $toNum; $i++) {
                $serial = strtoupper($prefix . str_pad($i, $padLength, '0', STR_PAD_LEFT));
                $allItems[] = SparepartPurchaseItem::create([
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
            }
        } elseif (!empty($item['serial_no'])) {
            $serials = explode(',', $item['serial_no']);
            foreach ($serials as $serial) {
                $serial = strtoupper(trim($serial));
                if ($serial === '') continue;

                $allItems[] = SparepartPurchaseItem::create([
                    'purchase_id' => $purchase->id,
                    'product_id' => $item['product_id'] ?? null,
                    'sparepart_id' => $item['sparepart_id'],
                    'quantity' => 1,
                    'warranty_status' => $item['warranty_status'] ?? null,
                    'serial_no' => $serial,
                    'from_serial' => null,
                    'to_serial' => null,
                    'created_by' => auth()->id(),
                ]);
            }
        } else {
            $allItems[] = SparepartPurchaseItem::create([
                'purchase_id' => $purchase->id,
                'product_id' => $item['product_id'] ?? null,
                'sparepart_id' => $item['sparepart_id'],
                'quantity' => $item['quantity'],
                'warranty_status' => $item['warranty_status'] ?? null,
                'serial_no' => null,
                'from_serial' => null,
                'to_serial' => null,
                'created_by' => auth()->id(),
            ]);
        }
    }

    return response()->json([
        'message' => 'Spare part purchase saved successfully',
        'purchase_id' => $purchase->id,
        'items' => $allItems,
        'received_date' => $purchase->received_date,
        'documents' => $images,
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
    $purchase = SparepartPurchase::with(['items.product', 'items.sparepart', 'vendor'])->findOrFail($id);

    $response = [
        'id' => $purchase->id,
        'vendor_id' => $purchase->vendor_id,
        'vendor_name' => $purchase->vendor->vendor ?? null,
        'challan_no' => $purchase->challan_no,
        'challan_date' => $purchase->challan_date,
        'received_date' => $purchase->received_date,
        'quantity' => $purchase->quantity,
        'document_recipient' => $purchase->document_recipient,
        'document_challan_1' => $purchase->document_challan_1,
        'document_challan_2' => $purchase->document_challan_2,
        'items' => $purchase->items->map(function ($item) {
            return [
                'id' => $item->id,
                'product_id' => $item->product_id,
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
    $request->validate([
        'vendor_id' => 'required|integer|exists:vendors,id',
        'challan_no' => 'required|string|max:50',
        'challan_date' => ['required', 'date_format:d-m-Y', 'before_or_equal:today'],
        'received_date' => ['nullable', 'date_format:d-m-Y', 'before_or_equal:today'],
        'items' => 'nullable|array|min:1',
        'items.*.id' => 'nullable|integer',
        'items.*.product_id' => 'nullable|integer|exists:product,id',
        'items.*.sparepart_id' => 'nullable|integer|exists:spareparts,id',
        'items.*.quantity' => 'required|integer|min:1',
        'items.*.warranty_status' => 'nullable|string|max:50',
        'items.*.serials' => 'nullable|array',
        'items.*.from_serial' => 'nullable|string|max:100',
        'items.*.to_serial' => 'nullable|string|max:100',
        'image_recipient' => 'nullable|image|mimes:jpg,jpeg,png,gif|max:2048',
        'image_challan_1' => 'nullable|image|mimes:jpg,jpeg,png,gif|max:2048',
        'image_challan_2' => 'nullable|image|mimes:jpg,jpeg,png,gif|max:2048',
        'deleted_ids' => 'nullable|array',
    ]);

    $purchase = SparepartPurchase::findOrFail($id);

    // Handle images
    $images = [];
    foreach (['image_recipient', 'image_challan_1', 'image_challan_2'] as $img) {
        if ($request->hasFile($img)) {
            $file = $request->file($img);
            $filename = time() . '_' . $file->getClientOriginalName();
            $file->storeAs('sparepart_images', $filename, 'public');
            $images[$img] = 'storage/sparepart_images/' . $filename;
        }
    }

    // Update purchase
    $purchase->update(array_merge([
        'vendor_id' => $request->vendor_id,
        'challan_no' => $request->challan_no,
        'challan_date' => Carbon::createFromFormat('d-m-Y', $request->challan_date)->format('Y-m-d'),
        'received_date' => $request->received_date ? Carbon::createFromFormat('d-m-Y', $request->received_date)->format('Y-m-d') : null,
    ], $images));

    $payloadItemIds = collect($request->items)->pluck('id')->filter()->all();
    $deletedIds = $request->deleted_ids ?? [];

    // Delete removed items
    SparepartPurchaseItem::where('purchase_id', $purchase->id)
        ->whereNotIn('id', $payloadItemIds)
        ->orWhereIn('id', $deletedIds)
        ->delete();

    $totalQuantity = 0;

    foreach ($request->items as $item) {
        $serials = $item['serials'] ?? [];

        // Generate serials if from/to provided
        if (empty($serials) && $item['from_serial'] && $item['to_serial']) {
            $serials = [];
            $start = intval($item['from_serial']);
            $end = intval($item['to_serial']);
            for ($i = $start; $i <= $end; $i++) {
                $serials[] = str_pad($i, strlen($item['from_serial']), '0', STR_PAD_LEFT);
            }
        }

        if (!empty($serials)) {
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
                        'from_serial' => $item['from_serial'] ?? null,
                        'to_serial' => $item['to_serial'] ?? null,
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
                    'from_serial' => $item['from_serial'] ?? null,
                    'to_serial' => $item['to_serial'] ?? null,
                ]
            );
            $totalQuantity += $item['quantity'] ?? 0;
        }
    }

    $purchase->update(['quantity' => $totalQuantity]);

    $purchaseItems = SparepartPurchaseItem::where('purchase_id', $purchase->id)->get()
        ->groupBy(fn($item) => $item->sparepart_id . '_' . ($item->product_id ?? 'null'))
        ->map(function ($group) {
            $first = $group->first();
            $serials = $group->pluck('serial_no')->filter(fn($s) => $s !== null && $s !== '')->sort()->values()->toArray();
            return [
                'id' => $first->id,
                'sparepart_id' => $first->sparepart_id,
                'product_id' => $first->product_id,
                'qty' => $serials ? count($serials) : $first->quantity,
                'warranty_status' => $first->warranty_status,
                'from_serial' => $first->from_serial,
                'to_serial' => $first->to_serial,
                'serials' => $serials,
            ];
        })->values();

    return response()->json([
        'message' => 'Spare part purchase updated successfully',
        'purchase_id' => $purchase->id,
        'quantity' => $totalQuantity,
        'items' => $purchaseItems,
        'received_date' => $purchase->received_date,
        'documents' => $images,
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
        'received_date' => $purchase->received_date, // <-- add received date
        'total_quantity' => $totalQuantity,
        'document_recipient' => $purchase->document_recipient, // <-- add document fields
        'document_challan_1' => $purchase->document_challan_1,
        'document_challan_2' => $purchase->document_challan_2,
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


private function calculateSeriesData($series)
{
    $baseParts = [
        ['name' => 'Bolt', 'required_per_vci' => 4],
        ['name' => 'End Plate', 'required_per_vci' => 1],
        ['name' => 'Mahle Sticker', 'required_per_vci' => 1],
        ['name' => 'Nut', 'required_per_vci' => 4],
        ['name' => 'OBD Connector', 'required_per_vci' => 1],
        ['name' => 'Enclosure', 'required_per_vci' => 1],
        ['name' => 'Rubber Case', 'required_per_vci' => 1],
        ['name' => 'White Panel', 'required_per_vci' => 2],
    ];

    if (str_contains($series, '5')) {
        $baseParts[] = ['name' => 'PCB Board', 'required_per_vci' => 1];
        $baseParts[] = ['name' => 'Boot Rubber', 'required_per_vci' => 1];
        $baseParts[] = ['name' => 'Red Rubber', 'required_per_vci' => 1];
    } elseif (str_contains($series, '7')) {
        $baseParts[] = ['name' => 'PCB Board', 'required_per_vci' => 1];
        $baseParts[] = ['name' => 'Grey Boot Rubber', 'required_per_vci' => 1];
    } elseif (str_contains($series, '8') || str_contains($series, '9') || str_contains($series, '0')) {
        $baseParts[] = ['name' => 'PCB Board', 'required_per_vci' => 1];
    }

    $seriesPrefix = substr($series, 0, 1); // '7' for 7-series
    $assembledVCIs = \DB::table('inventory')
        ->whereNull('deleted_by')
        ->where('serial_no', 'LIKE', $seriesPrefix . '%')  // ✅ Match only its own series
        ->count();

    $parts = collect($baseParts)->map(function ($part) use ($series, $assembledVCIs) {
        $purchasedQty = \DB::table('sparepart_purchase_items as spi')
            ->join('spareparts as sp', 'spi.sparepart_id', '=', 'sp.id')
            ->leftJoin('product as p', 'spi.product_id', '=', 'p.id')
            ->whereRaw('LOWER(sp.name) = ?', [strtolower($part['name'])])
            ->when(strtolower($part['name']) === 'pcb board', function ($query) use ($series) {
                $query->where('p.name', 'LIKE', "%{$series}%");
            })
            ->sum('spi.quantity');

        $usedQty = $assembledVCIs * $part['required_per_vci'];
        $availableQty = max($purchasedQty - $usedQty, 0);
        $boardsPossible = $part['required_per_vci'] > 0 ? intdiv($availableQty, $part['required_per_vci']) : 0;

        return [
            'name' => $part['name'],
            'purchased_quantity' => $purchasedQty,
            'used_quantity' => $usedQty,
            'available_quantity' => $availableQty,
            'required_per_vci' => $part['required_per_vci'],
            'boards_possible' => $boardsPossible,
        ];
    });

    return [
        'series' => $series,
        'spare_parts' => $parts->values(),
        'max_vci_possible' => $parts->min('boards_possible'),
        'shortages' => $parts->filter(fn($p) => $p['boards_possible'] === 0)->values(),
    ];
}

}