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
    $challan1Path = $request->hasFile('challan_1') ? $request->file('challan_1')->store('uploads/service_vci/challans', 'public') : null;
    $challan2Path = $request->hasFile('challan_2') ? $request->file('challan_2')->store('uploads/service_vci/challans', 'public') : null;
    $receiptPath = $request->hasFile('receipt_upload') ? $request->file('receipt_upload')->store('uploads/service_vci/receipts', 'public') : null;

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

    // Loop through each item
    foreach ($request->items as $index => $itemData) {
        $uploadImagePath = $request->hasFile("items.$index.upload_image")
            ? $request->file("items.$index.upload_image")->store('uploads/service_vci/images', 'public')
            : null;

        $serviceVCI->items()->create([
            'product_id' => $itemData['product_id'],
            'vci_serial_no' => $itemData['vci_serial_no'],
            'tested_date' => $itemData['tested_date'] ?? null,
            'issue_found' => $itemData['issue_found'] ?? null,
            'upload_image' => $uploadImagePath,
            'testing_assigned_to' => $itemData['testing_assigned_to'] ?? null,
            'testing_status' => $itemData['testing_status'] ?? 'pending',
        ]);
    }

    // Load relations
    $serviceVCI->load(['items.product', 'items.vendor', 'items.serviceVCI']);

    return response()->json($serviceVCI, 201);
}

        
    public function show($id)
    {
        $serviceVCI = VCIService::with('items')->find($id);
        if (!$serviceVCI) {
            return response()->json(['message' => 'Service VCI not found'], 404);
        }
        return response()->json($serviceVCI);
    }
public function update(Request $request, $id)
{
    $serviceVCI = VCIService::find($id);
    if (!$serviceVCI) {
        return response()->json(['message' => 'Service VCI not found'], 404);
    }

    // ✅ Parse all input (FormData compatible)
    $input = $request->all();

    // ✅ Extract nested item data (FormData flatten fix)
    $items = [];
    foreach ($input as $key => $value) {
        if (preg_match('/^items\[(\d+)\]\[(.+)\]$/', $key, $matches)) {
            $index = $matches[1];
            $field = $matches[2];
            $items[$index][$field] = $value;
        }
    }

    // ✅ Handle file uploads
    $challan1Path = $serviceVCI->challan_1;
    $challan2Path = $serviceVCI->challan_2;
    $receiptPath  = $serviceVCI->receipt_upload;

    if ($request->hasFile('challan_1')) {
        $challan1Path = $request->file('challan_1')->store('uploads/service_vci/challans', 'public');
    }
    if ($request->hasFile('challan_2')) {
        $challan2Path = $request->file('challan_2')->store('uploads/service_vci/challans', 'public');
    }
    if ($request->hasFile('receipt_upload')) {
        $receiptPath = $request->file('receipt_upload')->store('uploads/service_vci/receipts', 'public');
    }

    // ✅ Update main record (force overwrite if field exists in request)
    $serviceVCI->update([
        'challan_no'      => $request->has('challan_no') ? $request->challan_no : $serviceVCI->challan_no,
        'challan_date'    => $request->has('challan_date') ? $request->challan_date : $serviceVCI->challan_date,
        'courier_name'    => $request->has('courier_name') ? $request->courier_name : $serviceVCI->courier_name,
        'quantity'        => $request->has('quantity') ? $request->quantity : $serviceVCI->quantity,
        'status'          => $request->has('status') ? $request->status : $serviceVCI->status,
        'sent_date'       => $request->has('sent_date') ? $request->sent_date : $serviceVCI->sent_date,
        'received_date'   => $request->has('received_date') ? $request->received_date : $serviceVCI->received_date,
        'from_place'      => $request->has('from_place') ? $request->from_place : $serviceVCI->from_place,
        'to_place'        => $request->has('to_place') ? $request->to_place : $serviceVCI->to_place,
        'tracking_number' => $request->has('tracking_number') ? $request->tracking_number : $serviceVCI->tracking_number,
        'challan_1'       => $challan1Path,
        'challan_2'       => $challan2Path,
        'receipt_upload'  => $receiptPath,
    ]);

    // ✅ Update or create items
    foreach ($items as $data) {
        $item = isset($data['id']) ? VCIServiceItems::find($data['id']) : null;

        if (!$item) {
            $item = new VCIServiceItems();
            $item->service_vci_id = $serviceVCI->id;
        }

        // ✅ Update fields properly
        $item->product_id          = $data['product_id'] ?? $item->product_id;
        $item->vci_serial_no       = $data['vci_serial_no'] ?? $item->vci_serial_no;
        $item->tested_date         = $data['tested_date'] ?? $item->tested_date;
        $item->issue_found         = $data['issue_found'] ?? $item->issue_found;
        $item->action_taken        = $data['action_taken'] ?? $item->action_taken;
        $item->testing_assigned_to = $data['testing_assigned_to'] ?? $item->testing_assigned_to;
        $item->testing_status      = $data['testing_status'] ?? $item->testing_status;
        $item->warranty_status     = $data['warranty_status'] ?? $item->warranty_status;
        $item->urgent              = isset($data['urgent']) ? filter_var($data['urgent'], FILTER_VALIDATE_BOOLEAN) : false;

        $item->save();
    }

    // ✅ Reload with all relationships
    $serviceVCI->load(['items.product', 'items.vendor']);

    return response()->json([
        'message' => 'Service VCI updated successfully',
        'data' => $serviceVCI
    ]);
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
            'upload_image_url' => $item->upload_image ? asset('storage/' . $item->upload_image) : null,
        ];
    });

    return response()->json($response);
}

}