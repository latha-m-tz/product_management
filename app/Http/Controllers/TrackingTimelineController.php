<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\sparepartPurchaseItem;
use App\Models\Inventory;
use App\Models\Sale;
use App\Models\VCIServiceItems;

class TrackingTimelineController extends Controller
{
    public function show($serial_number)
    {

        $spareParts = SparepartPurchaseItem::with([
        'product',
        'productType',
        'purchase' 
    ])
    ->leftJoin('sparepart_purchase as sp', 'sparepart_purchase_items.purchase_id', '=', 'sp.id')
    ->leftJoin('vendors as v', 'sp.vendor_id', '=', 'v.id')
    ->where('sparepart_purchase_items.serial_no', $serial_number)
    ->select(
        'sparepart_purchase_items.*',
        'v.vendor as vendor_name',
        'sp.challan_no',
        'sp.challan_date'
    )
    ->get()
    ->map(function ($item) {
        return [
            'id' => $item->id,
            'serial_no' => $item->serial_no,
            'quantity' => $item->quantity,
            'warranty_status' => $item->warranty_status,
            'product_name' => $item->product?->name,
            'product_type' => $item->productType?->name,
            'challan_no' => $item->challan_no,
            'challan_date' => $item->challan_date,
            'vendor_name' => $item->vendor_name, 
            'created_at' => $item->created_at,
            'updated_at' => $item->updated_at,
        ];
    });

        // Inventory
        $inventory = Inventory::with(['product', 'productType'])
            ->where('serial_no', $serial_number)
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'serial_no' => $item->serial_no,
                    'firmware_version' => $item->firmware_version,
                    'tested_status' => $item->tested_status,
                    'product_name' => $item->product?->name,
                    'product_type' => $item->productType?->name,
                    'created_at' => $item->created_at,
                    'updated_at' => $item->updated_at,
                ];
            });

        // VCI Service
        $serviceVCI = VCIServiceItems::with(['product', 'productType'])
            ->where('vci_serial_no', $serial_number)
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'challan_no' => $item->challan_no,
                    'challan_date' => $item->challan_date,
                    'status' => $item->status,
                    'product_name' => $item->product?->name,
                    'vendor_name' => $item->vendor?->name,
                    'created_at' => $item->created_at,
                    'updated_at' => $item->updated_at,
                ];
            });

        return response()->json([
            'spare_parts' => $spareParts,
            'inventory' => $inventory,
            'service_vci' => $serviceVCI,
        ]);
    }



}
