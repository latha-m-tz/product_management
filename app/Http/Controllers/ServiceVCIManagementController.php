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

        'vendor_id'    => 'required|integer|exists:vendors,id',
        'challan_no'   => 'required|string|max:50|unique:service_vci,challan_no',
        'challan_date' => 'required|date',
        'tracking_no'  => 'nullable|string|max:50',

        'receipt_files.*' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:102400',

        'items' => 'required|array|min:1',

        'items.*.type'    => 'required|in:product,sparepart',
        'items.*.status'  => 'required|string',
        'items.*.remarks' => 'nullable|string',

        'items.*.product_id'   => 'nullable|integer|exists:product,id',
        'items.*.sparepart_id' => 'nullable|integer|exists:spareparts,id',

        // product
        'items.*.serial_from' => 'nullable|integer',
        'items.*.serial_to'   => 'nullable|integer',

        // sparepart
        'items.*.quantity'      => 'nullable|integer|min:1',
        'items.*.vci_serial_no' => 'nullable|string',

        'items.*' => function ($attribute, $item, $fail) {

            $type        = $item['type'] ?? null;
            $productId   = $item['product_id'] ?? null;
            $sparepartId = $item['sparepart_id'] ?? null;

            /* ================= BASIC ================= */
            if ($type === 'product' && !$productId) {
                return $fail('Product is required.');
            }

            if ($type === 'sparepart' && !$sparepartId) {
                return $fail('Sparepart is required.');
            }

            if ($productId && $sparepartId) {
                return $fail('Row cannot contain both Product and Sparepart.');
            }

            /* ================= PRODUCT ================= */
            if ($type === 'product') {

                if (empty($item['serial_from']) || empty($item['serial_to'])) {
                    return $fail('Serial From and Serial To are required for product.');
                }

                if ($item['serial_to'] < $item['serial_from']) {
                    return $fail('Serial To must be greater than or equal to Serial From.');
                }

                return;
            }

            /* ================= SPAREPART ================= */
            if ($type === 'sparepart') {

                $sparepart = DB::table('spareparts')
                    ->where('id', $sparepartId)
                    ->first();

                if (!$sparepart) {
                    return $fail('Invalid sparepart.');
                }

                // 🔥 PCB / BARCODE detection by NAME
                $isPCB = (
                    stripos($sparepart->name, 'PCB') !== false ||
                    stripos($sparepart->name, 'BARCODE') !== false
                );

                /* ===== PCB / BARCODE ===== */
                if ($isPCB) {

                    if (!empty($item['quantity'])) {
                        return $fail('Quantity not allowed for PCB / Barcode sparepart.');
                    }

                    if (empty($item['vci_serial_no'])) {
                        return $fail('Serial number is required for PCB / Barcode sparepart.');
                    }

                    if (!ctype_digit((string) $item['vci_serial_no'])) {
                        return $fail('Invalid PCB / Barcode serial number.');
                    }

                    return;
                }

                /* ===== NORMAL SPAREPART ===== */
                if (!isset($item['quantity']) || $item['quantity'] <= 0) {
                    return $fail('Quantity is required for sparepart.');
                }

                $purchased = DB::table('sparepart_purchase_items')
                    ->where('sparepart_id', $sparepartId)
                    ->whereNull('deleted_at')
                    ->sum('quantity');

                $used = DB::table('service_vci_items')
                    ->where('sparepart_id', $sparepartId)
                    ->whereNull('deleted_at')
                    ->sum('quantity');

                if ($item['quantity'] > ($purchased - $used)) {
                    return $fail(
                        'Only ' . max(0, $purchased - $used) . ' qty available.'
                    );
                }
            }
        }
    ]);

    if ($validator->fails()) {
        return response()->json(['errors' => $validator->errors()], 422);
    }

    DB::beginTransaction();

    try {

        /* ================= RECEIPTS ================= */
        $receiptFiles = [];

        if ($request->hasFile('receipt_files')) {
            foreach ($request->file('receipt_files') as $file) {
                $receiptFiles[] = $file->store(
                    'uploads/service_vci/receipts',
                    'public'
                );
            }
        }

        /* ================= CREATE SERVICE ================= */
        $service = VCIService::create([
            'vendor_id'     => $request->vendor_id,
            'challan_no'    => $request->challan_no,
            'challan_date'  => $request->challan_date,
            'tracking_no'   => $request->tracking_no,
            'receipt_files' => $receiptFiles,
            'created_by'    => Auth::id(),
            'updated_by'    => Auth::id(),
        ]);

        /* ================= ITEMS SAVE ================= */
        foreach ($request->items as $index => $item) {

            $imagePath = null;
            if ($request->hasFile("items.$index.upload_image")) {
                $imagePath = $request->file("items.$index.upload_image")
                    ->store('uploads/service_vci/items', 'public');
            }

            /* ================= PRODUCT ================= */
            if ($item['type'] === 'product') {

                for ($s = $item['serial_from']; $s <= $item['serial_to']; $s++) {
                    VCIServiceItems::create([
                        'service_vci_id' => $service->id,
                        'product_id'     => $item['product_id'],
                        'sparepart_id'   => null,
                        'vci_serial_no'  => $s,
                        'quantity'       => null,
                        'status'         => $item['status'],
                        'remarks'        => $item['remarks'] ?? null,
                        'upload_image'   => $imagePath,
                        'created_by'     => Auth::id(),
                        'updated_by'     => Auth::id(),
                    ]);
                }

                continue;
            }

            /* ================= SPAREPART ================= */
            $sparepart = DB::table('spareparts')
                ->where('id', $item['sparepart_id'])
                ->first();

            $isPCB = (
                stripos($sparepart->name, 'PCB') !== false ||
                stripos($sparepart->name, 'BARCODE') !== false
            );

            VCIServiceItems::create([
                'service_vci_id' => $service->id,
                'sparepart_id'   => $item['sparepart_id'],
                'product_id'     => null,
                'vci_serial_no'  => $isPCB ? $item['vci_serial_no'] : null,
                'quantity'       => $isPCB ? null : $item['quantity'],
                'status'         => $item['status'],
                'remarks'        => $item['remarks'] ?? null,
                'upload_image'   => $imagePath,
                'created_by'     => Auth::id(),
                'updated_by'     => Auth::id(),
            ]);
        }

        DB::commit();

        return response()->json([
            'message' => 'Service created successfully',
            'data'    => $service->load('items'),
        ], 201);

    } catch (\Exception $e) {

        DB::rollBack();

        return response()->json([
            'message' => 'Something went wrong',
            'error'   => $e->getMessage(),
        ], 500);
    }
}




