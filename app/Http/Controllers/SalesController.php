<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Inventory;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;

class SalesController extends Controller
{
    public function index()
    {
        $sales = Sale::with([
            'customer:id,customer,email,mobile_no',
            'items:id,sale_id,serial_no,quantity,product_id',
            'items.inventory:id,serial_no,tested_status',
            'items.product:id,name'
        ])->get();

        return response()->json($sales->map(function ($sale) {
            return [
                'id'            => $sale->id,
                'customer'      => $sale->customer,
                'challan_no'    => $sale->challan_no,
                'challan_date'  => $sale->challan_date,
                'shipment_date' => $sale->shipment_date,
                'shipment_name' => $sale->shipment_name,
                'notes'         => $sale->notes,
                'created_at'    => $sale->created_at,
                'updated_at'    => $sale->updated_at,
                'items'         => $sale->items->map(function ($item) {
                    return [
                        'id'        => $item->id,
                        'quantity'  => $item->quantity,
                        'product_id' => $item->product_id,
                        'serial_no' => $item->serial_no,
                        'inventory' => $item->inventory ? [
                            'serial_no'     => $item->inventory->serial_no,
                            'tested_status' => $item->inventory->tested_status,
                        ] : null,
                        'product'   => $item->product ? $item->product->name : null,
                    ];
                }),
                'unique_products' => $sale->items
                ->map(fn($item) => $item->product ? $item->product->name : null)
                ->filter()
                ->unique()
                ->values(),
            ];
        }));
    }

    public function customers()
    {
        return response()->json(Customer::all());
    }

    public function addedSerials()
    {
        $serials = SaleItem::pluck('serial_no')->map(fn($s) => trim($s));

        return response()->json($serials);
    }

