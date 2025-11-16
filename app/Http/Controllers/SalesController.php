<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Inventory;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class SalesController extends Controller
{
    // -------------------------------------------------------
    // LIST SALES (Soft delete safe)
    // -------------------------------------------------------
    public function index()
    {
        $sales = Sale::with([
            'customer:id,customer,email,mobile_no',
            'items:id,sale_id,serial_no,quantity,product_id',
            'items.inventory:id,serial_no,tested_status',
            'items.product:id,name'
        ])
        ->whereNull('deleted_at')
        ->orderBy('id', 'desc')
        ->get();

        return response()->json($sales);
    }

    // -------------------------------------------------------
    // LIST CUSTOMERS (Soft delete safe)
    // -------------------------------------------------------
    public function customers()
    {
        return response()->json(
            Customer::whereNull('deleted_at')->get()
        );
    }

    // -------------------------------------------------------
    // SERIALS ALREADY ADDED IN ANY SALE
    // -------------------------------------------------------
    public function addedSerials()
    {
        return response()->json(
            SaleItem::whereNull('deleted_at')->pluck('serial_no')->map('trim')
        );
    }

    // -------------------------------------------------------
    // GET TESTED SERIALS AVAILABLE FOR SALE
    // -------------------------------------------------------
    public function getTestingData(Request $request)
    {
        $query = Inventory::with(['product', 'tester'])
            ->whereRaw('LOWER(TRIM(tested_status)) = ?', ['pass'])
            ->whereNull('deleted_at')
            ->whereNotIn('serial_no', function ($q) {
                $q->select('serial_no')->from('sale_items')->whereNull('deleted_at');
            });

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        if ($request->filled('serial_from')) {
            $query->where('serial_no', '>=', trim($request->serial_from));
        }

        if ($request->filled('serial_to')) {
            $query->where('serial_no', '<=', trim($request->serial_to));
        }

        return response()->json($query->get());
    }

    // -------------------------------------------------------
    // CREATE SALE (SOFT DELETE SAFE)
    // -------------------------------------------------------
    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => [
                'required',
                function ($attribute, $value, $fail) {
                    $exists = Customer::where('id', $value)
                        ->whereNull('deleted_at')
                        ->exists();
                    if (!$exists) {
                        $fail("Customer not found.");
                    }
                },
            ],

            'challan_no' => [
                'required',
                function ($attribute, $value, $fail) {
                    $exists = Sale::where('challan_no', $value)
                        ->whereNull('deleted_at')
                        ->exists();
                    if ($exists) {
                        $fail("Challan No already exists.");
                    }
                }
            ],

            'challan_date'  => 'required|date',
            'shipment_date' => 'required|date',
            'shipment_name' => 'nullable|string',
            'notes'         => 'nullable|string',
            'items'         => 'required|array|min:1',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.serial_no' => 'required|string',
        ]);

        return DB::transaction(function () use ($validated) {

            $sale = Sale::create([
                'customer_id'   => $validated['customer_id'],
                'challan_no'    => $validated['challan_no'],
                'challan_date'  => $validated['challan_date'],
                'shipment_date' => $validated['shipment_date'],
                'shipment_name' => $validated['shipment_name'] ?? null,
                'notes'         => $validated['notes'] ?? null,
                'created_by'    => Auth::id(),
            ]);

            $addedSerials = [];

            foreach ($validated['items'] as $item) {
                $serial = trim($item['serial_no']);

                if (in_array($serial, $addedSerials)) continue;

                $inventory = Inventory::whereRaw('LOWER(TRIM(serial_no)) = ?', [strtolower($serial)])
                    ->whereRaw('LOWER(TRIM(tested_status)) = ?', ['pass'])
                    ->whereNull('deleted_at')
                    ->whereNotIn('serial_no', function ($q) {
                        $q->select('serial_no')->from('sale_items')->whereNull('deleted_at');
                    })
                    ->first();

                if (!$inventory) {
                    throw new \Exception("Serial {$serial} invalid or already assigned.");
                }

                $sale->items()->create([
                    'quantity'  => $item['quantity'],
                    'serial_no' => $serial,
                    'product_id'=> $inventory->product_id,
                    'created_by'=> Auth::id(),
                ]);

                $addedSerials[] = $serial;
            }

            return response()->json($sale->load('items.inventory'), 201);
        });
    }

    // -------------------------------------------------------
    // SHOW SALE (SOFT DELETE SAFE)
    // -------------------------------------------------------
    public function show($id)
    {
        $sale = Sale::with([
            'customer:id,customer,email,mobile_no',
            'items:id,sale_id,serial_no,quantity,product_id',
            'items.inventory:id,serial_no,tested_status',
            'items.product:id,name'
        ])
        ->whereNull('deleted_at')
        ->find($id);

        if (!$sale) {
            return response()->json(['message' => 'Sale not found'], 404);
        }

        return response()->json($sale);
    }

    // -------------------------------------------------------
    // UPDATE SALE (SOFT DELETE SAFE)
    // -------------------------------------------------------
    public function update(Request $request, $id)
    {
        $sale = Sale::whereNull('deleted_at')->find($id);

        if (!$sale) {
            return response()->json(['message' => 'Sale not found'], 404);
        }

        $request->validate([
            'challan_no' => [
                'sometimes',
                function ($attribute, $value, $fail) use ($id) {
                    $exists = Sale::where('challan_no', $value)
                        ->where('id', '!=', $id)
                        ->whereNull('deleted_at')
                        ->exists();
                    if ($exists) {
                        $fail("Challan No already exists.");
                    }
                }
            ],
            'customer_id' => 'sometimes|integer',
            'items'       => 'array',
        ]);

        return DB::transaction(function () use ($request, $sale) {

            $sale->update($request->only([
                'customer_id', 'challan_no', 'challan_date',
                'shipment_date', 'shipment_name', 'notes'
            ]) + ['updated_by' => Auth::id()]);

            if ($request->has('items')) {
                $existing = collect($request->items)->pluck('id')->filter();
                $sale->items()->whereNotIn('id', $existing)->update([
                    'deleted_at' => now(),
                    'deleted_by' => Auth::id()
                ]);

                foreach ($request->items as $item) {
                    $serial = trim($item['serial_no']);

                    $inventory = Inventory::where('serial_no', $serial)
                        ->whereRaw('LOWER(TRIM(tested_status)) = ?', ['pass'])
                        ->whereNull('deleted_at')
                        ->whereNotIn('serial_no', function ($q) use ($sale) {
                            $q->select('serial_no')->from('sale_items')
                                ->where('sale_id', '!=', $sale->id)
                                ->whereNull('deleted_at');
                        })
                        ->first();

                    if (!$inventory) {
                        throw new \Exception("Serial {$serial} invalid or already assigned.");
                    }

                    if (!empty($item['id'])) {
                        SaleItem::find($item['id'])
                        ->update([
                            'quantity'  => $item['quantity'],
                            'serial_no' => $serial,
                            'product_id'=> $inventory->product_id,
                            'updated_by'=> Auth::id()
                        ]);
                    } else {
                        $sale->items()->create([
                            'quantity'  => $item['quantity'],
                            'serial_no' => $serial,
                            'product_id'=> $inventory->product_id,
                            'created_by'=> Auth::id(),
                        ]);
                    }
                }
            }

            return response()->json($sale->load('items.inventory'));
        });
    }

    // -------------------------------------------------------
    // SOFT DELETE SALE (WITH deleted_by)
    // -------------------------------------------------------
    public function destroy($id)
    {
        $sale = Sale::whereNull('deleted_at')->find($id);

        if (!$sale) {
            return response()->json(['message' => 'Sale not found'], 404);
        }

        $sale->items()->update([
            'deleted_at' => now(),
            'deleted_by' => Auth::id()
        ]);

        $sale->update([
            'deleted_at' => now(),
            'deleted_by' => Auth::id()
        ]);

        return response()->json(['message' => 'Sale soft deleted successfully']);
    }
public function getSaleSerials($productId)
{
    $serials = \DB::table('sale_items')
        ->where('product_id', $productId)
        ->whereNull('deleted_at')   // ✅ Only active (not deleted)
        ->select('id', 'serial_no')
        ->get();

    return response()->json($serials);
}
    // -------------------------------------------------------
    // PRODUCT SERIALS FOR SALE
    // -------------------------------------------------------
    public function getProductSerials($productId)
    {
        return response()->json(
            Inventory::where('product_id', $productId)
                ->whereNull('deleted_at')
                ->whereRaw('LOWER(TRIM(tested_status)) = ?', ['pass'])
                ->select('id', 'serial_no', 'product_id', 'tested_status')
                ->get()
        );
    }
}
