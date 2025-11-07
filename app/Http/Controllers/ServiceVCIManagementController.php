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

        'receipt_files.*' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:2048',

        // Items validation
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

    // ✅ Handle multiple file uploads
    // $challanFiles = [];
    // if ($request->hasFile('challan_files')) {
    //     foreach ($request->file('challan_files') as $file) {
    //         $challanFiles[] = $file->store('uploads/service_vci/challans', 'public');
    //     }
    // }

    $receiptFiles = [];
    if ($request->hasFile('receipt_files')) {
        foreach ($request->file('receipt_files') as $file) {
            $receiptFiles[] = $file->store('uploads/service_vci/receipts', 'public');
        }
    }

    // ✅ Create main VCI service record
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
        'tracking_number' => $request->tracking_number,
        // 'challan_files' => $challanFiles,
        'receipt_files' => $receiptFiles,
    ]);

    // ✅ Handle service items
    foreach ($request->items as $item) {
        $uploadPath = null;
        if (isset($item['upload_image']) && $item['upload_image'] instanceof \Illuminate\Http\UploadedFile) {
            $uploadPath = $item['upload_image']->store('uploads/service_vci/items', 'public');
        }

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

    $serviceVCI->load(['items.product', 'items.vendor', 'items.serviceVCI']);

    return response()->json($serviceVCI, 201);
}

        
public function show($id)
{
    $serviceVCI = VCIService::with([
        'items',
        'items.product',
    ])->find($id);

    if (!$serviceVCI) {
        return response()->json(['message' => 'Service VCI not found'], 404);
    }

    // ✅ Convert challan files to full URLs
    $challanFiles = [];
    if (!empty($serviceVCI->challan_files) && is_array($serviceVCI->challan_files)) {
        foreach ($serviceVCI->challan_files as $file) {
            $challanFiles[] = asset('storage/' . $file);
        }
    }

    // ✅ Convert receipt files to full URLs
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

    // ✅ Add new URL arrays for frontend
    $serviceVCI->challan_files_urls = $challanFiles;
    $serviceVCI->receipt_files_urls = $receiptFiles;

    return response()->json($serviceVCI, 200);
}

public function update(Request $request, $id)
{
    $serviceVCI = VCIService::findOrFail($id);

    // 🔹 Validate incoming request
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

        // File validations
        // 'challan_files.*' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:2048',
        'receipt_files.*' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:2048',

        // Items validation
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

    // ✅ Get current files from DB
    // $challanFiles = $serviceVCI->challan_files ?? [];
    $receiptFiles = $serviceVCI->receipt_files ?? [];

    // ✅ Handle removed challan files
    // if ($request->filled('removed_challan_files')) {
    //     $removedChallanFiles = json_decode($request->removed_challan_files, true);
    //     foreach ($removedChallanFiles as $file) {
    //         if (Storage::disk('public')->exists($file)) {
    //             Storage::disk('public')->delete($file);
    //         }
    //     }
    //     $challanFiles = array_values(array_diff($challanFiles, $removedChallanFiles));
    // }

    // ✅ Handle removed receipt files
    if ($request->filled('removed_receipt_files')) {
        $removedReceiptFiles = json_decode($request->removed_receipt_files, true);
        foreach ($removedReceiptFiles as $file) {
            if (Storage::disk('public')->exists($file)) {
                Storage::disk('public')->delete($file);
            }
        }
        $receiptFiles = array_values(array_diff($receiptFiles, $removedReceiptFiles));
    }

    // if ($request->hasFile('challan_files')) {
    //     foreach ($challanFiles as $oldFile) {
    //         if (Storage::disk('public')->exists($oldFile)) {
    //             Storage::disk('public')->delete($oldFile);
    //         }
    //     }
    //     $challanFiles = [];
    //     foreach ($request->file('challan_files') as $file) {
    //         $challanFiles[] = $file->store('uploads/service_vci/challans', 'public');
    //     }
    // }

    if ($request->hasFile('receipt_files')) {
        foreach ($receiptFiles as $oldFile) {
            if (Storage::disk('public')->exists($oldFile)) {
                Storage::disk('public')->delete($oldFile);
            }
        }
        $receiptFiles = [];
        foreach ($request->file('receipt_files') as $file) {
            $receiptFiles[] = $file->store('uploads/service_vci/receipts', 'public');
        }
    }

    // ✅ Update main ServiceVCI record
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
        // 'challan_files' => $challanFiles,
        'receipt_files' => $receiptFiles,
    ]);

    // ✅ Handle deleted items
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

    // ✅ Update or create items
    foreach ($request->items as $item) {
        $uploadPath = $item['upload_image'] ?? null;

        if (isset($item['upload_image']) && $item['upload_image'] instanceof \Illuminate\Http\UploadedFile) {
            $uploadPath = $item['upload_image']->store('uploads/service_vci/items', 'public');
        } else {
            $uploadPath = $this->normalizeImagePath($item['upload_image'] ?? null);
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

    // ✅ Reload updated relationships
    $serviceVCI->load(['items.product', 'items.vendor', 'items.serviceVCI']);
    
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