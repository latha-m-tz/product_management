<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\SparepartPurchaseItem;
use App\Models\Inventory;
use App\Models\Sale;
use App\Models\VCIServiceItems;

class TrackingTimelineController extends Controller
{
    public function show($serial_number)
    {
        // --- Spare Parts ---
        $spareParts = SparepartPurchaseItem::with(['product', 'productType', 'purchase'])
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

        // --- Inventory ---
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

        // --- VCI Service ---
        $serviceVCI = VCIServiceItems::with(['product', 'productType', 'serviceVCI'])
    ->where('vci_serial_no', $serial_number)
    ->get()
    ->map(function ($item) {
        return [
            'id'            => $item->id,
            'challan_no'    => $item->serviceVCI?->challan_no,
            'challan_date'  => $item->serviceVCI?->challan_date,
            'courier_name'        => $item->serviceVCI?->courier_name,
            'from_place'    => $item->serviceVCI?->from_place,
            'to_place'      => $item->serviceVCI?->to_place,
            'product_name'  => $item->product?->name,
            'vendor_name'   => $item->vendor?->vendor,
            'created_at'    => $item->created_at,
            'updated_at'    => $item->updated_at,
        ];
    });
        // --- Sales ---
        $sales = Sale::with([
            'customer:id,customer,email,mobile_no',
            'items:id,sale_id,serial_no,quantity,product_id',
            'items.inventory:id,serial_no,tested_status,product_id',
            'items.product:id,name'
        ])->whereHas('items', function ($query) use ($serial_number) {
            $query->where('serial_no', $serial_number);
        })->get();

        $saleDetails = $sales->map(function ($sale) use ($serial_number) {
            // Filter items by serial number
            $filteredItems = $sale->items->where('serial_no', $serial_number)->map(function ($item) {
                return [
                    'id' => $item->id,
                    'serial_no' => $item->serial_no,
                    'quantity' => $item->quantity,
                    'product' => $item->product?->name,
                    'inventory' => $item->inventory ? [
                        'serial_no' => $item->inventory->serial_no,
                        'tested_status' => $item->inventory->tested_status,
                    ] : null,
                ];
            });

            return [
                'id' => $sale->id,
                'customer' => $sale->customer,
                'challan_no' => $sale->challan_no,
                'challan_date' => $sale->challan_date,
                'shipment_date' => $sale->shipment_date,
                'shipment_name' => $sale->shipment_name,
                'notes' => $sale->notes,
                'created_at' => $sale->created_at,
                'updated_at' => $sale->updated_at,
                'items' => $filteredItems->values(), // reindex
                'unique_products' => $filteredItems
                    ->map(fn($item) => $item['product'])
                    ->filter()
                    ->unique()
                    ->values(),
            ];
        });

        return response()->json([
            'spare_parts' => $spareParts,
            'inventory' => $inventory,
            'service_vci' => $serviceVCI,
            'sale' => $saleDetails->values(), // reindex collection
        ]);
    }
}
