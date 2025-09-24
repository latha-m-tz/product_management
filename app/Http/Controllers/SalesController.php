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
            'items' => function ($query) {
                $query->select('id', 'sale_id', 'testing_id', 'quantity');
            },
            'items.testing' => function ($query) {
                $query->select('id', 'serial_no', 'tested_status');
            }
        ])->get();

        return response()->json($sales);
    }

    public function customers()
    {
        return response()->json(Customer::all());
    }

    public function getTestingData(Request $request)
    {
        $query = Inventory::whereRaw('LOWER(TRIM(tested_status)) = ?', ['pass'])
            ->whereNotIn('id', function ($q) {
                $q->select('testing_id')->from('sale_items');
            });

        if ($request->filled('serial_from') && $request->filled('serial_to')) {
            $query->whereBetween('serial_no', [$request->serial_from, $request->serial_to]);
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $request->validate([
            'customer_id'   => 'required|integer',
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

                if (in_array($serial, $addedSerials)) {
                    continue;
                }

                $testing = Inventory::where('serial_no', $serial)
                                    ->whereRaw('LOWER(TRIM(tested_status)) = ?', ['pass'])
                                    ->whereNotIn('id', function ($q) {
                                        $q->select('testing_id')->from('sale_items');
                                    })
                                    ->first();

                if (!$testing) {
                    throw new \Exception("Serial {$serial} is not PASS, not found, or already assigned");
                }

                $sale->items()->create([
                    'quantity'   => $itemData['quantity'],
                    'testing_id' => $testing->id,
                ]);

                $addedSerials[] = $serial;
            }

            return response()->json($sale->load('items.testing'), 201);
        }, 5); 
    }

    public function show($id)
    {
        $sale = Sale::with([
            'customer:id,customer,email,mobile_no',
            'items' => function ($query) {
                $query->select('id', 'sale_id', 'testing_id', 'quantity');
            },
            'items.testing' => function ($query) {
                $query->select('id', 'serial_no', 'tested_status');
            }
        ])->find($id);

        if (!$sale) {
            return response()->json(['message' => 'Sale not found'], 404);
        }

        return response()->json($sale);
    }

    public function update(Request $request, $id)
    {
        $sale = Sale::find($id);
        if (!$sale) {
            return response()->json(['message' => 'Sale not found'], 404);
        }

        $request->validate([
            'customer_id'   => 'sometimes|integer',
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

                $addedSerials = $sale->items()
                    ->with('testing')
                    ->get()
                    ->pluck('testing.serial_no')
                    ->map(fn($s) => trim($s))
                    ->toArray();

                foreach ($request->items as $itemData) {
                    $serial = trim($itemData['serial_no']);

                    if (in_array($serial, $addedSerials)) {
                        continue;
                    }

                    $testing = Inventory::where('serial_no', $serial)
                                        ->whereRaw('LOWER(TRIM(tested_status)) = ?', ['pass'])
                                        ->whereNotIn('id', function ($q) use ($sale) {
                                            $q->select('testing_id')
                                              ->from('sale_items')
                                              ->where('sale_id', '!=', $sale->id);
                                        })
                                        ->first();

                    if (!$testing) {
                        throw new \Exception("Serial {$serial} is not PASS, not found, or already assigned");
                    }

                    if (!empty($itemData['id'])) {
                        $item = SaleItem::find($itemData['id']);
                        if ($item) {
                            $item->update([
                                'quantity'   => $itemData['quantity'],
                                'testing_id' => $testing->id,
                            ]);
                        }
                    } else {
                        $sale->items()->create([
                            'quantity'   => $itemData['quantity'],
                            'testing_id' => $testing->id,
                        ]);
                    }

                    $addedSerials[] = $serial;
                }
            }

            return response()->json($sale->load('items.testing'));
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
}