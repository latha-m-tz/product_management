<?php

namespace App\Http\Controllers;

use App\Models\VCIService;
use App\Models\VCIServiceItems;
use App\Models\Product;
use App\Models\Vendor;
use App\Models\spareparts;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
class ServiceVCIManagementController extends Controller

{
   
public function index()
{
    $services = VCIService::with(['vendor', 'items.sparepart', 'items.product'])
        ->whereNull('deleted_at')
        ->orderBy('id', 'desc')
        ->get();

    $data = $services->map(function ($service) {
        return [
            'id'            => $service->id,
            'vendor_id'     => $service->vendor->id ?? null,
            'vendor_name'   => $service->vendor->vendor ?? null,
            'challan_no'    => $service->challan_no,
            'challan_date'  => $service->challan_date,
            'tracking_no'   => $service->tracking_no,
            'status'        => $service->status,
            'created_at'    => optional($service->created_at)->format('Y-m-d H:i:s'),
            'updated_at'    => optional($service->updated_at)->format('Y-m-d H:i:s'),

            'items' => $service->items->map(function ($item) {

                $status = strtolower($item->status);

                switch ($status) {
                    case 'inward':
                        $status = 'Inward';
                        break;

                    case 'testing':
                        $status = 'Testing';
                        break;

                    case 'delivered':
                    case 'delivery':
                        $status = 'Delivered';
                        break;

                    case 'return':
                        $status = 'Return';
                        break;

                    default:
                        $status = ucfirst($status);
                }

                return [
                    'id'            => $item->id,
                    'vci_serial_no' => $item->vci_serial_no,
                    'status'        => $status,
                    'created_at'    => optional($item->created_at)->format('Y-m-d H:i:s'),
                    'upload_image'  => $item->upload_image 
                                        ? asset('storage/' . $item->upload_image)
                                        : null,
                    'sparepart'     => $item->sparepart->name ?? null,
                     'quantity'      => $item->quantity,
                    'product'       => $item->product->name ?? null,
                ];
            }),
        ];
    });

    return response()->json($data, 200);
}

public function store(Request $request)
{
    $validator = Validator::make($request->all(), [

        // =========================
        // SERVICE FIELDS
        // =========================
        'vendor_id'    => 'required|integer|exists:vendors,id',
        'challan_no'   => 'required|string|max:50|unique:service_vci,challan_no',
        'challan_date' => 'required|date',
        'tracking_no'  => 'nullable|string|max:50',

        'receipt_files.*' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:2048',

        // =========================
        // ITEMS ARRAY
        // =========================
        'items' => 'required|array|min:1',

        'items.*.status'   => 'required|string',
        'items.*.remarks'  => 'nullable|string',

        'items.*.product_id'   => 'nullable|integer|exists:product,id',
        'items.*.sparepart_id' => 'nullable|integer|exists:spareparts,id',

        'items.*.serial_from'  => 'nullable|integer',
        'items.*.serial_to'    => 'nullable|integer',

        'items.*.quantity'     => 'nullable|integer|min:1',

        // =========================
        // CUSTOM ROW VALIDATION
        // =========================
        'items.*' => function ($attribute, $item, $fail) {

            $productId   = $item['product_id'] ?? null;
            $sparepartId = $item['sparepart_id'] ?? null;

            // Either product or sparepart required
            if (!$productId && !$sparepartId) {
                return $fail('Each row must contain either Product or Sparepart.');
            }

            // Both not allowed
            if ($productId && $sparepartId) {
                return $fail('Row cannot contain both Product and Sparepart.');
            }

            // PRODUCT VALIDATION
            if ($productId) {
                if (empty($item['serial_from']) || empty($item['serial_to'])) {
                    return $fail('Serial From and Serial To are required for product.');
                }

                if ($item['serial_to'] < $item['serial_from']) {
                    return $fail('Serial To must be greater than or equal to Serial From.');
                }
            }

            // SPAREPART VALIDATION
            if ($sparepartId) {
                if (empty($item['quantity']) || $item['quantity'] <= 0) {
                    return $fail('Quantity is required for sparepart.');
                }

                // STOCK CHECK
                $purchased = DB::table('sparepart_purchase_items')
                    ->where('sparepart_id', $sparepartId)
                    ->whereNull('deleted_at')
                    ->sum('quantity');

                $used = DB::table('service_vci_items')
                    ->where('sparepart_id', $sparepartId)
                    ->whereNull('deleted_at')
                    ->sum('quantity');

                if ($item['quantity'] > ($purchased - $used)) {
                    return $fail('Only ' . max(0, $purchased - $used) . ' qty available.');
                }
            }
        }
    ]);

    if ($validator->fails()) {
        return response()->json(['errors' => $validator->errors()], 422);
    }

    DB::beginTransaction();

    try {

        // =========================
        // CREATE SERVICE
        // =========================
        $service = VCIService::create([
            'vendor_id'    => $request->vendor_id,
            'challan_no'   => $request->challan_no,
            'challan_date' => $request->challan_date,
            'tracking_no'  => $request->tracking_no,
            'created_by'   => Auth::id(),
            'updated_by'   => Auth::id(),
        ]);

        // =========================
        // STORE ITEMS
        // =========================
        foreach ($request->items as $index => $item) {

            $imagePath = null;
            if ($request->hasFile("items.$index.upload_image")) {
                $imagePath = $request->file("items.$index.upload_image")
                    ->store('uploads/service_vci/items', 'public');
            }

            // =========================
            // PRODUCT → SERIAL ROWS
            // =========================
            if (!empty($item['product_id'])) {

                for ($s = $item['serial_from']; $s <= $item['serial_to']; $s++) {
                    VCIServiceItems::create([
                        'service_vci_id' => $service->id,
                        'product_id'     => $item['product_id'],
                        'sparepart_id'   => null,
                        'quantity'       => null,
                        'vci_serial_no'  => $s,
                        'status'         => $item['status'],
                        'remarks'        => $item['remarks'] ?? null,
                        'upload_image'   => $imagePath,
                        'created_by'     => Auth::id(),
                        'updated_by'     => Auth::id(),
                    ]);
                }
            }

            // =========================
            // SPAREPART → QUANTITY ROW
            // =========================
            if (!empty($item['sparepart_id'])) {

                VCIServiceItems::create([
                    'service_vci_id' => $service->id,
                    'sparepart_id'   => $item['sparepart_id'], // ✅ FIXED
                    'product_id'     => null,
                    'vci_serial_no'  => null,
                    'quantity'       => $item['quantity'],
                    'status'         => $item['status'],
                    'remarks'        => $item['remarks'] ?? null,
                    'upload_image'   => $imagePath,
                    'created_by'     => Auth::id(),
                    'updated_by'     => Auth::id(),
                ]);
            }
        }

        DB::commit();

        return response()->json([
            'message' => 'VCI Service created successfully',
            'data'    => $service->load('items')
        ], 201);

    } catch (\Exception $e) {
        DB::rollBack();

        return response()->json([
            'message' => 'Something went wrong',
            'error'   => $e->getMessage()
        ], 500);
    }
}

public function update(Request $request, $id)
{
    $service = VCIService::whereNull('deleted_at')->find($id);
    if (!$service) {
        return response()->json(['message' => 'Service VCI not found'], 404);
    }

    $validator = Validator::make($request->all(), [

        'vendor_id'    => 'required|integer|exists:vendors,id',
        'challan_no'   => 'required|string|max:50|unique:service_vci,challan_no,' . $id,
        'challan_date' => 'required|date',
        'tracking_no'  => 'nullable|string|max:50',

        'items' => 'required|array|min:1',

        'items.*.id'           => 'nullable|integer|exists:service_vci_items,id',
        'items.*.status'       => 'required|string',
        'items.*.remarks'      => 'nullable|string',

        'items.*.product_id'   => 'nullable|integer|exists:product,id',
        'items.*.sparepart_id' => 'nullable|integer|exists:spareparts,id',

        'items.*.serial_from'  => 'nullable|integer',
        'items.*.serial_to'    => 'nullable|integer',

        'items.*.quantity'     => 'nullable|integer|min:1',

        'items.*' => function ($attribute, $item, $fail) use ($id) {

            $productId   = $item['product_id'] ?? null;
            $sparepartId = $item['sparepart_id'] ?? null;

            // Either product or sparepart
            if (!$productId && !$sparepartId) {
                return $fail('Each row must contain either Product or Sparepart.');
            }

            if ($productId && $sparepartId) {
                return $fail('Row cannot contain both Product and Sparepart.');
            }

            if ($productId) {
                if (empty($item['serial_from']) || empty($item['serial_to'])) {
                    return $fail('Serial From and Serial To are required for product.');
                }

                if ($item['serial_to'] < $item['serial_from']) {
                    return $fail('Serial To must be greater than or equal to Serial From.');
                }
            }

            if ($sparepartId) {
                if (empty($item['quantity']) || $item['quantity'] <= 0) {
                    return $fail('Quantity is required for sparepart.');
                }

                $purchased = DB::table('sparepart_purchase_items')
                    ->where('sparepart_id', $sparepartId)
                    ->whereNull('deleted_at')
                    ->sum('quantity');

                $usedOther = DB::table('service_vci_items')
                    ->where('sparepart_id', $sparepartId)
                    ->where('service_vci_id', '!=', $id)
                    ->whereNull('deleted_at')
                    ->sum('quantity');

                $available = max(0, $purchased - $usedOther);

                if ($item['quantity'] > $available) {
                    return $fail("Only {$available} qty available.");
                }
            }
        }
    ]);

    if ($validator->fails()) {
        return response()->json(['errors' => $validator->errors()], 422);
    }

    DB::beginTransaction();

    try {

        // =========================
        // UPDATE SERVICE HEADER
        // =========================
        $service->update([
            'vendor_id'    => $request->vendor_id,
            'challan_no'   => $request->challan_no,
            'challan_date' => $request->challan_date,
            'tracking_no'  => $request->tracking_no,
            'updated_by'   => Auth::id(),
        ]);

        // =========================
        // DELETE OLD ITEMS (FULL RESET)
        // =========================
        $oldItems = $service->items()->whereNull('deleted_at')->get();

        foreach ($oldItems as $old) {
            if ($old->upload_image && file_exists(public_path($old->upload_image))) {
                unlink(public_path($old->upload_image));
            }
        }

        VCIServiceItems::where('service_vci_id', $service->id)
            ->whereNull('deleted_at')
            ->update([
                'deleted_at' => now(),
                'deleted_by' => Auth::id(),
            ]);

        // =========================
        // RE-CREATE ITEMS (SAME AS STORE)
        // =========================
        foreach ($request->items as $index => $item) {

            $imagePath = null;
            if ($request->hasFile("items.$index.upload_image")) {
                $imagePath = $request->file("items.$index.upload_image")
                    ->store('uploads/service_vci/items', 'public');
            }

            // PRODUCT → SERIAL ROWS
            if (!empty($item['product_id'])) {

                for ($s = $item['serial_from']; $s <= $item['serial_to']; $s++) {
                    VCIServiceItems::create([
                        'service_vci_id' => $service->id,
                        'product_id'     => $item['product_id'],
                        'sparepart_id'   => null,
                        'quantity'       => null,
                        'vci_serial_no'  => $s,
                        'status'         => $item['status'],
                        'remarks'        => $item['remarks'] ?? null,
                        'upload_image'   => $imagePath,
                        'created_by'     => Auth::id(),
                        'updated_by'     => Auth::id(),
                    ]);
                }
            }

            // SPAREPART → QUANTITY ROW
            if (!empty($item['sparepart_id'])) {

                VCIServiceItems::create([
                    'service_vci_id' => $service->id,
                    'sparepart_id'   => $item['sparepart_id'],
                    'product_id'     => null,
                    'vci_serial_no'  => null,
                    'quantity'       => $item['quantity'],
                    'status'         => $item['status'],
                    'remarks'        => $item['remarks'] ?? null,
                    'upload_image'   => $imagePath,
                    'created_by'     => Auth::id(),
                    'updated_by'     => Auth::id(),
                ]);
            }
        }

        DB::commit();

        return response()->json([
            'message' => 'VCI Service updated successfully',
            'data'    => $service->load('items')
        ], 200);

    } catch (\Exception $e) {
        DB::rollBack();

        return response()->json([
            'message' => 'Something went wrong',
            'error'   => $e->getMessage()
        ], 500);
    }
}


public function show($id)
{
    $service = VCIService::with(['vendor', 'items.sparepart'])
        ->whereNull('deleted_at')
        ->find($id);

    if (!$service) {
        return response()->json(['message' => 'Service VCI not found'], 404);
    }

    $groupedItems = [];
    $productGroups = [];

    foreach ($service->items as $item) {

        /* =========================
           PRODUCT → GROUP SERIALS
        ========================= */
        if ($item->product_id) {

            $key = $item->product_id . '|' . $item->status;

            if (!isset($productGroups[$key])) {
                $productGroups[$key] = [
                    'id'           => $item->id,
                    'type'         => 'product',
                    'product_id'   => $item->product_id,
                    'serial_from'  => $item->vci_serial_no,
                    'serial_to'    => $item->vci_serial_no,
                    'status'       => $item->status,
                    'remarks'      => $item->remarks,
                    'upload_image' => $item->upload_image
                        ? asset('storage/' . ltrim($item->upload_image, '/'))
                        : null,
                ];
            } else {
                $productGroups[$key]['serial_from'] = min(
                    $productGroups[$key]['serial_from'],
                    $item->vci_serial_no
                );
                $productGroups[$key]['serial_to'] = max(
                    $productGroups[$key]['serial_to'],
                    $item->vci_serial_no
                );
            }

            continue;
        }

        $groupedItems[] = [
            'id'            => $item->id,
            'type'          => 'sparepart',
            'sparepart_id'  => $item->sparepart_id,
            'quantity'      => $item->quantity,
            'status'        => $item->status,
            'remarks'       => $item->remarks,
            'upload_image'  => $item->upload_image
                ? asset('storage/' . ltrim($item->upload_image, '/'))
                : null,
        ];
    }

    // Merge product groups
    $groupedItems = array_merge($groupedItems, array_values($productGroups));

    return response()->json([
        'id'           => $service->id,
        'vendor_id'    => $service->vendor_id,
        'vendor_name'  => optional($service->vendor)->vendor,
        'challan_no'   => $service->challan_no,
        'challan_date' => $service->challan_date,
        'tracking_no'  => $service->tracking_no,
        'items'        => $groupedItems,
        'created_at'   => $service->created_at,
        'updated_at'   => $service->updated_at,
    ], 200);
}

public function destroyItem($id)
{
    $item = VCIServiceItems::whereNull('deleted_at')->find($id);

    if (!$item) {
        return response()->json([
            'message' => 'Service item not found'
        ], 404);
    }

    $item->update([
        'deleted_at' => now(),
        'deleted_by' => Auth::id(),
    ]);

    return response()->json([
        'message' => 'Service item deleted successfully'
    ], 200);
}



   
    public function getAllVCISerialNumbers()
    {
        $serialNumbers = VCIServiceItems::whereNull('deleted_at')
            ->pluck('vci_serial_no')
            ->map(fn($s) => trim($s))
            ->unique()
            ->values();

        return response()->json($serialNumbers, 200);
    }

   
    public function getAllServiceItems(Request $request)
    {
        $query = VCIServiceItems::query()
            ->with(['serviceVCI', 'product', 'serviceVCI.vendor'])
            ->whereNull('service_vci_items.deleted_at');

        // Filter by from_place (on service)
        if ($request->filled('from_place')) {
            $query->whereHas('serviceVCI', function ($q) use ($request) {
                $q->where('from_place', $request->from_place)
                  ->whereNull('deleted_at');
            });
        }

        if ($request->filled('to_place')) {
            $query->whereHas('serviceVCI', function ($q) use ($request) {
                $q->where('to_place', $request->to_place)
                  ->whereNull('deleted_at');
            });
        }

        if ($request->filled('tracking_number')) {
            $query->whereHas('serviceVCI', function ($q) use ($request) {
                $q->where('tracking_number', 'like', '%' . $request->tracking_number . '%')
                  ->whereNull('deleted_at');
            });
        }

        if ($request->filled('testing_status')) {
            $query->where('testing_status', $request->testing_status);
        }

        $items = $query->get();

        $response = $items->map(function ($item) {
            return [
                'id' => $item->id,
                'vci_serial_no' => $item->vci_serial_no,
                'product_name' => $item->product?->name,
                'vendor_name' => $item->serviceVCI?->vendor?->vendor,
                'testing_status' => $item->testing_status ?? null,
                'tracking_number' => $item->serviceVCI?->tracking_number,
                'from_place' => $item->serviceVCI?->from_place,
                'to_place' => $item->serviceVCI?->to_place,
                'upload_image' => $item->upload_image ? asset('storage/' . $item->upload_image) : null,
            ];
        });

        return response()->json($response, 200);
    }

   
    private function normalizeImagePath($path)
    {
        if (!$path) return null;

        $replacements = [
            url('storage') . '/',
            'http://localhost/storage/',
            'https://localhost/storage/',
            'http://127.0.0.1/storage/',
            'https://127.0.0.1/storage/',
            'http://localhost:8000/storage/',
            'https://localhost:8000/storage/',
            'http://127.0.0.1:8000/storage/',
            'https://127.0.0.1:8000/storage/',
        ];

        $normalized = str_replace($replacements, '', $path);
        return ltrim($normalized, '/');
    }

public function checkQty($id)
{
    $product = Sparepart::find($id);
    if (!$product) {
        return response()->json([
            'error' => 'Product not found'
        ], 404);
    }

    $purchasedQty = DB::table('sparepart_purchase_items')
        ->where('sparepart_id', $id)
        ->whereNull('deleted_at')
        ->sum('qty');   // <= correct purchase qty

    $usedService = DB::table('service_vci_items')
        ->where('product_id', $id)
        ->whereNull('deleted_at')
        ->count();

    $usedAssembly = DB::table('inventory')
        ->where('sparepart_id', $id)
        ->whereNull('deleted_at')
        ->count();

    // 5️⃣ Available qty
    $availableQty = max($purchasedQty - ($usedService + $usedAssembly), 0);

    return response()->json([
        'product_id'     => $id,
        'purchased_qty'  => $purchasedQty,
        'used_service'   => $usedService,
        'used_assembly'  => $usedAssembly,
        'available_qty'  => $availableQty
    ], 200);
}
public function checkStatus(Request $request)
{
    $request->validate([
        'vendor_id'  => 'required|integer|exists:vendors,id',
        'serial_no'  => 'required|string|max:50',
        'product_id' => 'required|integer|exists:product,id',
    ]);

    $lastEntry = DB::table('service_vci_items')
        ->where('vendor_id', $request->vendor_id)
        ->where('product_id', $request->product_id)
        ->where('vci_serial_no', $request->serial_no)
        ->whereNull('deleted_at')
        ->orderByDesc('id')
        ->first();

    $current = $lastEntry?->status;

    $allowed = [];

    switch ($current) {
        case null:
            $allowed = ['Inward'];
            break;

        case 'Inward':
            $allowed = ['Testing'];
            break;

        case 'Testing':
            $allowed = ['Delivered'];
            break;

        case 'Delivered':
            $allowed = ['Return'];
            break;

        case 'Return':
            $allowed = [];
            break;
    }

    return response()->json([
        'current_status' => $current,
        'allowed_status' => $allowed,
    ], 200);
}
}