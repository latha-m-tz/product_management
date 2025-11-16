<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Vendor;
use App\Models\ContactPerson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;

class VendorController extends Controller
{

  public function Vendorstore(Request $request)
{
    $validator = Validator::make($request->all(), [
        'vendor' => [
            'required', 'string', 'max:255',
            function ($attribute, $value, $fail) {
                $exists = Vendor::where('vendor', $value)
                    ->whereNull('deleted_at') // Soft delete logic
                    ->exists();
                if ($exists) {
                    $fail("The $attribute has already been taken.");
                }
            },
        ],

        'gst_no'    => [
            'nullable', 'string', 'max:15',
            Rule::unique('vendors', 'gst_no')->whereNull('deleted_at')
        ],

        'email'     => [
            'nullable', 'email', 'max:255',
            Rule::unique('vendors', 'email')->whereNull('deleted_at')
        ],

        'pincode'   => 'nullable|digits:6',
        'city'      => 'nullable|string|max:100',
        'state'     => 'nullable|string|max:100',
        'district'  => 'nullable|string|max:100',
        'address'   => 'nullable|string',

        'mobile_no' => [
            'required', 'max:15',
            Rule::unique('vendors', 'mobile_no')->whereNull('deleted_at')
        ],

        'contact_persons'               => 'nullable|array',
        'contact_persons.*.name'        => 'required_with:contact_persons|string|max:255',
        'contact_persons.*.designation' => 'nullable|string|max:100',
        'contact_persons.*.mobile_no'   => [
            'required_with:contact_persons',
            'max:15',
            Rule::unique('vendor_contact_person', 'mobile_no')->whereNull('deleted_at')
        ],
        'contact_persons.*.email'       => [
            'nullable', 'email', 'max:255',
            Rule::unique('vendor_contact_person', 'email')->whereNull('deleted_at')
        ],
    ]);

    if ($validator->fails()) {
        return response()->json([
            'errors' => $validator->errors()
        ], 422);
    }

    DB::beginTransaction();

    try {
        $vendorId = DB::table('vendors')->insertGetId([
            'vendor'        => $request->vendor,
            'gst_no'        => $request->gst_no,
            'email'         => $request->email,
            'pincode'       => $request->pincode,
            'city'          => $request->city,
            'state'         => $request->state,
            'district'      => $request->district,
            'address'       => $request->address,
            'mobile_no'     => $request->mobile_no,
            'status'        => 'Active',
            'created_at'    => now(),
            'updated_at'    => now(),
            'created_by'    => auth()->id(),
        ]);

        if (!empty($request->contact_persons) && is_array($request->contact_persons)) {
            foreach ($request->contact_persons as $contact) {
                DB::table('vendor_contact_person')->insert([
                    'vendor_id'     => $vendorId,
                    'name'          => $contact['name'] ?? null,
                    'designation'   => $contact['designation'] ?? null,
                    'mobile_no'     => $contact['mobile_no'] ?? null,
                    'email'         => $contact['email'] ?? null,
                    'status'        => 'Active',
                    'is_main'       => !empty($contact['is_main']) ? 1 : 0,
                    'created_at'    => now(),
                    'updated_at'    => now(),
                    'created_by'    => auth()->id(),
                ]);
            }
        }

        DB::commit();

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

        // -------------------- VENDOR FIELD VALIDATIONS --------------------
        'vendor' => [
            'required', 'string', 'max:255',
            function ($attribute, $value, $fail) use ($id) {
                $exists = Vendor::where('vendor', $value)
                    ->where('id', '!=', $id)
                    ->whereNull('deleted_at')
                    ->exists();

                if ($exists) {
                    $fail("The $attribute has already been taken.");
                }
            },
        ],

        'gst_no' => [
            'nullable', 'string', 'max:50',
            Rule::unique('vendors', 'gst_no')
                ->ignore($id, 'id')
                ->whereNull('deleted_at')
        ],

        'email' => [
            'nullable', 'email', 'max:255',

            Rule::unique('vendors', 'email')
                ->ignore($id, 'id')
                ->whereNull('deleted_at'),

            // ❌ Prevent vendor email == contact person email
            function ($attribute, $value, $fail) use ($request) {
                if (!empty($request->contact_persons)) {
                    foreach ($request->contact_persons as $cp) {
                        if (!empty($cp['email']) && strtolower($cp['email']) == strtolower($value)) {
                            $fail("Vendor email cannot be the same as a contact person's email.");
                        }
                    }
                }
            },
        ],

        'pincode'  => 'nullable|digits:6',
        'city'     => 'nullable|string|max:100',
        'state'    => 'nullable|string|max:100',
        'district' => 'nullable|string|max:100',
        'address'  => 'nullable|string',

        'mobile_no' => [
            'required', 'max:15',

            Rule::unique('vendors', 'mobile_no')
                ->ignore($id, 'id')
                ->whereNull('deleted_at'),

            // ❌ Prevent vendor mobile == contact person mobile
            function ($attribute, $value, $fail) use ($request) {
                if (!empty($request->contact_persons)) {
                    foreach ($request->contact_persons as $cp) {
                        if (!empty($cp['mobile_no']) && $cp['mobile_no'] == $value) {
                            $fail("Vendor mobile number cannot be the same as a contact person's mobile number.");
                        }
                    }
                }
            },
        ],

        // -------------------- CONTACT PERSON VALIDATIONS --------------------
        'contact_persons'               => 'nullable|array',
        'contact_persons.*.name'        => 'required_with:contact_persons|string|max:255',
        'contact_persons.*.designation' => 'nullable|string|max:100',

        'contact_persons.*.mobile_no' => [
            'required_with:contact_persons',
            'max:15',

            Rule::unique('vendor_contact_person', 'mobile_no')
                ->whereNull('deleted_at')
                ->where(function ($q) use ($id) {
                    return $q->where('vendor_id', '!=', $id);
                }),

            // ❌ Prevent contact person's mobile == vendor mobile
            function ($attribute, $value, $fail) use ($request) {
                if (!empty($request->mobile_no) && $request->mobile_no == $value) {
                    $fail("Contact person's mobile cannot be the same as vendor mobile number.");
                }
            },
        ],

        'contact_persons.*.email' => [
            'nullable', 'email', 'max:255',

            Rule::unique('vendor_contact_person', 'email')
                ->whereNull('deleted_at')
                ->where(function ($q) use ($id) {
                    return $q->where('vendor_id', '!=', $id);
                }),

            // ❌ Prevent contact person email == vendor email
            function ($attribute, $value, $fail) use ($request) {
                if (!empty($request->email) && strtolower($request->email) == strtolower($value)) {
                    $fail("Contact person's email cannot be the same as vendor email.");
                }
            },
        ],
    ]);

