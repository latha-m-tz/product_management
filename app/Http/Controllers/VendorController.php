<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Vendor;
use App\Models\ContactPerson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Http;

class VendorController extends Controller
{

    public function Vendorstore(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'vendor'         => 'required|string|max:255',
            'gst_no'         => 'nullable|string|max:15|unique:vendors,gst_no',
            'email'          => 'nullable|email|max:255|unique:vendors,email',
            'pincode'        => 'nullable|digits:6',
            'city'           => 'nullable|string|max:100',
            'state'          => 'nullable|string|max:100',
            'district'       => 'nullable|string|max:100',
            'address'        => 'nullable|string',
            'mobile_no'      => 'required|max:15|unique:vendors,mobile_no',

            'contact_persons'                 => 'nullable|array',
            'contact_persons.*.name'          => 'required_with:contact_persons|string|max:255',
            'contact_persons.*.designation'   => 'nullable|string|max:100',
            'contact_persons.*.mobile_no'     => 'required_with:contact_persons|max:15|unique:vendor_contact_person,mobile_no',
            'contact_persons.*.email'         => 'nullable|email|max:255|unique:vendor_contact_person,email',
        ]);


        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();

        try {
            $vendorId = DB::table('vendors')->insertGetId([
                'vendor'         => $request->vendor,
                'gst_no'         => $request->gst_no,
                'email'          => $request->email,
                'pincode'        => $request->pincode,
                'city'           => $request->city,
                'state'          => $request->state,
                'district'       => $request->district,
                'address'        => $request->address,
                'mobile_no'      => $request->mobile_no,
                // 'alt_mobile_no'  => $request->alt_mobile_no,
                'status'         => 'Active',
                'created_at'     => now(),
                'updated_at'     => now(),
                'created_by' => auth()->id() ?? 1,


            ]);


            if (!empty($request->contact_persons) && is_array($request->contact_persons)) {
                foreach ($request->contact_persons as $contact) {
                    DB::table('vendor_contact_person')->insert([
                        'vendor_id'     => $vendorId,
                        'name'          => $contact['name'] ?? null,
                        'designation'   => $contact['designation'] ?? null,
                        'mobile_no'     => $contact['mobile_no'] ?? null,
                        // 'alt_mobile_no' => $contact['alt_mobile_no'] ?? null,
                        'email'         => $contact['email'] ?? null,
                        'status'        => 'Active',
                         'is_main'     => !empty($contact['is_main']) ? 1 : 0, 
                        'created_at'    => now(),
                        'updated_at'    => now(),
                        'created_by' => auth()->id() ?? 1,

                    ]);
                }
            }

            DB::commit();

            // return response()->json([
            //     'message'   => 'Vendor and contacts saved successfully',
            //     'vendor_id' => $vendorId
            // ], 201);

            $vendor = DB::table('vendors')->where('id', $vendorId)->first();
            $contacts = DB::table('vendor_contact_person')->where('vendor_id', $vendorId)->get();

            return response()->json([
                'message' => 'Vendor and contacts saved successfully',
                'vendor'  => $vendor,
                'contacts' => $contacts,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }



    public function VendorEdit($id)
    {
        try {
            $vendor = DB::table('vendors')->where('id', $id)->first();

            if (!$vendor) {
                return response()->json(['error' => 'Vendor not found'], 404);
            }

            $contacts = DB::table('vendor_contact_person')
                ->where('vendor_id', $id)
                ->get();

            return response()->json([
                'vendor'   => $vendor,
                'contacts' => $contacts
            ], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }


    public function VendorUpdate(Request $request, $id)
    {

        $validator = Validator::make($request->all(), [
            'vendor'   => 'required|string|max:255',
            'gst_no'         => "nullable|string|max:50|unique:vendors,gst_no,{$id}",
            'email'          => "nullable|email|max:255|unique:vendors,email,{$id}",
            'pincode'        => 'nullable|digits:6',
            'city'           => 'nullable|string|max:100',
            'state'          => 'nullable|string|max:100',
            'district'       => 'nullable|string|max:100',
            'address'        => 'nullable|string',
            'mobile_no'      => "required|max:15|unique:vendors,mobile_no,{$id}",
            // 'alt_mobile_no'  => 'nullable|max:15',

            'contact_persons'               => 'nullable|array',
            'contact_persons.*.name'        => 'required_with:contact_persons|string|max:255',
            'contact_persons.*.designation' => 'nullable|string|max:100',
            'contact_persons.*.mobile_no'   => 'required_with:contact_persons|max:15',
            'contact_persons.*.email'       => 'nullable|email|max:255',
        ]);


        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();

        try {

            $updated = DB::table('vendors')
                ->where('id', $id)
                ->update([
                    'vendor'         => $request->vendor,
                    'gst_no'         => $request->gst_no,
                    'email'          => $request->email,
                    'pincode'        => $request->pincode,
                    'city'           => $request->city,
                    'state'          => $request->state,
                    'district'       => $request->district,
                    'address'        => $request->address,
                    'mobile_no'      => $request->mobile_no,
                    // 'alt_mobile_no'  => $request->alt_mobile_no,
                    'updated_at'     => now(),
                    'updated_by'     => auth()->id() ?? 1,
                ]);

            if (!$updated) {
                return response()->json(['error' => 'Vendor not found'], 404);
            }

            DB::table('vendor_contact_person')->where('vendor_id', $id)->delete();

            if (!empty($request->contact_persons) && is_array($request->contact_persons)) {
                foreach ($request->contact_persons as $contact) {
                    DB::table('vendor_contact_person')->insert([
                        'vendor_id'     => $id,
                        'name'          => $contact['name'] ?? null,
                        'designation'   => $contact['designation'] ?? null,
                        'mobile_no'     => $contact['mobile_no'] ?? null,
                        // 'alt_mobile_no' => $contact['alt_mobile_no'] ?? null,
                        'email'         => $contact['email'] ?? null,
                        'status'        => $contact['status'] ?? 'Active',
                        'is_main'       => !empty($contact['is_main']) ? 1 : 0,
                        'created_at'    => now(),
                        'updated_at'    => now(),
                        'updated_by'    => auth()->id() ?? 1,
                    ]);
                }
            }

            DB::commit();

            return response()->json(['message' => 'Vendor and contacts updated successfully'], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function VendorList()
    {
        $vendors = DB::table('vendors')
            ->select('id', 'vendor', 'gst_no', 'email', 'mobile_no', 'status', 'created_at')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($vendors);
    }
    public function show($id)
    {
        $vendor = Vendor::with('contactPersons')->find($id);

        if (!$vendor) {
            return response()->json(['message' => 'Vendor not found'], 404);
        }

        return response()->json($vendor);
    }
    public function destroy($id)
    {
        try {
            $vendor = Vendor::with('contactPersons')->findOrFail($id);
            $userId = auth()->id() ?? 1;

            foreach ($vendor->contactPersons as $contact) {
                $contact->deleted_by = $userId;
                $contact->save();
                $contact->delete();
            }

            $vendor->deleted_by = $userId;
            $vendor->save();
            $vendor->delete();

            return response()->json([
                'message' => 'Vendor and contacts soft deleted successfully',
                'deleted_by' => $userId
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