public function update(Request $request, $id)
{
    $service = VCIService::whereNull('deleted_at')->findOrFail($id);

    $validator = Validator::make($request->all(), [

        /* ================= SERVICE ================= */
        'vendor_id'    => 'required|integer|exists:vendors,id',
        'challan_no'   => 'required|string|max:50|unique:service_vci,challan_no,' . $service->id,
        'challan_date' => 'required|date',
        'tracking_no'  => 'nullable|string|max:50',

        /* ================= RECEIPTS ================= */
        'receipt_files.*' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:102400',

        /* ================= ITEMS ================= */
        'items' => 'required|array|min:1',

        'items.*.type'    => 'required|in:product,sparepart',
        'items.*.status'  => 'required|string',
        'items.*.remarks' => 'nullable|string',

        'items.*.product_id'   => 'nullable|integer|exists:product,id',
        'items.*.sparepart_id' => 'nullable|integer|exists:spareparts,id',

        'items.*.serial_from' => 'nullable|integer',
        'items.*.serial_to'   => 'nullable|integer',

        'items.*.quantity'      => 'nullable|integer|min:1',
        'items.*.vci_serial_no' => 'nullable|string',

        /* ================= ITEM VALIDATION ================= */
        'items.*' => function ($attribute, $item, $fail) use ($service) {

            $type        = $item['type'] ?? null;
            $productId   = $item['product_id'] ?? null;
            $sparepartId = $item['sparepart_id'] ?? null;

            if ($type === 'product' && !$productId) {
                return $fail('Product is required.');
            }

            if ($type === 'sparepart' && !$sparepartId) {
                return $fail('Sparepart is required.');
            }

            if ($productId && $sparepartId) {
                return $fail('Row cannot contain both Product and Sparepart.');
            }

            if ($type === 'product') {

                if (empty($item['serial_from']) || empty($item['serial_to'])) {
                    return $fail('Serial From and Serial To are required.');
                }

                if ($item['serial_to'] < $item['serial_from']) {
                    return $fail('Serial To must be greater than or equal to Serial From.');
                }

                return;
            }

            $sparepart = DB::table('spareparts')->where('id', $sparepartId)->first();

            if (!$sparepart) {
                return $fail('Invalid sparepart.');
            }

            $isPCB = (
                stripos($sparepart->name, 'PCB') !== false ||
                stripos($sparepart->name, 'BARCODE') !== false
            );

            if ($isPCB) {

                if (!empty($item['quantity'])) {
                    return $fail('Quantity not allowed for PCB / Barcode sparepart.');
                }

                if (empty($item['vci_serial_no']) || !ctype_digit((string)$item['vci_serial_no'])) {
                    return $fail('Valid serial number is required for PCB / Barcode.');
                }

                return;
            }

            if (!isset($item['quantity']) || $item['quantity'] <= 0) {
                return $fail('Quantity is required for sparepart.');
            }

            $purchased = DB::table('sparepart_purchase_items')
                ->where('sparepart_id', $sparepartId)
                ->whereNull('deleted_at')
                ->sum('quantity');

            $used = DB::table('service_vci_items')
                ->where('sparepart_id', $sparepartId)
                ->whereNull('deleted_at')
                ->where('service_vci_id', '!=', $service->id)
                ->sum('quantity');

            if ($item['quantity'] > ($purchased - $used)) {
                return $fail('Only ' . max(0, $purchased - $used) . ' qty available.');
            }
        }
    ]);

    if ($validator->fails()) {
        return response()->json(['errors' => $validator->errors()], 422);
    }

    DB::beginTransaction();

    try {

        /* ================= RECEIPTS ================= */
        $receiptFiles = $service->receipt_files ?? [];

        // 🔥 DELETE RECEIPTS (IMPORTANT FIX)
        if ($request->has('deleted_receipt_files')) {
            foreach ($request->deleted_receipt_files as $file) {
                Storage::disk('public')->delete($file);
            }

            $receiptFiles = array_values(array_diff(
                $receiptFiles,
                $request->deleted_receipt_files
            ));
        }

        // ➕ ADD NEW RECEIPTS
        if ($request->hasFile('receipt_files')) {
            foreach ($request->file('receipt_files') as $file) {
                $receiptFiles[] = $file->store('uploads/service_vci/receipts', 'public');
            }
        }

        $service->update([
            'vendor_id'     => $request->vendor_id,
            'challan_no'    => $request->challan_no,
            'challan_date'  => $request->challan_date,
            'tracking_no'   => $request->tracking_no,
            'receipt_files' => $receiptFiles,
            'updated_by'    => Auth::id(),
        ]);

        VCIServiceItems::where('service_vci_id', $service->id)
            ->update([
                'deleted_at' => now(),
                'deleted_by' => Auth::id(),
            ]);

        /* ================= SAVE NEW ITEMS ================= */
        foreach ($request->items as $index => $item) {

            $imagePath = null;

            if ($request->hasFile("items.$index.upload_image")) {
                $imagePath = $request->file("items.$index.upload_image")
                    ->store('uploads/service_vci/items', 'public');
            } elseif (!empty($item['existing_image'])) {
                $imagePath = $item['existing_image'];
            }

            if ($item['type'] === 'product') {

                for ($s = $item['serial_from']; $s <= $item['serial_to']; $s++) {
                    VCIServiceItems::create([
                        'service_vci_id' => $service->id,
                        'product_id'     => $item['product_id'],
                        'vci_serial_no'  => $s,
                        'status'         => $item['status'],
                        'remarks'        => $item['remarks'] ?? null,
                        'upload_image'   => $imagePath,
                        'created_by'     => Auth::id(),
                        'updated_by'     => Auth::id(),
                    ]);
                }

                continue;
            }

            $sparepart = DB::table('spareparts')->where('id', $item['sparepart_id'])->first();

            $isPCB = (
                stripos($sparepart->name, 'PCB') !== false ||
                stripos($sparepart->name, 'BARCODE') !== false
            );

            VCIServiceItems::create([
                'service_vci_id' => $service->id,
                'sparepart_id'   => $item['sparepart_id'],
                'vci_serial_no'  => $isPCB ? $item['vci_serial_no'] : null,
                'quantity'       => $isPCB ? null : $item['quantity'],
                'status'         => $item['status'],
                'remarks'        => $item['remarks'] ?? null,
                'upload_image'   => $imagePath,
                'created_by'     => Auth::id(),
                'updated_by'     => Auth::id(),
            ]);
        }

        DB::commit();

        return response()->json([
            'message' => 'Service updated successfully',
            'data'    => $service->load('items'),
        ]);

    } catch (\Exception $e) {

        DB::rollBack();

        return response()->json([
            'message' => 'Something went wrong',
            'error'   => $e->getMessage(),
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

    /* ================= RECEIPTS ================= */
    $receiptFiles = [];
    if (!empty($service->receipt_files)) {
        foreach ((array) $service->receipt_files as $file) {
            $receiptFiles[] = ltrim($file, '/');
        }
    }

    $items = [];

    foreach ($service->items as $item) {

        /* ================= PRODUCT ================= */
        if ($item->product_id) {
            $items[] = [
                'id'           => $item->id,
                'type'         => 'product',
                'product_id'   => $item->product_id,
                'serial_from'  => $item->vci_serial_no,
                'serial_to'    => $item->vci_serial_no,
                'status'       => $item->status,
                'remarks'      => $item->remarks,
                'upload_image' => $item->upload_image,
            ];
            continue;
        }

        /* ================= SPAREPART ================= */
        $sparepart = $item->sparepart;

        $isPCB = $sparepart && (
            stripos($sparepart->name, 'PCB') !== false ||
            stripos($sparepart->name, 'BARCODE') !== false
        );

        $items[] = [
            'id'             => $item->id,
            'type'           => 'sparepart',
            'sparepart_id'   => $item->sparepart_id,

            // 🔥 PCB / BARCODE → SERIAL
            'vci_serial_no'  => $isPCB ? $item->vci_serial_no : null,

            // 🔥 NORMAL SPAREPART → QTY
            'quantity'       => $isPCB ? null : $item->quantity,

            'status'         => $item->status,
            'remarks'        => $item->remarks,
            'upload_image'   => $item->upload_image,
        ];
    }

    return response()->json([
        'id'            => $service->id,
        'vendor_id'     => $service->vendor_id,
        'vendor_name'   => optional($service->vendor)->vendor,
        'challan_no'    => $service->challan_no,
        'challan_date'  => $service->challan_date,
        'tracking_no'   => $service->tracking_no,
        'receipt_files' => $receiptFiles,
        'items'         => $items,
        'created_at'    => $service->created_at,
        'updated_at'    => $service->updated_at,
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
        'vendor_id'    => 'required|integer|exists:vendors,id',
        'serial_no'    => 'required|string|max:50',
        'sparepart_id' => 'required|integer|exists:spareparts,id',
    ]);

    $lastEntry = DB::table('service_vci_items')
        ->where('vendor_id', $request->vendor_id)
        ->where('sparepart_id', $request->sparepart_id)
        ->where('vci_serial_no', $request->serial_no)
        ->whereNull('deleted_at')
        ->orderByDesc('id')
        ->first();

    $current = $lastEntry?->status;
    $allowed = [];

    /* =========================
       STRICT SERIAL FLOW
    ========================= */
    if (!$current) {
        // 🔥 First time ONLY
        $allowed = ['Inward'];

    } elseif ($current === 'Inward') {
        // 🚫 BLOCK inward again
        $allowed = ['Delivered']; // or ['Delivered','Return'] if needed

    } else {
        // ✅ After any OTHER status
        $allowed = ['Inward'];
    }

    return response()->json([
        'current_status' => $current,
        'allowed_status' => $allowed,
    ], 200);
}



}