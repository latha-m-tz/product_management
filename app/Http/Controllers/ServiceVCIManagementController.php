<?php

namespace App\Http\Controllers;

use App\Models\VCIService;
use App\Models\VCIServiceItems;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
class ServiceVCIManagementController extends Controller
{
public function index()
{
    $services = VCIService::with(['vendor', 'items.product'])->get();

    $data = $services->map(function ($service) {
        return [
            'id' => $service->id,
            'vendor_id' => $service->vendor->id ?? '-',
            'challan_no' => $service->challan_no,
            'challan_date' => $service->challan_date,
            'status' => $service->status,
            'created_at' => $service->created_at->format('Y-m-d H:i:s'),
            'items' => $service->items->map(function ($item) {
                return [
                    'id' => $item->id,
                    'vci_serial_no' => $item->vci_serial_no,
                    'status' => $item->status === 'delivery' ? 'Delivered' : $item->status,
                    'created_at' => $item->created_at->format('Y-m-d H:i:s'),
                    'upload_image' => $item->upload_image,
                    'product' => $item->product->name ?? '-',
                ];
            }),
        ];
    });

    return response()->json($data);
}


public function store(Request $request)
{
    $validator = Validator::make($request->all(), [
        'vendor_id' => 'required|exists:vendors,id',
        'challan_no' => 'required|string|max:50',
        'challan_date' => 'required|date',
        'tracking_no' => 'nullable|string|max:50', 

        'receipt_files.*' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:2048',

        'items' => 'required|array|min:1',
        'items.*.product_id' => 'required|integer|exists:product,id',
        'items.*.vci_serial_no' => 'required|string|max:50',
        'items.*.status' => 'nullable|string|max:50',
        'items.*.remarks' => 'nullable|string|max:255',
        'items.*.upload_image' => [
            'nullable',
            function ($attribute, $value, $fail) {
                if ($value && !($value instanceof \Illuminate\Http\UploadedFile)) {
                    $fail('The '.$attribute.' must be a valid file.');
                }
            },
            'mimes:jpg,jpeg,png,pdf',
            'max:2048',
        ],
    ]);

    if ($validator->fails()) {
        return response()->json(['errors' => $validator->errors()], 422);
    }

    // Handle receipt file uploads
    $receiptFiles = [];
    if ($request->hasFile('receipt_files')) {
        foreach ($request->file('receipt_files') as $file) {
            $receiptFiles[] = $file->store('uploads/service_vci/receipts', 'public');
        }
    }

    // Create main VCI service record
    $serviceVCI = VCIService::create([
        'vendor_id' => $request->vendor_id,
        'challan_no' => $request->challan_no,
        'challan_date' => $request->challan_date,
        'tracking_no' => $request->tracking_no ?? null,
        'receipt_files' => $receiptFiles,
        'status' => 'active',
        'created_by' => auth()->id() ?? 1,
        'updated_by' => auth()->id() ?? 1,
    ]);

    // Handle each service item
    foreach ($request->items as $item) {
        // Check if the same serial already exists for this service
        $exists = VCIServiceItems::where('service_vci_id', $serviceVCI->id)
            ->where('vci_serial_no', $item['vci_serial_no'])
            ->exists();
        if ($exists) {
            continue; // Skip duplicate serial
        }

        $uploadPath = null;
        if (isset($item['upload_image']) && $item['upload_image'] instanceof \Illuminate\Http\UploadedFile) {
            $uploadPath = $item['upload_image']->store('uploads/service_vci/items', 'public');
        }

        VCIServiceItems::create([
            'service_vci_id' => $serviceVCI->id,
            'product_id' => $item['product_id'],
            'vci_serial_no' => $item['vci_serial_no'],
            'status' => $item['status'] ?? null,
            'remarks' => $item['remarks'] ?? null,
            'upload_image' => $uploadPath,
            'created_by' => auth()->id() ?? 1,
            'updated_by' => auth()->id() ?? 1,
        ]);
    }

    // Load relations for response
    $serviceVCI->load(['items.product', 'vendor']);

    return response()->json([
        'message' => 'VCI Service created successfully',
        'data' => $serviceVCI
    ], 201);
}

        
public function show($id)
{
    $serviceVCI = VCIService::with([
        'vendor',           // include vendor
        'items.product',    // include product for items
    ])->find($id);

    if (!$serviceVCI) {
        return response()->json(['message' => 'Service VCI not found'], 404);
    }

    // Convert receipt files to full URLs
    $receiptFiles = [];
    if (!empty($serviceVCI->receipt_files) && is_array($serviceVCI->receipt_files)) {
        foreach ($serviceVCI->receipt_files as $file) {
            $receiptFiles[] = asset('storage/' . $file);
        }
    }

    foreach ($serviceVCI->items as $item) {
        $item->upload_image = $item->upload_image
            ? asset('storage/' . $item->upload_image)
            : null;
    }

    $serviceVCI->receipt_files_urls = $receiptFiles;

    return response()->json([
        'id' => $serviceVCI->id,
        'vendor_id' => $serviceVCI->vendor_id,
        'vendor_name' => $serviceVCI->vendor->vendor ?? null, // vendor name
        'challan_no' => $serviceVCI->challan_no,
        'challan_date' => $serviceVCI->challan_date,
        'tracking_no' =>$serviceVCI->tracking_no,
        'status' => $serviceVCI->status,
        'receipt_files' => $serviceVCI->receipt_files,
        'receipt_files_urls' => $serviceVCI->receipt_files_urls,
        'items' => $serviceVCI->items,
        'created_at' => $serviceVCI->created_at,
        'updated_at' => $serviceVCI->updated_at,
    ], 200);
}


public function update(Request $request, $id)
{
    $serviceVCI = VCIService::findOrFail($id);

    $validator = Validator::make($request->all(), [
        'vendor_id' => 'required|exists:vendors,id',
        'challan_no' => 'required|string|max:50',
        'challan_date' => 'required|date',
        'tracking_no' => 'nullable|string',
        'status' => 'nullable|string|max:50',

        // Receipt files with validation
        'receipt_files.*' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:2048',

        // Items
        'items' => 'required|array|min:1',
        'items.*.id' => 'nullable|integer|exists:service_vci_items,id',
        'items.*.product_id' => 'required|integer|exists:product,id',
        'items.*.vci_serial_no' => 'required|string|max:50',
        'items.*.status' => 'nullable|string|max:50',
        'items.*.remarks' => 'nullable|string|max:255',

        // No restrictions for upload_image
        'items.*.upload_image' => 'nullable',
    ]);

    if ($validator->fails()) {
        return response()->json(['errors' => $validator->errors()], 422);
    }

    // ---------------------------
    // HANDLE RECEIPT FILES
    // ---------------------------
    $receiptFiles = $serviceVCI->receipt_files ?? [];

    if ($request->hasFile('receipt_files')) {

        // Delete old files
        foreach ($receiptFiles as $file) {
            if (Storage::disk('public')->exists($file)) {
                Storage::disk('public')->delete($file);
            }
        }

        // Store new files
        $receiptFiles = [];
        foreach ($request->file('receipt_files') as $file) {
            $receiptFiles[] = $file->store('uploads/service_vci/receipts', 'public');
        }
    }

    // ---------------------------
    // UPDATE MAIN SERVICE
    // ---------------------------
    $serviceVCI->update([
        'vendor_id' => $request->vendor_id,
        'challan_no' => $request->challan_no,
        'challan_date' => $request->challan_date,
        'tracking_no' => $request->tracking_no,
        'status' => $request->status ?? $serviceVCI->status,
        'receipt_files' => $receiptFiles,
    ]);

    // ---------------------------
    // HANDLE ITEMS (DELETE REMOVED)
    // ---------------------------
    $existingItemIds = $serviceVCI->items()->pluck('id')->toArray();
    $incomingItemIds = collect($request->items)->pluck('id')->filter()->toArray();
    $itemsToDelete = array_diff($existingItemIds, $incomingItemIds);

    if (!empty($itemsToDelete)) {
        $itemsToDeleteModels = VCIServiceItems::whereIn('id', $itemsToDelete)->get();

        foreach ($itemsToDeleteModels as $item) {
            if ($item->upload_image && Storage::disk('public')->exists($item->upload_image)) {
                Storage::disk('public')->delete($item->upload_image);
            }
        }

        VCIServiceItems::whereIn('id', $itemsToDelete)->delete();
    }

    // ---------------------------
    // UPDATE OR CREATE ITEMS
    // ---------------------------
    foreach ($request->items as $item) {

        $uploadPath = null;

        // If new file uploaded
        if (isset($item['upload_image']) && $item['upload_image'] instanceof \Illuminate\Http\UploadedFile) {
            $uploadPath = $item['upload_image']->store('uploads/service_vci/items', 'public');
        }

        // If existing full URL -> convert to relative storage path
        elseif (!empty($item['upload_image']) && is_string($item['upload_image'])) {
            $uploadPath = str_replace(url('storage') . '/', '', $item['upload_image']);
        }

        // UPDATE
        if (!empty($item['id'])) {
            $existingItem = VCIServiceItems::find($item['id']);

            if ($existingItem) {
                $existingItem->update([
                    'product_id' => $item['product_id'],
                    'vci_serial_no' => $item['vci_serial_no'],
                    'status' => $item['status'] ?? $existingItem->status,
                    'remarks' => $item['remarks'] ?? $existingItem->remarks,
                    'upload_image' => $uploadPath ?? $existingItem->upload_image,
                ]);
            }
        }

        // CREATE
        else {
            VCIServiceItems::create([
                'service_vci_id' => $serviceVCI->id,
                'product_id' => $item['product_id'],
                'vci_serial_no' => $item['vci_serial_no'],
                'status' => $item['status'] ?? null,
                'remarks' => $item['remarks'] ?? null,
                'upload_image' => $uploadPath,
            ]);
        }
    }

    // Reload relationships
    $serviceVCI->load(['vendor', 'items.product']);

    return response()->json($serviceVCI, 200);
}




private function normalizeImagePath($path)
{
    if (!$path) {
        return null;
    }

    $path = str_replace([
        url('storage') . '/',
        'http://localhost:8000/storage/',
        'https://localhost:8000/storage/',
        'http://127.0.0.1:8000/storage/',
        'https://127.0.0.1:8000/storage/',
    ], '', $path);

    return ltrim($path, '/');
}



