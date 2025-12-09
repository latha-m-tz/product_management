<?php

namespace App\Http\Controllers;

use App\Models\VCIService;
use App\Models\VCIServiceItems;
use App\Models\Product;
use App\Models\Vendor;
use App\Models\spareparts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth;
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
        'vendor_id' => [
            'required',
            'integer',
            function ($attribute, $value, $fail) {
                $exists = Vendor::where('id', $value)
                    ->whereNull('deleted_at')
                    ->exists();

                if (!$exists) {
                    $fail('Selected vendor not found.');
                }
            }
        ],

        'challan_no' => [
            'required',
            'string',
            'max:50',
            Rule::unique('service_vci', 'challan_no')->whereNull('deleted_at')
        ],

        'challan_date' => 'required|date',
        'tracking_no'  => 'nullable|string|max:50',

        'receipt_files.*' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:2048',

        'items' => 'required|array|min:1',

        'items.*.sparepart_id' => 'required|integer',
        'items.*.vci_serial_no' => 'nullable|string|max:50',
        'items.*.quantity'      => 'nullable|integer|min:1',
        'items.*.status'        => 'nullable|string',
        'items.*.remarks'       => 'nullable|string',

        /* ============================================================
           CUSTOM ITEM VALIDATION (PCB VS NON-PCB)
        ============================================================ */
        'items.*' => function ($attribute, $value, $fail) {

            $sparepart_id = $value['sparepart_id'] ?? null;
            $serial       = $value['vci_serial_no'] ?? null;
            $qty          = $value['quantity'] ?? null;
            $status       = $value['status'] ?? null;

            $sparepart = DB::table('spareparts')->where('id', $sparepart_id)->first();
            if (!$sparepart) {
                return $fail("Invalid sparepart selected.");
            }

            // Decide whether PCB or NON-PCB
            $isPCB = stripos($sparepart->name, 'pcb') !== false;

            /* ============================================================
               PCB VALIDATION
            ============================================================ */
            if ($isPCB) {

                if (!$serial) {
                    return $fail("Serial number is required for PCB spareparts.");
                }

                if ($qty) {
                    return $fail("Quantity is NOT allowed for PCB spareparts.");
                }

                if (!$status) {
                    return $fail("Status is required for PCB serial {$serial}.");
                }

                // PCB must exist in purchase stock
                $exists = DB::table('sparepart_purchase_items')
                    ->where('sparepart_id', $sparepart_id)
                    ->where('serial_no', $serial)
                    ->whereNull('deleted_at')
                    ->exists();

                if (!$exists) {
                    return $fail("Serial '{$serial}' does not exist in purchase stock.");
                }

                // PCB Status Flow Check
                $currentStatus = DB::table('service_vci_items')
                    ->where('vci_serial_no', $serial)
                    ->whereNull('deleted_at')
                    ->orderByDesc('id')
                    ->value('status');

                $currentStatus = $currentStatus ? trim($currentStatus) : null;
                $newStatus = trim($status);

                if (!$currentStatus && $newStatus !== 'Inward') {
                    return $fail("Serial {$serial} must start with Inward.");
                }

                if ($currentStatus === $newStatus) {
                    return $fail("Serial {$serial} is already '{$newStatus}'.");
                }

                $allowedNext = [
                    'Inward'    => ['Testing', 'Delivered'],
                    'Testing'   => ['Delivered', 'Return'],
                    'Delivered' => ['Return'],
                    'Return'    => []
                ];

                if ($currentStatus && !in_array($newStatus, $allowedNext[$currentStatus])) {
                    $allowedList = implode(', ', $allowedNext[$currentStatus]);
                    return $fail("Invalid status. Allowed next: {$allowedList}.");
                }

                return;
            }

            /* ============================================================
               NON-PCB VALIDATION
            ============================================================ */
            if (!$qty || $qty <= 0) {
                return $fail("Quantity is required for non-PCB spareparts.");
            }

            if ($serial) {
                return $fail("Serial number is NOT allowed for non-PCB spareparts.");
            }

            // Stock check
            $purchased = DB::table('sparepart_purchase_items')
                ->where('sparepart_id', $sparepart_id)
                ->whereNull('deleted_at')
                ->sum('quantity');

            $used = DB::table('service_vci_items')
                ->where('sparepart_id', $sparepart_id)
                ->whereNull('deleted_at')
                ->sum('quantity');

            $available = max(0, $purchased - $used);

            if ($qty > $available) {
                return $fail("Only {$available} qty available for this sparepart.");
            }
        }
    ]);

    if ($validator->fails()) {
        return response()->json(['errors' => $validator->errors()], 422);
    }

    /* ============================================================
       SAVE MAIN RECORD
    ============================================================ */
    $receiptFiles = [];
    if ($request->hasFile('receipt_files')) {
        foreach ($request->file('receipt_files') as $file) {
            $receiptFiles[] = $file->store('uploads/service_vci/receipts', 'public');
        }
    }

    $service = VCIService::create([
        'vendor_id'     => $request->vendor_id,
        'challan_no'    => $request->challan_no,
        'challan_date'  => $request->challan_date,
        'tracking_no'   => $request->tracking_no,
        'receipt_files' => $receiptFiles,
        'status'        => 'active',
        'created_by'    => Auth::id(),
        'updated_by'    => Auth::id(),
    ]);

    /* ============================================================
       SAVE ITEMS
    ============================================================ */
    foreach ($request->items as $index => $item) {

        $spare = DB::table('spareparts')->where('id', $item['sparepart_id'])->first();
        $isPCB = stripos($spare->name, 'pcb') !== false;

        $imagePath = null;

        if ($request->hasFile("items.$index.upload_image")) {
            $imagePath = $request->file("items.$index.upload_image")
                ->store("uploads/service_vci/items", "public");
        }

        VCIServiceItems::create([
            'service_vci_id' => $service->id,
            'sparepart_id'   => $item['sparepart_id'],

            // PCB → save serial, NON-PCB → NULL
            'vci_serial_no'  => $isPCB ? ($item['vci_serial_no'] ?? null) : null,

            // NON-PCB → save qty, PCB → NULL
            'quantity'       => $isPCB ? null : ($item['quantity'] ?? null),

            'status'         => $item['status'] ?? null,
            'remarks'        => $item['remarks'] ?? null,
            'upload_image'   => $imagePath,
            'created_by'     => Auth::id(),
            'updated_by'     => Auth::id(),
        ]);
    }

    $service->load(['items.sparepart', 'vendor']);

    return response()->json([
        'message' => 'VCI Service created successfully',
        'data'    => $service
    ], 201);
}



