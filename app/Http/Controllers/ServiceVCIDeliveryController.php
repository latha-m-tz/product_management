<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ServiceVCIDelivery;
use App\Models\VCIServiceItems;
use Validator;

class ServiceVCIDeliveryController extends Controller
{
    public function index()
    {
        $deliveries = ServiceVCIDelivery::with(['serviceVCI', 'serviceItem'])->get();
        return response()->json($deliveries);
    }

    public function eligibleItems()
    {
        // ✅ Only items eligible for delivery
        $items = VCIServiceItems::where('testing_status', 'pass')
            ->where('urgent', false)
            ->whereDoesntHave('delivery') // avoid already delivered items
            ->with(['serviceVCI', 'product'])
            ->get();

        return response()->json($items);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'service_vci_id' => 'required|integer|exists:service_vci,id',
            'service_vci_item_ids' => 'required|array|min:1',
            'service_vci_item_ids.*' => 'integer|exists:service_vci_items,id',

            'delivery_challan_no' => 'required|string|max:50',
            'delivery_date' => 'required|date',
            'courier_name' => 'nullable|string|max:100',
            'tracking_number' => 'nullable|string|max:100',
            'delivered_to' => 'nullable|string|max:100',

            // ✅ Multiple file validations
            'challan_files.*' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:2048',
            'receipt_files.*' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // ✅ Handle multiple challan uploads
        $challanFiles = [];
        if ($request->hasFile('challan_files')) {
            foreach ($request->file('challan_files') as $file) {
                $challanFiles[] = $file->store('uploads/service_delivery/challans', 'public');
            }
        }

        // ✅ Handle multiple receipt uploads
        $receiptFiles = [];
        if ($request->hasFile('receipt_files')) {
            foreach ($request->file('receipt_files') as $file) {
                $receiptFiles[] = $file->store('uploads/service_delivery/receipts', 'public');
            }
        }

        $createdDeliveries = [];

        // ✅ Loop through all item IDs
        foreach ($request->service_vci_item_ids as $itemId) {
            $item = VCIServiceItems::find($itemId);

            if (!$item || $item->testing_status !== 'pass' || $item->urgent) {
                continue; // skip invalid/urgent items
            }

            $delivery = ServiceVCIDelivery::create([
                'service_vci_id' => $request->service_vci_id,
                'service_vci_item_id' => $itemId,
                'delivery_challan_no' => $request->delivery_challan_no,
                'delivery_date' => $request->delivery_date,
                'courier_name' => $request->courier_name,
                'tracking_number' => $request->tracking_number,
                'delivered_to' => $request->delivered_to,
                'challan_files' => $challanFiles,
                'receipt_files' => $receiptFiles,
                'status' => 'delivered',
            ]);

            $createdDeliveries[] = $delivery;
        }

        return response()->json([
            'message' => 'Deliveries created successfully',
            'data' => $createdDeliveries,
        ], 201);
    }

public function show($id)
{
    // Fetch one delivery record (first one for the given delivery group)
    $delivery = ServiceVCIDelivery::with(['serviceVciItem.product'])
        ->where('id', $id)
        ->first();

    if (!$delivery) {
        return response()->json(['message' => 'Service delivery not found'], 404);
    }

    // Since your store() creates one record per service_vci_item_id,
    // we can group all items that share the same challan number and service_vci_id.
    $deliveries = ServiceVCIDelivery::with(['serviceVciItem.product'])
        ->where('service_vci_id', $delivery->service_vci_id)
        ->where('delivery_challan_no', $delivery->delivery_challan_no)
        ->get();

    // Collect items info
    $items = $deliveries->map(function ($d) {
        return [
            'id' => $d->service_vci_item_id,
            'product_id' => optional($d->serviceVciItem)->product_id,
            'vci_serial_no' => optional($d->serviceVciItem)->vci_serial_no,
            'service_vci_id' => $d->service_vci_id,
            'service_vci_item_id' => $d->service_vci_item_id,
        ];
    });

    return response()->json([
        'id' => $delivery->id,
        'service_vci_id' => $delivery->service_vci_id,
        'delivery_challan_no' => $delivery->delivery_challan_no,
        'delivery_date' => $delivery->delivery_date,
        'courier_name' => $delivery->courier_name,
        'tracking_number' => $delivery->tracking_number,
        'delivered_to' => $delivery->delivered_to,
        'challan_files' => $delivery->challan_files,
        'receipt_files' => $delivery->receipt_files,
        'status' => $delivery->status,
        'items' => $items,
    ]);
}


    public function update(Request $request, $id)
    {
        $delivery = ServiceVCIDelivery::findOrFail($id);

        $delivery->update($request->only([
            'delivery_challan_no',
            'delivery_date',
            'courier_name',
            'tracking_number',
            'delivered_to',
            'status'
        ]));

        return response()->json($delivery);
    }

    public function destroy($id)
    {
        $delivery = ServiceVCIDelivery::findOrFail($id);
        $delivery->delete();

        return response()->json(['message' => 'Delivery record deleted successfully.']);
    }
}