    public function destroy($id)
    {
        $serviceVCI = VCIService::find($id);
        if (!$serviceVCI) {
            return response()->json(['message' => 'Service VCI not found'], 404);
        }

        $serviceVCI->items()->delete();
        $serviceVCI->delete();

        return response()->json(['message' => 'Service VCI and its items deleted successfully']);
    }
    
    public function getAllVCISerialNumbers()
    {
        $serialNumbers = VCIServiceItems::pluck('vci_serial_no')->map(fn($s) => trim($s));
        return response()->json($serialNumbers);
    }
    public function getAllServiceItems(Request $request)
{
    $query = VCIServiceItems::query()
        ->with(['serviceVCI', 'product', 'vendor']);

    // Apply filters if present
    if ($request->filled('from_place')) {
        $query->whereHas('serviceVCI', function ($q) use ($request) {
            $q->where('from_place', $request->from_place);
        });
    }

    if ($request->filled('to_place')) {
        $query->whereHas('serviceVCI', function ($q) use ($request) {
            $q->where('to_place', $request->to_place);
        });
    }

    if ($request->filled('tracking_number')) {
        $query->whereHas('serviceVCI', function ($q) use ($request) {
            $q->where('tracking_number', 'like', '%' . $request->tracking_number . '%');
        });
    }

    if ($request->filled('testing_status')) {
        $query->where('testing_status', $request->testing_status);
    }

    $items = $query->get();

    // Format response
    $response = $items->map(function ($item) {
        return [
            'id' => $item->id,
            'vci_serial_no' => $item->vci_serial_no,
            'product_name' => $item->product?->name,
            'vendor_name' => $item->vendor?->vendor,
            'testing_status' => $item->testing_status,
            'tracking_number' => $item->serviceVCI?->tracking_number,
            'from_place' => $item->serviceVCI?->from_place,
            'to_place' => $item->serviceVCI?->to_place,
            'upload_image' => $item->upload_image ? asset('storage/' . $item->upload_image) : null,
        ];
    });

    return response()->json($response);
}

}