public function update(Request $request, $id)
{
    $service = VCIService::whereNull('deleted_at')->find($id);
    if (!$service) {
        return response()->json(['message' => 'Service VCI not found'], 404);
    }

    $validator = Validator::make($request->all(), [

        'vendor_id' => [
            'required',
            'integer',
            function ($attribute, $value, $fail) {
                $exists = Vendor::where('id', $value)
                    ->whereNull('deleted_at')
                    ->exists();
                if (!$exists) $fail('Selected vendor not found.');
            }
        ],

      'challan_no' => [
    'required',
    'string',
    'max:50',
    // Rule::unique('service_vci', 'challan_no')
    //     ->ignore($id)
    //     ->whereNull('deleted_at'),
],

        'challan_date' => 'required|date',
        'tracking_no'  => 'nullable|string|max:50',
        'receipt_files.*' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:2048',

        'items' => 'required|array|min:1',

        'items.*.id'            => 'nullable|integer|exists:service_vci_items,id',
        'items.*.sparepart_id'  => 'required|integer',
        'items.*.vci_serial_no' => 'nullable|string|max:50',
        'items.*.quantity'      => 'nullable|integer|min:1',

        'items.*' => function ($attribute, $value, $fail) use ($id) {

            $sparepart_id = $value['sparepart_id'];
            $serial       = $value['vci_serial_no'] ?? null;
            $qty          = $value['quantity'] ?? null;
            $itemId       = $value['id'] ?? null; 

            $sparepart = DB::table('spareparts')->where('id', $sparepart_id)->first();
            if (!$sparepart) {
                return $fail("Invalid sparepart selected.");
            }

            $isPCB = stripos($sparepart->name, 'pcb') !== false;

            if ($isPCB) {

                if (!$serial) {
                    return $fail("Serial number is required for PCB spareparts.");
                }

                if (!empty($qty)) {
                    return $fail("Quantity is not allowed for PCB spareparts.");
                }

                // Serial must exist in purchased
                $exists = DB::table('sparepart_purchase_items')
                    ->where('sparepart_id', $sparepart_id)
                    ->where('serial_no', $serial)
                    ->whereNull('deleted_at')
                    ->exists();

                if (!$exists) {
                    return $fail("Serial '{$serial}' was not purchased for this PCB sparepart.");
                }

                return; // PCB DONE
            }
            if (!$qty || $qty <= 0) {
                return $fail("Quantity is required for non-PCB spareparts.");
            }

            if (!empty($serial)) {
                return $fail("Serial number is not allowed for non-PCB spareparts.");
            }


            // Purchased
            $purchased = DB::table('sparepart_purchase_items')
                ->where('sparepart_id', $sparepart_id)
                ->whereNull('deleted_at')
                ->sum('quantity');

            // Used by OTHER services
            $usedOther = DB::table('service_vci_items')
                ->where('sparepart_id', $sparepart_id)
                ->where('service_vci_id', '!=', $id)
                ->whereNull('deleted_at')
                ->sum('quantity');

            // Used inside THIS service (excluding current row)
            $usedInside = DB::table('service_vci_items')
                ->where('sparepart_id', $sparepart_id)
                ->where('service_vci_id', $id)
                ->where('id', '!=', $itemId)
                ->whereNull('deleted_at')
                ->sum('quantity');

            $totalUsed = $usedOther + $usedInside;

            $available = max(0, $purchased - $totalUsed);

            if ($qty > $available) {
                return $fail("Only {$available} quantity available for this sparepart.");
            }
        }
    ]);

    if ($validator->fails()) {
        return response()->json(['errors' => $validator->errors()], 422);
    }

    $receiptFiles = $service->receipt_files ?? [];

    if ($request->hasFile('receipt_files')) {

        foreach ($receiptFiles as $file) {
            if ($file && Storage::disk('public')->exists($file)) {
                Storage::disk('public')->delete($file);
            }
        }

        $receiptFiles = [];
        foreach ($request->file('receipt_files') as $file) {
            $receiptFiles[] = $file->store('uploads/service_vci/receipts', 'public');
        }
    }

    $service->update([
        'vendor_id'     => $request->vendor_id,
        'challan_no'    => $request->challan_no,
        'challan_date'  => $request->challan_date,
        'tracking_no'   => $request->tracking_no ?? $service->tracking_no,
        'receipt_files' => $receiptFiles,
        'updated_by'    => Auth::id(),
    ]);

    $existingIds = $service->items()->whereNull('deleted_at')->pluck('id')->toArray();
    $incomingIds = collect($request->items)->pluck('id')->filter()->toArray();

    $toDelete = array_diff($existingIds, $incomingIds);

    if (!empty($toDelete)) {
        $deleteModels = VCIServiceItems::whereIn('id', $toDelete)->get();

        foreach ($deleteModels as $del) {
            if ($del->upload_image && Storage::disk('public')->exists($del->upload_image)) {
                Storage::disk('public')->delete($del->upload_image);
            }
        }

        VCIServiceItems::whereIn('id', $toDelete)->update([
            'deleted_at' => now(),
            'deleted_by' => Auth::id(),
        ]);
    }

    foreach ($request->items as $item) {

        $uploadPath = null;

        if (!empty($item['upload_image']) && $item['upload_image'] instanceof \Illuminate\Http\UploadedFile) {
            $uploadPath = $item['upload_image']->store('uploads/service_vci/items', 'public');
        }

        // UPDATE existing item
        if (!empty($item['id'])) {

            $existing = VCIServiceItems::whereNull('deleted_at')->find($item['id']);
            if ($existing) {

                if ($uploadPath && $existing->upload_image && Storage::disk('public')->exists($existing->upload_image)) {
                    Storage::disk('public')->delete($existing->upload_image);
                }

                $existing->update([
                    'sparepart_id'  => $item['sparepart_id'],
                    'vci_serial_no' => $item['vci_serial_no'] ?? null,
                    'quantity'      => $item['quantity'] ?? null,
                    'status'        => $item['status'] ?? $existing->status,
                    'remarks'       => $item['remarks'] ?? $existing->remarks,
                    'upload_image'  => $uploadPath ?? $existing->upload_image,
                    'updated_by'    => Auth::id(),
                ]);
            }

        } else {

            VCIServiceItems::create([
                'service_vci_id' => $service->id,
                'sparepart_id'   => $item['sparepart_id'],
                'vci_serial_no'  => $item['vci_serial_no'] ?? null,
                'quantity'       => $item['quantity'] ?? null,
                'status'         => $item['status'] ?? null,
                'remarks'        => $item['remarks'] ?? null,
                'upload_image'   => $uploadPath,
                'created_by'     => Auth::id(),
                'updated_by'     => Auth::id(),
            ]);
        }
    }

    $service->load(['vendor', 'items.sparepart']);

    $service->receipt_files_urls = [];
    foreach ($service->receipt_files ?? [] as $file) {
        $service->receipt_files_urls[] = asset('storage/' . $file);
    }

    foreach ($service->items as $item) {
        $item->upload_image = $item->upload_image
            ? asset('storage/' . $item->upload_image)
            : null;
    }

    return response()->json($service, 200);
}
 public function show($id)
{
    $service = VCIService::with(['vendor', 'items.sparepart'])
        ->whereNull('deleted_at')
        ->find($id);

    if (!$service) {
        return response()->json(['message' => 'Service VCI not found'], 404);
    }

    $vendorName = $service->vendor ? $service->vendor->vendor : null;

    $receiptFiles = [];
    if (is_array($service->receipt_files)) {
        foreach ($service->receipt_files as $file) {
            $receiptFiles[] = asset('storage/' . $file);
        }
    }

    // Fix upload_image path
    foreach ($service->items as $item) {
        $item->upload_image = $item->upload_image
            ? asset('storage/' . ltrim($item->upload_image, '/'))
            : null;
    }

    return response()->json([
        'id'                => $service->id,
        'vendor_id'         => $service->vendor_id,
        'vendor_name'       => $vendorName,
        'challan_no'        => $service->challan_no,
        'challan_date'      => $service->challan_date,
        'tracking_no'       => $service->tracking_no,
        'status'            => $service->status,
        'receipt_files'     => $service->receipt_files,
        'receipt_files_urls'=> $receiptFiles,
        'items'             => $service->items,
        'created_at'        => $service->created_at,
        'updated_at'        => $service->updated_at,
    ], 200);
}



    public function destroy($id)
    {
        $service = VCIService::whereNull('deleted_at')->find($id);
        if (!$service) {
            return response()->json(['message' => 'Service VCI not found'], 404);
        }

        // mark items deleted
        $service->items()->whereNull('deleted_at')->update([
            'deleted_at' => now(),
            'deleted_by' => Auth::id()
        ]);

        // mark service deleted
        $service->update([
            'deleted_at' => now(),
            'deleted_by' => Auth::id()
        ]);

        return response()->json(['message' => 'Service VCI and its items soft deleted successfully'], 200);
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
            ->whereNull('service_vci_items.deleted_at'); // table-specific null check

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
    // 1️⃣ Check product exists
    $product = Sparepart::find($id);
    if (!$product) {
        return response()->json([
            'error' => 'Product not found'
        ], 404);
    }

    // 2️⃣ Total purchased quantity (using qty column)
    $purchasedQty = DB::table('sparepart_purchase_items')
        ->where('sparepart_id', $id)
        ->whereNull('deleted_at')
        ->sum('qty');   // <= correct purchase qty

    // 3️⃣ Used in service_vci_items (each row = 1 used serial)
    $usedService = DB::table('service_vci_items')
        ->where('product_id', $id)
        ->whereNull('deleted_at')
        ->count();

    // 4️⃣ Used in assembly (each row = 1 used)
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



}
