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
use App\Models\Inventory;
use App\Models\SaleItem;

class SparepartPurchaseController extends Controller
{

public function store(Request $request)
{
    $request->validate([
        'vendor_id' => 'required|integer|exists:vendors,id',

        'challan_no' => [
            'required',
            'string',
            'max:50',
            function ($attribute, $value, $fail) {
                $exists = DB::table('sparepart_purchase')
                    ->where('challan_no', $value)
                    ->whereNull('deleted_at')  
                    ->exists();

                if ($exists) {
                    $fail("The $attribute has already been taken.");
                }
            }
        ],

        'tracking_number' => 'nullable|string|max:100',
        'challan_date'  => ['required', 'date'],
        'received_date' => ['required', 'date', 'after_or_equal:challan_date'],
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

        if ($request->hasFile('document_recipient')) {
            foreach ($request->file('document_recipient') as $file) {
                $filename = time() . '_' . uniqid() . '_' . $file->getClientOriginalName();
                $file->move(public_path('sparepart_images/recipients'), $filename);
                $recipientFiles[] = 'sparepart_images/recipients/' . $filename;
            }
        }

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

        foreach ($request->items as $index => $item) {

            $from = $item['from_serial'] ?? null;
            $to   = $item['to_serial'] ?? null;

            if ($from && $to) {

                for ($i = intval($from); $i <= intval($to); $i++) {
                    $serialsToInsert[] = [
                        'purchase_id' => $purchase->id,
                        'product_id' => $item['product_id'] ?? null,
                        'sparepart_id' => $item['sparepart_id'] ?? null,
                        'quantity' => 1,
                        'warranty_status' => $item['warranty_status'] ?? null,
                        'serial_no' => $i,
                        'from_serial' => $from,
                        'to_serial' => $to,
                        'group_index' => $index + 1,
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
                    'from_serial' => $from,
                    'to_serial' => $to,
                    'group_index' => $index + 1,
                    'created_by' => auth()->id(),
                ];
            }
        }

        $duplicateSerials = [];

        foreach ($serialsToInsert as $s) {
            if (empty($s['serial_no'])) continue;

            $query = SparepartPurchaseItem::where('serial_no', $s['serial_no'])
                ->where('sparepart_id', $s['sparepart_id'])
                ->whereNull('deleted_at');   

            if (!empty($s['product_id'])) {
                $query->where('product_id', $s['product_id']);
            } else {
                $query->whereNull('product_id');
            }

            if ($query->exists()) {
                $duplicateSerials[] = $s['serial_no'];
            }
        }

        if (!empty($duplicateSerials)) {
            $duplicateSerials = array_unique($duplicateSerials);

            DB::rollBack();
            return response()->json([
                'message' => 'The following serial numbers are already purchased: ' . implode(', ', $duplicateSerials),
                'duplicates' => $duplicateSerials,
            ], 422);
        }

        foreach ($serialsToInsert as $record) {
            SparepartPurchaseItem::create($record);
        }

        DB::commit();

        return response()->json([
            'message' => 'Spare part purchase saved successfully',
            'purchase_id' => $purchase->id,
            'tracking_number' => $purchase->tracking_number,
            'documents' => [
                'recipients' => $recipientFiles,
            ],
        ], 201);

    } catch (\Throwable $e) {
        DB::rollBack();

        return response()->json([
            'message' => 'Error saving purchase',
            'error' => $e->getMessage(),
            'line' => $e->getLine(),
        ], 500);
    }
}



public function getAvailableSpareparts(Request $request)
{ 
    
    $spareParts = DB::table('spareparts as s')
        ->select('s.id', 's.name', 's.sparepart_type')
        ->whereNull('s.deleted_at')
        ->whereNull('s.deleted_by')
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

        ->leftJoin('sparepart_purchase_items as pi', function ($join) {
            $join->on('p.id', '=', 'pi.purchase_id')
                 ->whereNull('pi.deleted_at');  
        })

        ->leftJoin('product as c', 'pi.product_id', '=', 'c.id')
        ->leftJoin('spareparts as s', 'pi.sparepart_id', '=', 's.id')
        ->select(
            'p.id as purchase_id',
            'p.challan_no',
            'p.challan_date',
            'v.id as vendor_id',

            // Vendor safe-value if vendor is deleted
            DB::raw("COALESCE(v.vendor, 'Deleted Vendor') as vendor_name"),

            'pi.id as item_id',
            'pi.quantity as item_quantity',
            'pi.serial_no',
            'pi.warranty_status',

            'c.id as product_id',
            'c.name as product_name',

            's.id as sparepart_id',
            's.name as sparepart_name',

            // Total quantity per purchase
            DB::raw('SUM(pi.quantity) OVER (PARTITION BY p.id) as total_quantity')
        )

        // ✅ ensure only active (non-deleted) purchases shown
        ->whereNull('p.deleted_at')

        ->orderBy('p.id', 'desc');

    $results = $query->get();

    $grouped = $results->groupBy('purchase_id')->map(function ($items) {
        $first = $items->first();

        return [
            'purchase_id'    => $first->purchase_id,
            'challan_no'     => $first->challan_no,
            'challan_date'   => $first->challan_date,
            'total_quantity' => $first->total_quantity,

            'vendor' => [
                'id'   => $first->vendor_id,
                'name' => $first->vendor_name,
            ],

            'items' => $items->map(function ($item) {
                return [
                    'item_id'        => $item->item_id,
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
    // Retrieve the purchase with related items and vendor
    $purchase = SparepartPurchase::with(['items.product', 'items.sparepart', 'vendor'])
        ->findOrFail($id);

    // Decode the document recipient if it's a JSON string
    $recipientFiles = is_string($purchase->document_recipient)
        ? json_decode($purchase->document_recipient, true)
        : ($purchase->document_recipient ?? []);

    // Prepare the response data
    $response = [
        'id' => $purchase->id,
        'vendor_id' => $purchase->vendor_id,
        'vendor_name' => $purchase->vendor->vendor ?? null,
        'challan_no' => $purchase->challan_no,
        'tracking_number' => $purchase->tracking_number,
        'courier_name' => $purchase->courier_name,
        'challan_date' => $purchase->challan_date ? \Carbon\Carbon::parse($purchase->challan_date)->format('d-m-Y') : null,
        'received_date' => $purchase->received_date ? \Carbon\Carbon::parse($purchase->received_date)->format('d-m-Y') : null,
        'document_recipient' => $recipientFiles,
        'items' => $purchase->items->map(function ($item) {
            // Fetch from_serial and to_serial from the item, and use serial_no if not set
            $fromSerial = $item->from_serial ?? $item->serial_no;
            $toSerial = $item->to_serial ?? $item->serial_no;

            return [
                'id' => $item->id,
                'product_name' => $item->product->name ?? null,
                'sparepart_id' => $item->sparepart_id,
                'sparepart_name' => $item->sparepart->name ?? null,
                'quantity' => $item->quantity,
                'warranty_status' => $item->warranty_status,
                'serial_no' => $item->serial_no,
                'from_serial' => $fromSerial,
                'to_serial' => $toSerial,
            ];
        })->values(),
    ];

    // Return the JSON response
    return response()->json($response);
}


public function update(Request $request, $id)
{
    $purchase = SparepartPurchase::findOrFail($id);

    /* ================= VALIDATION ================= */
    $request->validate([
        'vendor_id' => 'required|integer|exists:vendors,id',

        'challan_no' => [
            'required',
            'string',
            'max:50',
            function ($attribute, $value, $fail) use ($id) {
                $exists = DB::table('sparepart_purchase')
                    ->where('challan_no', $value)
                    ->where('id', '!=', $id)
                    ->whereNull('deleted_at')
                    ->exists();

                if ($exists) {
                    $fail("The $attribute has already been taken.");
                }
            }
        ],

        'tracking_number' => 'nullable|string|max:100',
        'challan_date'  => ['required', 'date', 'before_or_equal:today'],
        'received_date' => ['required', 'date', 'after_or_equal:challan_date'],
        'courier_name'  => 'nullable|string|max:100',

        'items' => 'required|array|min:1',
        'items.*.product_id' => 'nullable|integer|exists:product,id',
        'items.*.sparepart_id' => 'required|integer|exists:spareparts,id',
        'items.*.quantity' => 'required|integer|min:1',
        'items.*.warranty_status' => 'nullable|string|max:50',
        'items.*.from_serial' => ['nullable', 'regex:/^\d{1,6}$/'],
        'items.*.to_serial'   => ['nullable', 'regex:/^\d{1,6}$/'],
    ]);

    DB::beginTransaction();

    try {

        /* ================= UPDATE PURCHASE HEADER ================= */
        $purchase->update([
            'vendor_id'       => $request->vendor_id,
            'challan_no'      => $request->challan_no,
            'tracking_number' => $request->tracking_number,
            'challan_date'    => $request->challan_date,
            'received_date'   => $request->received_date,
            'courier_name'    => $request->courier_name,
            'updated_by'      => auth()->id(),
        ]);

        /* =========================================================
           📁 RECEIPT DOCUMENTS (CRITICAL FIX)
           ========================================================= */

        // Existing files from DB
        $existingFiles = is_array($purchase->document_recipient)
            ? $purchase->document_recipient
            : json_decode($purchase->document_recipient, true) ?? [];

        // Files removed from UI
        $removedFiles = json_decode($request->removed_recipient_files ?? '[]', true);

        // Remove deleted files
        $existingFiles = array_values(array_diff($existingFiles, $removedFiles));

        // Add newly uploaded files
        if ($request->hasFile('document_recipient')) {
            foreach ($request->file('document_recipient') as $file) {
                $path = $file->store('uploads/receipts', 'public');
                $existingFiles[] = $path;
            }
        }

        // Save merged files back to DB
        $purchase->document_recipient = $existingFiles;
        $purchase->save();

        /* ================= BUILD ITEM ROWS ================= */
        $items = $request->input('items', []);
        $serialsToInsert = [];

        foreach ($items as $index => $item) {

            $from = $item['from_serial'] ?? null;
            $to   = $item['to_serial'] ?? null;

            if ($from && $to) {
                // SERIAL ITEMS
                for ($i = intval($from); $i <= intval($to); $i++) {
                    $serialsToInsert[] = [
                        'purchase_id'    => $purchase->id,
                        'product_id'     => $item['product_id'] ?? null,
                        'sparepart_id'   => $item['sparepart_id'],
                        'quantity'       => 1,
                        'warranty_status'=> $item['warranty_status'] ?? null,
                        'serial_no'      => (string) $i,
                        'from_serial'    => $from,
                        'to_serial'      => $to,
                        'group_index'    => $index + 1,
                        'created_by'     => auth()->id(),
                    ];
                }
            } else {
                // NON-SERIAL ITEMS
                $serialsToInsert[] = [
                    'purchase_id'    => $purchase->id,
                    'product_id'     => $item['product_id'] ?? null,
                    'sparepart_id'   => $item['sparepart_id'],
                    'quantity'       => $item['quantity'],
                    'warranty_status'=> $item['warranty_status'] ?? null,
                    'serial_no'      => null,
                    'from_serial'    => null,
                    'to_serial'      => null,
                    'group_index'    => $index + 1,
                    'created_by'     => auth()->id(),
                ];
            }
        }

        /* ================= DUPLICATE SERIAL CHECK ================= */
        $duplicateSerials = [];

        foreach ($serialsToInsert as $s) {
            if (empty($s['serial_no'])) continue;

            $query = SparepartPurchaseItem::where('serial_no', $s['serial_no'])
                ->where('sparepart_id', $s['sparepart_id'])
                ->where('purchase_id', '!=', $purchase->id)
                ->whereNull('deleted_at');

            if (!empty($s['product_id'])) {
                $query->where('product_id', $s['product_id']);
            } else {
                $query->whereNull('product_id');
            }

            if ($query->exists()) {
                $duplicateSerials[] = $s['serial_no'];
            }
        }

        if (!empty($duplicateSerials)) {
            DB::rollBack();
            return response()->json([
                'message' =>
                    'The following serial numbers are already purchased: ' .
                    implode(', ', array_unique($duplicateSerials)),
                'duplicates' => array_unique($duplicateSerials),
            ], 422);
        }

        /* ================= DELETE OLD ITEMS ================= */
        SparepartPurchaseItem::where('purchase_id', $purchase->id)->delete();

        /* ================= INSERT NEW ITEMS ================= */
        foreach ($serialsToInsert as $record) {
            SparepartPurchaseItem::create($record);
        }

        /* ================= UPDATE TOTAL QUANTITY ================= */
        $purchase->update([
            'quantity' => count(
                array_filter($serialsToInsert, fn ($r) => !empty($r['serial_no']))
            )
        ]);

        DB::commit();

        return response()->json([
            'message' => 'Spare part purchase updated successfully',
            'purchase_id' => $purchase->id,
        ], 200);

    } catch (\Throwable $e) {
        DB::rollBack();

        return response()->json([
            'message' => 'Update failed',
            'error'   => $e->getMessage(),
            'line'    => $e->getLine(),
        ], 500);
    }
}



public function destroy($id)
{
    $purchase = SparepartPurchase::find($id);

    if (!$purchase) {
        return response()->json([
            'message' => 'Sparepart purchase not found'
        ], 404);
    }

    DB::beginTransaction();

    try {
        $items = SparepartPurchaseItem::where('purchase_id', $id)->get();

        foreach ($items as $item) {
            $item->deleted_by = auth()->id();
            $item->save();
            $item->delete();
        }

        $purchase->deleted_by = auth()->id();
        $purchase->save();
        $purchase->delete(); 

        DB::commit();

        return response()->json([
            'message' => 'Purchase and its items deleted successfully'
        ], 200);

    } catch (\Throwable $e) {
        DB::rollBack();

        Log::error("Failed deleting purchase ID {$id}: {$e->getMessage()}", [
            'trace' => $e->getTraceAsString()
        ]);

        return response()->json([
            'message' => 'Delete failed',
            'error' => $e->getMessage(),
            'line' => $e->getLine(),
        ], 500);
    }
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
        ->whereNull('deleted_at')
        ->first();

    if (!$item) {
        return response()->json([
            'message' => 'Item not found'
        ], 404);
    }

    /* =================================================
       🔒 INVENTORY CHECK (SERIAL-BASED — CORRECT)
       ================================================= */
    if ($item->from_serial && $item->to_serial) {

        $usedInInventory = Inventory::whereBetween(
                'serial_no',
                [$item->from_serial, $item->to_serial]
            )
            ->whereNull('deleted_at')
            ->exists();

        if ($usedInInventory) {
            return response()->json([
                'message' =>
                    'Cannot delete: One or more serial numbers are already used in inventory.'
            ], 403);
        }
    }

    /* =================================================
       🔒 SALES CHECK (THROUGH INVENTORY, NOT SALE_ITEMS)
       ================================================= */
    if ($item->from_serial && $item->to_serial) {

        $usedInSales = Inventory::whereBetween(
                'serial_no',
                [$item->from_serial, $item->to_serial]
            )
            ->whereNotNull('sale_item_id')   // ✅ this column EXISTS (from your earlier error)
            ->whereNull('deleted_at')
            ->exists();

        if ($usedInSales) {
            return response()->json([
                'message' =>
                    'Cannot delete: One or more serial numbers are already sold.'
            ], 403);
        }
    }

    /* =================================================
       🔒 SERVICE / REPAIR CHECK (SERIAL-BASED)
       ================================================= */
    if ($item->from_serial && $item->to_serial) {

        $usedInService = Inventory::whereBetween(
                'serial_no',
                [$item->from_serial, $item->to_serial]
            )
            ->whereNotNull('service_item_id') // only if this column exists
            ->whereNull('deleted_at')
            ->exists();

        if ($usedInService) {
            return response()->json([
                'message' =>
                    'Cannot delete: One or more serial numbers are used in Service/Repair.'
            ], 403);
        }
    }

    /* =================================================
       🗑 SOFT DELETE
       ================================================= */
    $item->deleted_by = auth()->id();
    $item->save();
    $item->delete();

    return response()->json([
        'message' => 'Item deleted successfully',
        'deleted_item_id' => $itemId
    ], 200);
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

    $maxBoards = min($boardsPossibleArr);

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
public function overall()
{
    $purchased = DB::table('sparepart_purchase_items as pi')
        ->leftJoin('spareparts as sp', 'pi.sparepart_id', '=', 'sp.id')
        ->whereNull('pi.deleted_at')
        ->whereNull('pi.deleted_by')
        ->select(
            'pi.sparepart_id',
            'sp.name as sparepart_name',
            DB::raw('SUM(pi.quantity) as purchased_quantity')
        )
        ->groupBy('pi.sparepart_id', 'sp.name')
        ->orderBy('sp.name')
        ->get()
        ->keyBy('sparepart_id');

    if ($purchased->isEmpty()) {
        return response()->json([]);
    }


    $purchasedSerialsRaw = DB::table('sparepart_purchase_items')
        ->whereNull('deleted_at')
        ->whereNull('deleted_by')
        ->whereNotNull('serial_no')
        ->select('sparepart_id', 'serial_no')
        ->get();

    $purchasedSerials = [];
    foreach ($purchasedSerialsRaw as $r) {
        $purchasedSerials[$r->sparepart_id][] = trim($r->serial_no);
    }

    foreach ($purchasedSerials as $id => $list) {
        $purchasedSerials[$id] = array_values(array_unique($list));
    }

    $assembledSerials = DB::table('inventory')
        ->whereNotNull('serial_no')
        ->whereNull('deleted_at')
        ->pluck('serial_no')
        ->map(fn($x) => trim($x))
        ->toArray();

    $assembledSerials = array_unique($assembledSerials);

    $assembledCounts = DB::table('inventory')
        ->whereNull('deleted_by')
        ->whereNull('deleted_at')
        ->select('product_id', DB::raw('COUNT(*) as assembled_qty'))
        ->groupBy('product_id')
        ->pluck('assembled_qty', 'product_id');

    $productRequirements = DB::table('product')
        ->whereNull('deleted_at')
        ->select('id', 'sparepart_requirements')
        ->get()
        ->map(function ($row) {
            $row->sparepart_requirements = json_decode($row->sparepart_requirements, true) ?? [];
            return $row;
        })
        ->keyBy('id');

    $pcbInService = DB::table('service_vci_items')
        ->whereNotNull('vci_serial_no')
        ->whereIn('status', ['Inward', 'Testing'])
        ->whereNull('deleted_at')
        ->select('sparepart_id', 'vci_serial_no', 'status')
        ->get()
        ->groupBy('sparepart_id');

    $pcbDelivered = DB::table('service_vci_items')
        ->whereNotNull('vci_serial_no')
        ->where('status', 'Delivered')
        ->whereNull('deleted_at')
        ->pluck('vci_serial_no')
        ->map(fn($x) => trim($x))
        ->toArray();

    $nonPcbReturns = DB::table('service_vci_items')
        ->whereNotNull('quantity')
        ->where('status', 'Return')
        ->whereNull('deleted_at')
        ->select('sparepart_id', DB::raw('SUM(quantity) as return_qty'))
        ->groupBy('sparepart_id')
        ->pluck('return_qty', 'sparepart_id');

    $final = $purchased->map(function ($row) use (
        $purchasedSerials,
        $assembledSerials,
        $productRequirements,
        $assembledCounts,
        $pcbInService,
        $pcbDelivered,
        $nonPcbReturns
    ) {
        $id   = $row->sparepart_id;
        $name = strtolower($row->sparepart_name);

        if (str_contains($name, 'pcb')) {

            $serviceItems = collect($pcbInService[$id] ?? [])
                ->map(fn($x) => [
                    'serial' => trim($x->vci_serial_no),
                    'status' => $x->status
                ])
                ->toArray();

            $serviceSerialNumbers = array_map(fn($x) => $x['serial'], $serviceItems);

            $allPurchased = $purchasedSerials[$id] ?? [];
            $availableList = array_diff(
                $allPurchased,
                $assembledSerials,
                $pcbDelivered,
                $serviceSerialNumbers   
            );

            $availableDetailed = array_map(function ($serial) use ($serviceSerialNumbers) {
                return [
                    'serial'      => $serial,
                    'in_service'  => in_array($serial, $serviceSerialNumbers)
                ];
            }, array_values($availableList));

            return [
                'sparepart_id'       => $id,
                'sparepart_name'     => $row->sparepart_name,
                'type'               => 'pcb',

                'purchased_quantity' => count($allPurchased),

                'service_quantity'   => count($serviceSerialNumbers),

                'available_quantity' => count($availableList),

                'service_items'      => $serviceItems,
                'available_serials'  => $availableDetailed,
                'purchased_serials'  => $allPurchased,
            ];
        }


        $purchasedQty = (int) $row->purchased_quantity;

        $totalUsed = 0;
        foreach ($productRequirements as $productId => $pr) {
            $assembledQty = $assembledCounts[$productId] ?? 0;

            $requiredPerProduct = collect($pr->sparepart_requirements)
                ->firstWhere('id', $id)['required_quantity'] ?? 0;

            $totalUsed += $assembledQty * $requiredPerProduct;
        }

        $serviceQty = $nonPcbReturns[$id] ?? 0;

        $availableQty = max($purchasedQty - $totalUsed - $serviceQty, 0);

        return [
            'sparepart_id'       => $id,
            'sparepart_name'     => $row->sparepart_name,
            'type'               => 'non-pcb',

            'purchased_quantity' => $purchasedQty,
            'used_quantity'      => $totalUsed,

            'service_quantity'   => $serviceQty,     
            'available_quantity' => $availableQty,    

        ];
    })->values();

    return response()->json($final);
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
//
public function getSeriesSpareparts($series)
{
    $series = urldecode($series);

    if (preg_match('/^(.*)\((.*)\)$/', $series, $matches)) {
        $series = trim($matches[1]);
    }

    $product = Product::where('name', $series)
        ->whereNull('deleted_at')             
        ->with('productTypes')
        ->first();

    if (!$product) {
        return response()->json(['message' => "Product '{$series}' not found"], 404);
    }

    $sparepartRequirements = $product->sparepart_requirements ?? [];

    if (empty($sparepartRequirements)) {
        return response()->json([
            'product' => $product->name,
            'spare_parts' => [],
            'message' => 'No sparepart requirements found for this product.',
        ]);
    }

    $sparepartIds = collect($sparepartRequirements)->pluck('id')->toArray();

    $spareparts = Sparepart::whereIn('id', $sparepartIds)
        ->whereNull('deleted_at')              
        ->get();

    $assembledVCIs = DB::table('inventory')
        ->where('product_id', $product->id)
        ->whereNull('deleted_by')               
        ->whereNull('deleted_at')
        ->count();

    $sparepartsMapped = $spareparts->map(function ($part) use ($sparepartRequirements, $assembledVCIs) {

        $requiredPerProduct = collect($sparepartRequirements)
            ->firstWhere('id', $part->id)['required_quantity'] ?? 0;

        $purchasedQty = DB::table('sparepart_purchase_items')
            ->where('sparepart_id', $part->id)
            ->whereNull('deleted_at')           
            ->whereNull('deleted_by')         
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
public function lastFourPurchases()
{
    $purchases = SparepartPurchase::with([
            'vendor:id,vendor',
            'items.sparepart:id,name'
        ])
        ->whereNull('deleted_at')          
        ->orderBy('id', 'desc')            
        ->take(4)                          
        ->get();

    $response = [];

    foreach ($purchases as $purchase) {
        foreach ($purchase->items as $item) {
            $response[] = [
                'purchase_id'   => $purchase->id,
                'vendor'        => $purchase->vendor->vendor ?? 'Unknown Vendor',
                'challan_no'    => $purchase->challan_no,
                'challan_date'  => $purchase->challan_date,
                'sparepart'     => $item->sparepart->name ?? null,
                'quantity'      => $item->quantity,
            ];
        }
    }

    return response()->json($response);
}

}