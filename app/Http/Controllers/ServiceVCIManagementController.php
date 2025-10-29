<?php

namespace App\Http\Controllers;

use App\Models\VCIService;
use App\Models\VCIServiceItems;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ServiceVCIManagementController extends Controller
{
    public function index()
    {
        $serviceVCIs = VCIService::with('items')->get();
        return response()->json($serviceVCIs);
    }

public function store(Request $request)
{
    $validator = Validator::make($request->all(), [
        'challan_no' => 'required|string|max:50',
        'challan_date' => 'required|date',
        'courier_name' => 'nullable|string|max:100',
        'hsn_code' => 'nullable|string|max:20',
        'quantity' => 'integer|min:1',
        'status' => 'nullable|string|max:20',
        'sent_date' => 'nullable|date',
        'received_date' => 'nullable|date',
        'from_place' => 'nullable|string|max:100',
        'to_place' => 'nullable|string|max:100',
        'tracking_number' => 'nullable|string|max:100',
        'challan_1' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:2048',
        'challan_2' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:2048',
        'receipt_upload' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:2048',
        'items' => 'required|array|min:1',
        'items.*.product_id' => 'required|integer|exists:product,id',
        'items.*.vci_serial_no' => 'required|string|max:50',
        'items.*.tested_date' => 'nullable|date',
        'items.*.issue_found' => 'nullable|string|max:100',
        'items.*.upload_image' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:2048',
        'items.*.testing_assigned_to' => 'nullable|string|max:255',
        'items.*.testing_status' => 'nullable|string|max:50',
    ]);

    if ($validator->fails()) {
        return response()->json(['errors' => $validator->errors()], 422);
    }

    // Handle main service file uploads
    $trackingNumber = $request->tracking_number ?? null;
    $challan1Path = $request->hasFile('challan_1')
        ? $request->file('challan_1')->store('uploads/service_vci/challans', 'public')
        : null;
    $challan2Path = $request->hasFile('challan_2')
        ? $request->file('challan_2')->store('uploads/service_vci/challans', 'public')
        : null;
    $receiptPath = $request->hasFile('receipt_upload')
        ? $request->file('receipt_upload')->store('uploads/service_vci/receipts', 'public')
        : null;

    // Create the main VCI Service record
    $serviceVCI = VCIService::create([
        'challan_no' => $request->challan_no,
        'challan_date' => $request->challan_date,
        'courier_name' => $request->courier_name,
        'hsn_code' => $request->hsn_code,
        'quantity' => $request->quantity,
        'status' => $request->status ?? 'active',
        'sent_date' => $request->sent_date,
        'received_date' => $request->received_date,
        'from_place' => $request->from_place,
        'to_place' => $request->to_place,
        'tracking_number' => $trackingNumber,
        'challan_1' => $challan1Path,
        'challan_2' => $challan2Path,
        'receipt_upload' => $receiptPath,
    ]);

    // Loop through items and insert
    foreach ($request->items as $item) {
        $uploadPath = null;

        if (isset($item['upload_image']) && $item['upload_image'] instanceof \Illuminate\Http\UploadedFile) {
            $uploadPath = $item['upload_image']->store('uploads/service_vci/items', 'public');
        }

        VCIServiceItems::create([
            'service_vci_id'      => $serviceVCI->id,
            'product_id'          => $item['product_id'],
            'vci_serial_no'       => $item['vci_serial_no'],
            'warranty_status'     => $item['warranty_status'] ?? null,
            'testing_assigned_to' => $item['testing_assigned_to'] ?? null,
            'tested_date'         => $item['tested_date'] ?? null,
            'testing_status'      => $item['testing_status'] ?? null,
            'issue_found'         => $item['issue_found'] ?? null,
            'action_taken'        => $item['action_taken'] ?? null,
            'urgent'              => $item['urgent'] ?? false,
            'upload_image'        => $uploadPath,
        ]);
    }

    // Load relations
    $serviceVCI->load(['items.product', 'items.vendor', 'items.serviceVCI']);

    return response()->json($serviceVCI, 201);
}

        
  public function show($id)
{
    $serviceVCI = VCIService::with([
        'items',                // service_vci_items
        'items.product',        // related product
    ])->find($id);

    if (!$serviceVCI) {
        return response()->json(['message' => 'Service VCI not found'], 404);
    }

    $serviceVCI->challan_1 = $serviceVCI->challan_1 ? asset('storage/' . $serviceVCI->challan_1) : null;
    $serviceVCI->challan_2 = $serviceVCI->challan_2 ? asset('storage/' . $serviceVCI->challan_2) : null;
    $serviceVCI->receipt_upload = $serviceVCI->receipt_upload ? asset('storage/' . $serviceVCI->receipt_upload) : null;

    foreach ($serviceVCI->items as $item) {
        $item->upload_image = $item->upload_image ? asset('storage/' . $item->upload_image) : null;
    }

    return response()->json($serviceVCI, 200);
}

public function update(Request $request, $id)
{
    $serviceVCI = VCIService::findOrFail($id);

    $validator = Validator::make($request->all(), [
        'challan_no' => 'required|string|max:50',
        'challan_date' => 'required|date',
        'courier_name' => 'nullable|string|max:100',
        'hsn_code' => 'nullable|string|max:20',
        'quantity' => 'nullable|integer|min:1',
        'status' => 'nullable|string|max:20',
        'sent_date' => 'nullable|date',
        'received_date' => 'nullable|date',
        'from_place' => 'nullable|string|max:100',
        'to_place' => 'nullable|string|max:100',
        'tracking_number' => 'nullable|string|max:100',

        // ✅ Accept either a file OR an existing string path
        'challan_1' => 'nullable',
        'challan_2' => 'nullable',
        'receipt_upload' => 'nullable',

        // ✅ Relaxed rules for update (items can exist or be new)
        'items' => 'required|array|min:1',
        'items.*.id' => 'nullable|integer|exists:service_vci_items,id',
        'items.*.product_id' => 'required|integer|exists:product,id',
        'items.*.vci_serial_no' => 'required|string|max:50',
        'items.*.tested_date' => 'nullable|date',
        'items.*.issue_found' => 'nullable|string|max:255',
        'items.*.upload_image' => 'nullable',
        'items.*.testing_assigned_to' => 'nullable|string|max:255',
        'items.*.testing_status' => 'nullable|string|max:50',
    ]);

    if ($validator->fails()) {
        return response()->json(['errors' => $validator->errors()], 422);
    }

    $challan1Path = $serviceVCI->challan_1;
    if ($request->hasFile('challan_1')) {
        if ($challan1Path && Storage::disk('public')->exists($challan1Path)) {
            Storage::disk('public')->delete($challan1Path);
        }
        $challan1Path = $request->file('challan_1')->store('uploads/service_vci/challans', 'public');
    }

    $challan2Path = $serviceVCI->challan_2;
    if ($request->hasFile('challan_2')) {
        if ($challan2Path && Storage::disk('public')->exists($challan2Path)) {
            Storage::disk('public')->delete($challan2Path);
        }
        $challan2Path = $request->file('challan_2')->store('uploads/service_vci/challans', 'public');
    }

    $receiptPath = $serviceVCI->receipt_upload;
    if ($request->hasFile('receipt_upload')) {
        if ($receiptPath && Storage::disk('public')->exists($receiptPath)) {
            Storage::disk('public')->delete($receiptPath);
        }
        $receiptPath = $request->file('receipt_upload')->store('uploads/service_vci/receipts', 'public');
    }

    // 🧩 Update main service details
    $serviceVCI->update([
        'challan_no' => $request->challan_no,
        'challan_date' => $request->challan_date,
        'courier_name' => $request->courier_name,
        'hsn_code' => $request->hsn_code,
        'quantity' => $request->quantity,
        'status' => $request->status ?? $serviceVCI->status,
        'sent_date' => $request->sent_date,
        'received_date' => $request->received_date,
        'from_place' => $request->from_place,
        'to_place' => $request->to_place,
        'tracking_number' => $request->tracking_number,
        'challan_1' => $challan1Path,
        'challan_2' => $challan2Path,
        'receipt_upload' => $receiptPath,
    ]);

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

    // 🔁 Update or create items
    foreach ($request->items as $item) {
        $uploadPath = $item['upload_image'] ?? null;

        // If a new file is uploaded
        if (isset($item['upload_image']) && $item['upload_image'] instanceof \Illuminate\Http\UploadedFile) {
            $uploadPath = $item['upload_image']->store('uploads/service_vci/items', 'public');
        }

        if (isset($item['id'])) {
            $existingItem = VCIServiceItems::find($item['id']);
            if ($existingItem) {
                $existingItem->update([
                    'product_id' => $item['product_id'],
                    'vci_serial_no' => $item['vci_serial_no'],
                    'warranty_status' => $item['warranty_status'] ?? $existingItem->warranty_status,
                    'testing_assigned_to' => $item['testing_assigned_to'] ?? $existingItem->testing_assigned_to,
                    'tested_date' => $item['tested_date'] ?? $existingItem->tested_date,
                    'testing_status' => $item['testing_status'] ?? $existingItem->testing_status,
                    'issue_found' => $item['issue_found'] ?? $existingItem->issue_found,
                    'action_taken' => $item['action_taken'] ?? $existingItem->action_taken,
                    'urgent' => $item['urgent'] ?? $existingItem->urgent,
                    'upload_image' => $uploadPath ?? $existingItem->upload_image,
                ]);
            }
        } else {
            VCIServiceItems::create([
                'service_vci_id' => $serviceVCI->id,
                'product_id' => $item['product_id'],
                'vci_serial_no' => $item['vci_serial_no'],
                'warranty_status' => $item['warranty_status'] ?? null,
                'testing_assigned_to' => $item['testing_assigned_to'] ?? null,
                'tested_date' => $item['tested_date'] ?? null,
                'testing_status' => $item['testing_status'] ?? null,
                'issue_found' => $item['issue_found'] ?? null,
                'action_taken' => $item['action_taken'] ?? null,
                'urgent' => $item['urgent'] ?? false,
                'upload_image' => $uploadPath,
            ]);
        }
    }

    $serviceVCI->load(['items.product', 'items.vendor', 'items.serviceVCI']);

    return response()->json($serviceVCI, 200);
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