    // -------------------- VALIDATION FAIL RESPONSE --------------------
    if ($validator->fails()) {
        return response()->json([
            'errors' => $validator->errors()
        ], 422);
    }

    DB::beginTransaction();

    try {
        // -------------------- UPDATE VENDOR --------------------
        $updated = DB::table('vendors')
            ->where('id', $id)
            ->update([
                'vendor'        => $request->vendor,
                'gst_no'        => $request->gst_no,
                'email'         => $request->email,
                'pincode'       => $request->pincode,
                'city'          => $request->city,
                'state'         => $request->state,
                'district'      => $request->district,
                'address'       => $request->address,
                'mobile_no'     => $request->mobile_no,
                'updated_at'    => now(),
                'updated_by'    => auth()->id() ?? 1,
            ]);

        if (!$updated) {
            return response()->json(['error' => 'Vendor not found'], 404);
        }

        // -------------------- DELETE OLD CONTACT PERSONS --------------------
        DB::table('vendor_contact_person')->where('vendor_id', $id)->delete();

        // -------------------- INSERT NEW CONTACT PERSONS --------------------
        if (!empty($request->contact_persons) && is_array($request->contact_persons)) {
            foreach ($request->contact_persons as $contact) {
                DB::table('vendor_contact_person')->insert([
                    'vendor_id'     => $id,
                    'name'          => $contact['name'] ?? null,
                    'designation'   => $contact['designation'] ?? null,
                    'mobile_no'     => $contact['mobile_no'] ?? null,
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
        // $vendors = DB::table('vendors')
        //     ->select('id', 'vendor', 'gst_no', 'email', 'mobile_no', 'status', 'created_at')
        //     ->orderBy('created_at', 'desc')
        //     ->get();

        $vendors = DB::table('vendors')
    ->select('id', 'vendor', 'district', 'city','email', 'mobile_no', 'created_at')
    ->whereNull('deleted_at') // ignore soft-deleted vendors
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

        // Find vendor only if NOT already soft deleted
        $vendor = Vendor::where('id', $id)
            ->whereNull('deleted_at')
            ->with(['contactPersons' => function ($q) {
                $q->whereNull('deleted_at');
            }])
            ->first();

        if (!$vendor) {
            return response()->json(['error' => 'Vendor not found'], 404);
        }

        $userId = auth()->id() ?? 1;

        // Soft delete all active contact persons
        foreach ($vendor->contactPersons as $contact) {
            $contact->deleted_by = $userId;
            $contact->save();
            $contact->delete();
        }

        // Soft delete vendor
        $vendor->deleted_by = $userId;
        $vendor->save();
        $vendor->delete();

        return response()->json([
            'message'     => 'Vendor and contact persons deleted successfully',
            'deleted_by'  => $userId
        ], 200);

    } catch (\Exception $e) {
        return response()->json([
            'error' => $e->getMessage()
        ], 500);
    }
}


//    public function destroy($id)
// {
//     try {
//         $vendor = Vendor::find($id);

//         if (!$vendor) {
//             return response()->json([
//                 'error' => 'Vendor not found'
//             ], 404);
//         }

//         $vendor->delete(); // permanently deletes since SoftDeletes not used

//         return response()->json([
//             'message' => 'Vendor deleted successfully'
//         ], 200);

//     } catch (\Exception $e) {
//         return response()->json([
//             'error' => $e->getMessage()
//         ], 500);
//     }
// }

public function vendorCount()
{
    $count = Vendor::count();

    return response()->json([
        'success' => true,
        'message' => 'Vendor count fetched successfully',
        'count'   => $count
    ]);
}


}