    public function getTestingData(Request $request)
    {
        $query = Inventory::with(['product', 'tester'])
            ->whereRaw('LOWER(TRIM(tested_status)) = ?', ['pass'])
            ->whereNotIn('serial_no', function ($q) {
                $q->select('serial_no')->from('sale_items');
            });

        if ($request->filled('product_id')) {
            $query->where('inventories.product_id', $request->product_id);
        }

        if ($request->filled('serial_from')) {
            $query->where('inventories.serial_no', '>=', trim($request->serial_from));
        }

        if ($request->filled('serial_to')) {
            $query->where('inventories.serial_no', '<=', trim($request->serial_to));
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $request->validate([
            'customer_id'   => 'required|integer|exists:customers,id',
            'challan_no'    => 'required|string|unique:sales,challan_no',
            'challan_date'  => 'required|date',
            'shipment_date' => 'required|date',
            'shipment_name' => 'nullable|string',
            'notes'         => 'nullable|string',
            'items'         => 'required|array|min:1',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.serial_no' => 'required|string',
        ]);

        return DB::transaction(function () use ($request) {
            $sale = Sale::create($request->only([
                'customer_id', 'challan_no', 'challan_date',
                'shipment_date', 'shipment_name', 'notes'
            ]));

            $addedSerials = [];

            foreach ($request->items as $itemData) {
                $serial = trim($itemData['serial_no']);

                if (in_array($serial, $addedSerials)) continue;
                // $serial = trim($itemData['serial_no']);
                $inventory = Inventory::whereRaw('LOWER(TRIM(serial_no)) = ?', [strtolower($serial)])
                    ->whereRaw('LOWER(TRIM(tested_status)) = ?', ['pass'])
                    ->whereNotIn('serial_no', function ($q) {
                        $q->select('serial_no')->from('sale_items');
                    })
                    ->first();

                if (!$inventory) {
                    throw new \Exception("Serial {$serial} is not PASS, not found, or already assigned");
                }

                $sale->items()->create([
                    'quantity'  => $itemData['quantity'],
                    'serial_no' => $serial,
                    'product_id' => $inventory->product_id ?? null,
                ]);

                $addedSerials[] = $serial;
            }

            return response()->json($sale->load('items.inventory'), 201);
        }, 5);
    }

    public function show($id)
    {
        $sale = Sale::with([
            'customer:id,customer,email,mobile_no',
            'items:id,sale_id,serial_no,quantity,product_id',
            'items.inventory:id,serial_no,tested_status',
            'items.product:id,name'
        ])->find($id);

        if (!$sale) {
            return response()->json(['message' => 'Sale not found'], 404);
        }

        return response()->json([
            'id'            => $sale->id,
            'customer'      => $sale->customer,
            'challan_no'    => $sale->challan_no,
            'challan_date'  => $sale->challan_date,
            'shipment_date' => $sale->shipment_date,
            'shipment_name' => $sale->shipment_name,
            'notes'         => $sale->notes,
            'created_at'    => $sale->created_at,
            'updated_at'    => $sale->updated_at,
            'items'         => $sale->items->map(function ($item) {
                return [
                    'id'        => $item->id,
                    'quantity'  => $item->quantity,
                    'product_id' => $item->product_id,
                    'serial_no' => $item->serial_no,
                    'inventory' => $item->inventory ? [
                        'serial_no'     => $item->inventory->serial_no,
                        'tested_status' => $item->inventory->tested_status,
                    ] : null,
                    'product'   => $item->product ? $item->product->name : null,
                ];
            }),
            'unique_products' => $sale->items
            ->map(fn($item) => $item->product ? $item->product->name : null)
            ->filter()
            ->unique()
            ->values(),
        ]);
    }

    public function update(Request $request, $id)
    {
        $sale = Sale::find($id);
        if (!$sale) {
            return response()->json(['message' => 'Sale not found'], 404);
        }

        $request->validate([
            'customer_id'   => 'sometimes|integer|exists:customers,id',
            'challan_no'    => 'sometimes|string|unique:sales,challan_no,' . $id,
            'challan_date'  => 'sometimes|date',
            'shipment_date' => 'sometimes|date',
            'shipment_name' => 'nullable|string',
            'notes'         => 'nullable|string',
            'items'         => 'array',
            'items.*.id'    => 'nullable|integer',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.serial_no' => 'required|string',
        ]);

        return DB::transaction(function () use ($request, $sale) {
            $sale->update($request->only([
                'customer_id', 'challan_no', 'challan_date',
                'shipment_date', 'shipment_name', 'notes'
            ]));

            if ($request->has('items')) {
                $existingIds = collect($request->items)->pluck('id')->filter();
                $sale->items()->whereNotIn('id', $existingIds)->delete();

                $addedSerials = $sale->items()->pluck('serial_no')->map(fn($s) => trim($s))->toArray();

                foreach ($request->items as $itemData) {
                    $serial = trim($itemData['serial_no']);

                    if (in_array($serial, $addedSerials)) continue;

                    $inventory = Inventory::where('serial_no', $serial)
                        ->whereRaw('LOWER(TRIM(tested_status)) = ?', ['pass'])
                        ->whereNotIn('serial_no', function ($q) use ($sale) {
                            $q->select('serial_no')
                                ->from('sale_items')
                                ->where('sale_id', '!=', $sale->id);
                        })
                        ->first();

                    if (!$inventory) {
                        throw new \Exception("Serial {$serial} is not PASS, not found, or already assigned");
                    }

                    if (!empty($itemData['id'])) {
                        $item = SaleItem::find($itemData['id']);
                        if ($item) {
                            $item->update([
                                'quantity'  => $itemData['quantity'],
                                'serial_no' => $serial,
                                'product_id' => $inventory->product_id ?? null,
                            ]);
                        }
                    } else {
                        $sale->items()->create([
                            'quantity'  => $itemData['quantity'],
                            'serial_no' => $serial,
                            'product_id' => $inventory->product_id ?? null,
                        ]);
                    }

                    $addedSerials[] = $serial;
                }
            }

            return response()->json($sale->load('items.inventory'));
        }, 5);
    }

    public function destroy($id)
    {
        $sale = Sale::find($id);
        if (!$sale) {
            return response()->json(['message' => 'Sale not found'], 404);
        }

        $sale->items()->delete();
        $sale->delete();

        return response()->json(['message' => 'Sale and its items deleted successfully']);
    }

    public function getSaleSerials($productId)
    {
        $serials = \DB::table('sale_items')
            ->where('product_id', $productId)
            ->select('id', 'serial_no')
            ->get();

        return response()->json($serials);
    }

    public function getProductSerials($productId)
    {
        $serials = \DB::table('inventory')
        ->where('product_id', $productId)
        ->select ('id', 'serial_no','product_id','tested_status')
        ->get();
        return response()->json($serials);
    }


}