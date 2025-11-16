<?php

namespace App\Http\Controllers;

use App\Models\VCIService;
use App\Models\VCIServiceItems;
use App\Models\Product;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth;

class ServiceVCIManagementController extends Controller
{
    /**
     * List all VCI services (non-deleted)
     */
    public function index()
    {
        $services = VCIService::with(['vendor', 'items.product'])
            ->whereNull('deleted_at')
            ->orderBy('id', 'desc')
            ->get();

        $data = $services->map(function ($service) {
            return [
                'id' => $service->id,
                'vendor_id' => $service->vendor->id ?? null,
                'vendor_name' => $service->vendor->vendor ?? null,
                'challan_no' => $service->challan_no,
                'challan_date' => $service->challan_date,
                'tracking_no' => $service->tracking_no,
                'status' => $service->status,
                'created_at' => optional($service->created_at)->format('Y-m-d H:i:s'),
                'updated_at' => optional($service->updated_at)->format('Y-m-d H:i:s'),
                'items' => $service->items->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'vci_serial_no' => $item->vci_serial_no,
                        'status' => $item->status === 'delivery' ? 'Delivered' : $item->status,
                        'created_at' => optional($item->created_at)->format('Y-m-d H:i:s'),
                        'upload_image' => $item->upload_image ? asset('storage/' . $item->upload_image) : null,
                        'product' => $item->product->name ?? null,
                    ];
                }),
            ];
        });

        return response()->json($data, 200);
    }

    /**
     * Create a new VCI service with items and optional receipt files
     */
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
                function ($attribute, $value, $fail) {
                    $exists = VCIService::where('challan_no', $value)
                        ->whereNull('deleted_at')
                        ->exists();
                    if ($exists) {
                        $fail('Challan No has already been taken.');
                    }
                }
            ],
            'challan_date' => 'required|date',
            'tracking_no' => 'nullable|string|max:50',
            'receipt_files.*' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:2048',
            'items' => 'required|array|min:1',
            'items.*.product_id' => [
                'required',
                'integer',
                function ($attribute, $value, $fail) {
                    $exists = Product::where('id', $value)
                        ->whereNull('deleted_at')
                        ->exists();
                    if (!$exists) {
                        $fail('Selected product not found.');
                    }
                }
            ],
            'items.*.vci_serial_no' => 'required|string|max:50',
            'items.*.status' => 'nullable|string|max:50',
            'items.*.remarks' => 'nullable|string|max:255',
            'items.*.upload_image' => [
                'nullable',
                function ($attribute, $value, $fail) {
                    if ($value && !($value instanceof \Illuminate\Http\UploadedFile)) {
                        // If it's provided and not an UploadedFile, reject — we expect files on store
                        $fail('The ' . $attribute . ' must be a valid file.');
                    }
                },
                'mimes:jpg,jpeg,png,pdf',
                'max:2048'
            ],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // store receipt files if present
        $receiptFiles = [];
        if ($request->hasFile('receipt_files')) {
            foreach ($request->file('receipt_files') as $file) {
                $receiptFiles[] = $file->store('uploads/service_vci/receipts', 'public');
            }
        }

        $service = VCIService::create([
            'vendor_id' => $request->vendor_id,
            'challan_no' => $request->challan_no,
            'challan_date' => $request->challan_date,
            'tracking_no' => $request->tracking_no ?? null,
            'receipt_files' => $receiptFiles,
            'status' => 'active',
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        // loop items
        foreach ($request->items as $itemData) {
            // avoid duplicate serials within same request for same service
            $existsInService = VCIServiceItems::where('service_vci_id', $service->id)
                ->where('vci_serial_no', $itemData['vci_serial_no'])
                ->whereNull('deleted_at')
                ->exists();

            if ($existsInService) {
                // skip duplicates
                continue;
            }

            $uploadPath = null;
            if (isset($itemData['upload_image']) && $itemData['upload_image'] instanceof \Illuminate\Http\UploadedFile) {
                $uploadPath = $itemData['upload_image']->store('uploads/service_vci/items', 'public');
            }

            VCIServiceItems::create([
                'service_vci_id' => $service->id,
                'product_id' => $itemData['product_id'],
                'vci_serial_no' => $itemData['vci_serial_no'],
                'status' => $itemData['status'] ?? null,
                'remarks' => $itemData['remarks'] ?? null,
                'upload_image' => $uploadPath,
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);
        }

        $service->load(['items.product', 'vendor']);

        // convert receipt_files to URLs for response
        $service->receipt_files_urls = [];
        if (!empty($service->receipt_files) && is_array($service->receipt_files)) {
            foreach ($service->receipt_files as $file) {
                $service->receipt_files_urls[] = asset('storage/' . $file);
            }
        }

        foreach ($service->items as $item) {
            $item->upload_image = $item->upload_image ? asset('storage/' . $item->upload_image) : null;
        }

        return response()->json([
            'message' => 'VCI Service created successfully',
            'data' => $service
        ], 201);
    }

    /**
     * Show single VCI service (non-deleted)
     */
    public function show($id)
    {
        $service = VCIService::with(['vendor', 'items.product'])
            ->whereNull('deleted_at')
            ->find($id);

        if (!$service) {
            return response()->json(['message' => 'Service VCI not found'], 404);
        }

        // prepare file URLs
        $receiptFiles = [];
        if (!empty($service->receipt_files) && is_array($service->receipt_files)) {
            foreach ($service->receipt_files as $file) {
                $receiptFiles[] = asset('storage/' . $file);
            }
        }

        foreach ($service->items as $item) {
            $item->upload_image = $item->upload_image ? asset('storage/' . $item->upload_image) : null;
        }

        $service->receipt_files_urls = $receiptFiles;

        return response()->json([
            'id' => $service->id,
            'vendor_id' => $service->vendor_id,
            'vendor_name' => $service->vendor->vendor ?? null,
            'challan_no' => $service->challan_no,
            'challan_date' => $service->challan_date,
            'tracking_no' => $service->tracking_no,
            'status' => $service->status,
            'receipt_files' => $service->receipt_files,
            'receipt_files_urls' => $service->receipt_files_urls,
            'items' => $service->items,
            'created_at' => $service->created_at,
            'updated_at' => $service->updated_at,
        ], 200);
    }

    /**
     * Update VCI service + items
     */
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
                function ($attribute, $value, $fail) use ($id) {
                    $exists = VCIService::where('challan_no', $value)
                        ->where('id', '!=', $id)
                        ->whereNull('deleted_at')
                        ->exists();
                    if ($exists) $fail('Challan No has already been taken.');
                }
            ],
            'challan_date' => 'required|date',
            'tracking_no' => 'nullable|string|max:50',
            'status' => 'nullable|string|max:50',
            'receipt_files.*' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:2048',
            'items' => 'required|array|min:1',
            'items.*.id' => 'nullable|integer|exists:service_vci_items,id',
            'items.*.product_id' => [
                'required',
                'integer',
                function ($attribute, $value, $fail) {
                    $exists = Product::where('id', $value)
                        ->whereNull('deleted_at')
                        ->exists();
                    if (!$exists) $fail('Selected product not found.');
                }
            ],
            'items.*.vci_serial_no' => 'required|string|max:50',
            'items.*.status' => 'nullable|string|max:50',
            'items.*.remarks' => 'nullable|string|max:255',
            'items.*.upload_image' => 'nullable', // either UploadedFile or existing URL string
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // -------------------
        // Handle receipt files replacement if new provided
        // -------------------
        $receiptFiles = $service->receipt_files ?? [];
        if ($request->hasFile('receipt_files')) {
            // delete old
            if (!empty($receiptFiles) && is_array($receiptFiles)) {
                foreach ($receiptFiles as $file) {
                    if ($file && Storage::disk('public')->exists($file)) {
                        Storage::disk('public')->delete($file);
                    }
                }
            }

            // store new
            $receiptFiles = [];
            foreach ($request->file('receipt_files') as $file) {
                $receiptFiles[] = $file->store('uploads/service_vci/receipts', 'public');
            }
        }

        // -------------------
        // Update main service
        // -------------------
        $service->update([
            'vendor_id' => $request->vendor_id,
            'challan_no' => $request->challan_no,
            'challan_date' => $request->challan_date,
            'tracking_no' => $request->tracking_no ?? $service->tracking_no,
            'status' => $request->status ?? $service->status,
            'receipt_files' => $receiptFiles,
            'updated_by' => Auth::id(),
        ]);

        // -------------------
        // Delete removed items
        // -------------------
        $existingIds = $service->items()->whereNull('deleted_at')->pluck('id')->toArray();
        $incomingIds = collect($request->items)->pluck('id')->filter()->toArray();
        $toDelete = array_diff($existingIds, $incomingIds);

        if (!empty($toDelete)) {
            $toDeleteModels = VCIServiceItems::whereIn('id', $toDelete)->get();
            foreach ($toDeleteModels as $delItem) {
                if ($delItem->upload_image && Storage::disk('public')->exists($delItem->upload_image)) {
                    Storage::disk('public')->delete($delItem->upload_image);
                }
            }
            // soft-delete items (set deleted_at & deleted_by) if model uses SoftDeletes; otherwise hard delete
            VCIServiceItems::whereIn('id', $toDelete)->update([
                'deleted_at' => now(),
                'deleted_by' => Auth::id()
            ]);
        }

        // -------------------
        // Update existing or create new items
        // -------------------
        foreach ($request->items as $item) {
            $uploadPath = null;

            // If a new uploaded file was provided (UploadedFile)
            if (isset($item['upload_image']) && $item['upload_image'] instanceof \Illuminate\Http\UploadedFile) {
                // if replacing existing file for this item, delete old one (handled below when updating existing item)
                $uploadPath = $item['upload_image']->store('uploads/service_vci/items', 'public');
            } elseif (!empty($item['upload_image']) && is_string($item['upload_image'])) {
                // if frontend sent full URL, convert to relative storage path
                $uploadPath = $this->normalizeImagePath($item['upload_image']);
            }

            if (!empty($item['id'])) {
                // update existing
                $existing = VCIServiceItems::whereNull('deleted_at')->find($item['id']);
                if ($existing) {
                    // if a new upload replaced old file, delete old
                    if ($uploadPath && $existing->upload_image && Storage::disk('public')->exists($existing->upload_image)) {
                        Storage::disk('public')->delete($existing->upload_image);
                    }

                    $existing->update([
                        'product_id' => $item['product_id'],
                        'vci_serial_no' => $item['vci_serial_no'],
                        'status' => $item['status'] ?? $existing->status,
                        'remarks' => $item['remarks'] ?? $existing->remarks,
                        'upload_image' => $uploadPath ?? $existing->upload_image,
                        'updated_by' => Auth::id(),
                    ]);
                }
            } else {
                // create new item
                VCIServiceItems::create([
                    'service_vci_id' => $service->id,
                    'product_id' => $item['product_id'],
                    'vci_serial_no' => $item['vci_serial_no'],
                    'status' => $item['status'] ?? null,
                    'remarks' => $item['remarks'] ?? null,
                    'upload_image' => $uploadPath,
                    'created_by' => Auth::id(),
                    'updated_by' => Auth::id(),
                ]);
            }
        }

        // reload relationships
        $service->load(['vendor', 'items.product']);

        // convert receipts & item images to URLs for response
        $service->receipt_files_urls = [];
        if (!empty($service->receipt_files) && is_array($service->receipt_files)) {
            foreach ($service->receipt_files as $file) {
                $service->receipt_files_urls[] = asset('storage/' . $file);
            }
        }

        foreach ($service->items as $item) {
            $item->upload_image = $item->upload_image ? asset('storage/' . $item->upload_image) : null;
        }

        return response()->json($service, 200);
    }

    /**
     * Soft-delete service and its items (set deleted_at & deleted_by)
     */
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

    /**
     * List all VCI serial numbers (deduplicated)
     */
    public function getAllVCISerialNumbers()
    {
        $serialNumbers = VCIServiceItems::whereNull('deleted_at')
            ->pluck('vci_serial_no')
            ->map(fn($s) => trim($s))
            ->unique()
            ->values();

        return response()->json($serialNumbers, 200);
    }

    /**
     * Return service items with optional filters
     */
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

    /**
     * Normalize a full URL or absolute path to storage relative path
     */
    private function normalizeImagePath($path)
    {
        if (!$path) return null;

        // Remove possible full URL parts -> make it a relative storage path
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
}
