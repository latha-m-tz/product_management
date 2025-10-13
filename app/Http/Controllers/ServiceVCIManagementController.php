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
            'items' => 'required|array|min:1',
            'items.*.vci_serial_no' => 'required|string|max:50',
            'items.*.tested_date' => 'nullable|date',
            'items.*.issue_found' => 'nullable|string|max:100',
            'items.*.action_taken' => 'nullable|string',
            'items.*.remarks' => 'nullable|string',
            'items.*.testing_assigned_to' => 'nullable|string|max:255',
            'items.*.testing_status' => 'nullable|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $serviceVCI = VCIService::create([
            'challan_no'    => $request->challan_no,
            'challan_date'  => $request->challan_date,
            'courier_name'  => $request->courier_name,
            'hsn_code'      => $request->hsn_code,
            'quantity'      => $request->quantity,
            'status'        => $request->status ?? 'active',
            'sent_date'     => $request->sent_date,
            'received_date' => $request->received_date,
            'from_place'    => $request->from_place,
            'to_place'      => $request->to_place,
        ]);

        foreach ($request->items as $itemData) {
            $serviceVCI->items()->create([
                'vci_serial_no' => $itemData['vci_serial_no'],
                'tested_date' => $itemData['tested_date'] ?? null,
                'issue_found' => $itemData['issue_found'] ?? null,
                'action_taken' => $itemData['action_taken'] ?? null,
                'remarks' => $itemData['remarks'] ?? null,
                'testing_assigned_to' => $itemData['testing_assigned_to'] ?? null,
                'testing_status' => $itemData['testing_status'] ?? 'pending',
            ]);
        }
        
        $serviceVCI->load(['items.product', 'items.vendor', 'items.serviceVCI']);
        $response=$serviceVCI->toArray();
        $response['items']=$serviceVCI->items->map(function ($item){
            return [ 'id'           => $item->id,
            'vci_serial_no'=> $item->vci_serial_no,
            'product_name' => $item->product?->name,
            'vendor_name'  => $item->vendor?->vendor,
            'challan_no'   => $item->serviceVCI?->challan_no,
            'challan_date' => $item->serviceVCI?->challan_date,
            'status'       => $item->serviceVCI?->status === 'active' ? 'Active' : 'Inactive',
            'tested_date'  => $item->tested_date,
            'testing_status' => $item->testing_status,
        ];
    });

    return response()->json($response, 201);
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

        $validator = Validator::make($request->all(), [
            'challan_no' => 'sometimes|string|max:50',
            'challan_date' => 'sometimes|date',
            'courier_name' => 'nullable|string|max:100',
            'hsn_code' => 'nullable|string|max:20',
            'quantity' => 'integer|min:1',
            'status' => 'nullable|string|max:20',
            'sent_date' => 'nullable|date',
            'received_date' => 'nullable|date',
            'from_place' => 'nullable|string|max:100',
            'to_place' => 'nullable|string|max:100',
            'items' => 'sometimes|array',
            'items.*.id' => 'nullable|integer|exists:service_vci_items,id',
            'items.*.vci_serial_no' => 'required|string|max:50',
            'items.*.tested_date' => 'nullable|date',
            'items.*.issue_found' => 'nullable|string|max:100',
            'items.*.action_taken' => 'nullable|string',
            'items.*.remarks' => 'nullable|string',
            'items.*.testing_assigned_to' => 'nullable|string|max:255',
            'items.*.testing_status' => 'nullable|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $serviceVCI->update([
            'challan_no'    => $request->challan_no ?? $serviceVCI->challan_no,
            'challan_date'  => $request->challan_date ?? $serviceVCI->challan_date,
            'courier_name'  => $request->courier_name ?? $serviceVCI->courier_name,
            'hsn_code'      => $request->hsn_code ?? $serviceVCI->hsn_code,
            'quantity'      => $request->quantity ?? $serviceVCI->quantity,
            'status'        => $request->status ?? $serviceVCI->status ?? 'active', // default
            'sent_date'     => $request->sent_date ?? $serviceVCI->sent_date,
            'received_date' => $request->received_date ?? $serviceVCI->received_date,
            'from_place'    => $request->from_place ?? $serviceVCI->from_place,
            'to_place'      => $request->to_place ?? $serviceVCI->to_place,
        ]);

         if ($request->has('items')) {
        foreach ($request->items as $itemData) {
            if (!empty($itemData['id'])) {
                // Update existing item
                $item = VCIServiceItems::find($itemData['id']);
                if ($item && $item->service_vci_id == $serviceVCI->id) {
                    $item->update([
                        'vci_serial_no' => $itemData['vci_serial_no'],
                        'tested_date' => $itemData['tested_date'] ?? null,
                        'issue_found' => $itemData['issue_found'] ?? null,
                        'action_taken' => $itemData['action_taken'] ?? null,
                        'remarks' => $itemData['remarks'] ?? null,
                        'testing_assigned_to' => $itemData['testing_assigned_to'] ?? null,
                        'testing_status' => $itemData['testing_status'] ?? 'pending',
                    ]);
                }
            } else {
                // Create new item
                $serviceVCI->items()->create([
                    'vci_serial_no' => $itemData['vci_serial_no'],
                    'tested_date' => $itemData['tested_date'] ?? null,
                    'issue_found' => $itemData['issue_found'] ?? null,
                    'action_taken' => $itemData['action_taken'] ?? null,
                    'remarks' => $itemData['remarks'] ?? null,
                    'testing_assigned_to' => $itemData['testing_assigned_to'] ?? null,
                    'testing_status' => $itemData['testing_status'] ?? 'pending',
                ]);
            }
        }
    }

        $serviceVCI->load(['items.product', 'items.vendor', 'items.serviceVCI']);

    $response = $serviceVCI->toArray();
    $response['items'] = $serviceVCI->items->map(function ($item) {
        return [
            'id'           => $item->id,
            'vci_serial_no'=> $item->vci_serial_no,
            'product_name' => $item->product?->name,
            'vendor_name'  => $item->vendor?->vendor,
            'challan_no'   => $item->serviceVCI?->challan_no,
            'challan_date' => $item->serviceVCI?->challan_date,
            'status'       => $item->serviceVCI?->status === 'active' ? 'Active' : 'Inactive',
            'tested_date'  => $item->tested_date,
            'testing_status' => $item->testing_status,
        ];
    });

    return response()->json($response);
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
}