<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Vendor;
use App\Models\SparepartPurchase;
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

        // Vendor name unique
        'vendor' => [
            'required', 
            'string', 
            'max:255', 
            function ($attribute, $value, $fail) {
                $exists = Vendor::where('vendor', $value)
                    ->whereNull('deleted_at')
                    ->exists();
                if ($exists) {
                    $fail("The $attribute has already been taken.");
                }
            },
        ],

        // GST optional
        'gst_no' => [
            'nullable', 
            'string', 
            'max:15',
            Rule::unique('vendors', 'gst_no')->whereNull('deleted_at'),
        ],

        'email' => [
            'nullable', 
            'email', 
            'max:255',
            Rule::unique('vendors', 'email')->whereNull('deleted_at'),
        ],

        // Location fields optional
        'pincode'  => 'nullable|digits:6',
        'city'     => 'nullable|string|max:100',
        'state'    => 'nullable|string|max:100',
        'district' => 'nullable|string|max:100',
        'address'  => 'nullable|string',

        // Vendor mobile optional
        'mobile_no' => [
            'nullable', 
            'string', 
            'max:15',
            Rule::unique('vendors', 'mobile_no')
                ->whereNull('deleted_at')
                ->ignore($request->id),
        ],

        // Contact Person
        'contact_persons'               => 'nullable|array',
        'contact_persons.*.name'        => 'required_with:contact_persons|string|max:255',
        'contact_persons.*.designation' => 'nullable|string|max:100',

'contact_persons.*.mobile_no' => [
    'nullable',
    'string',
    'max:15',
    function ($attribute, $value, $fail) use ($request) {

        preg_match('/contact_persons\.(\d+)\.mobile_no/', $attribute, $match);
        $index = $match[1] ?? null;

        $contacts = $request->contact_persons ?? [];

        /**
         * 1️⃣ CHECK DUPLICATE INSIDE SAME VENDOR CONTACT LIST
         */
        foreach ($contacts as $i => $contact) {
            if ($i != $index && ($contact['mobile_no'] ?? null) === $value) {
                return $fail("This mobile number is already used by another contact person in this vendor.");
            }
        }

        /**
         * 2️⃣ CHECK IF SAME AS VENDOR MAIN MOBILE
         */
        if (!empty($request->mobile_no) && $request->mobile_no === $value) {
            return $fail("Contact mobile number cannot be the same as the vendor's company mobile number.");
        }

        /**
         * ❌ REMOVED: DO NOT CHECK OTHER VENDORS' MAIN MOBILE
         */

        /**
         * ❌ REMOVED: DO NOT CHECK OTHER VENDORS' CONTACT PERSON MOBILE
         */
    }
],



        'contact_persons.*.email' => [
            'nullable', 
            'email', 
            'max:255',
            Rule::unique('vendor_contact_person', 'email')
                ->whereNull('deleted_at'),
        ],
    ]);

    if ($validator->fails()) {
        return response()->json([
            'errors' => $validator->errors()
        ], 422);
    }

    DB::beginTransaction();

    try {

        // Insert Vendor
        $vendorId = DB::table('vendors')->insertGetId([
            'vendor'      => $request->vendor,
            'gst_no'      => $request->gst_no,
            'email'       => $request->email,
            'pincode'     => $request->pincode,
            'city'        => $request->city,
            'state'       => $request->state,
            'district'    => $request->district,
            'address'     => $request->address,
            'mobile_no'   => $request->mobile_no,
            'status'      => 'Active',
            'created_at'  => now(),
            'updated_at'  => now(),
            'created_by'  => auth()->id(),
        ]);

        // Insert Contact Persons
        if (!empty($request->contact_persons)) {
            foreach ($request->contact_persons as $contact) {
                DB::table('vendor_contact_person')->insert([
                    'vendor_id'   => $vendorId,
                    'name'        => $contact['name'] ?? null,
                    'designation' => $contact['designation'] ?? null,
                    'mobile_no'   => $contact['mobile_no'] ?? null,
                    'email'       => $contact['email'] ?? null,
                    'status'      => 'Active',
                    'is_main'     => !empty($contact['is_main']) ? 1 : 0,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                    'created_by'  => auth()->id(),
                ]);
            }
        }

        DB::commit();

        return response()->json([
            'message'  => 'Vendor and contacts saved successfully',
            'vendor'   => DB::table('vendors')->where('id', $vendorId)->first(),
            'contacts' => DB::table('vendor_contact_person')->where('vendor_id', $vendorId)->get(),
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

        // UNIQUE VENDOR NAME (excluding soft deleted)
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

        // GST UNIQUE PER VENDOR (ignore same vendor)
        'gst_no' => [
            'nullable', 'string', 'max:50',
            Rule::unique('vendors', 'gst_no')
                ->ignore($id)
                ->whereNull('deleted_at')
        ],

        'email' => [
            'nullable', 'email', 'max:255',
            Rule::unique('vendors', 'email')
                ->ignore($id)
                ->whereNull('deleted_at'),
        ],

        'pincode'   => 'nullable|digits:6',
        'city'      => 'nullable|string|max:100',
        'state'     => 'nullable|string|max:100',
        'district'  => 'nullable|string|max:100',
        'address'   => 'nullable|string',

        'mobile_no' => [
            'nullable',
            'max:15',
            Rule::unique('vendors', 'mobile_no')
                ->ignore($id)
                ->whereNull('deleted_at'),
        ],

        // CONTACT PERSON VALIDATION
        'contact_persons'               => 'nullable|array',
        'contact_persons.*.name'        => 'required_with:contact_persons|string|max:255',
        'contact_persons.*.designation' => 'nullable|string|max:100',

        // Contact person mobile — UNIQUE ONLY FOR SAME VENDOR
        'contact_persons.*.mobile_no' => [
            'nullable',
            'max:15',
            Rule::unique('vendor_contact_person', 'mobile_no')
                ->where('vendor_id', $id) // only check duplicates inside same vendor
                ->whereNull('deleted_at'),
        ],

        // Contact person email — UNIQUE ONLY FOR SAME VENDOR
        'contact_persons.*.email' => [
            'nullable', 'email', 'max:255',
            Rule::unique('vendor_contact_person', 'email')
                ->where('vendor_id', $id)
                ->whereNull('deleted_at'),
        ],

    ]);

    if ($validator->fails()) {
        return response()->json(['errors' => $validator->errors()], 422);
    }

    // EXTRA MANUAL VALIDATION
    $extraErrors = [];
    $contacts = $request->contact_persons ?? [];

    // Prevent duplicate mobile numbers inside same vendor (same request)
    $mobiles = [];
    $emails = [];

    foreach ($contacts as $index => $cp) {

        // Prevent duplicate mobile inside same vendor
        if (!empty($cp['mobile_no'])) {
            if (in_array($cp['mobile_no'], $mobiles)) {
                $extraErrors["contact_persons.$index.mobile_no"][] = 
                    "Duplicate mobile number for this vendor's contact persons.";
            }
            $mobiles[] = $cp['mobile_no'];
        }

        // Prevent duplicate email inside same vendor
        if (!empty($cp['email'])) {
            if (in_array($cp['email'], $emails)) {
                $extraErrors["contact_persons.$index.email"][] = 
                    "Duplicate email for this vendor's contact persons.";
            }
            $emails[] = $cp['email'];
        }

        // Vendor mobile == contact mobile
        if (!empty($request->mobile_no) && !empty($cp['mobile_no']) &&
            $request->mobile_no === $cp['mobile_no']) {
            $extraErrors["contact_persons.$index.mobile_no"][] =
                "Contact person's mobile cannot be the same as vendor mobile.";
        }

        // Vendor email == contact email
        if (!empty($request->email) && !empty($cp['email']) &&
            strtolower($request->email) === strtolower($cp['email'])) {
            $extraErrors["contact_persons.$index.email"][] =
                "Contact person's email cannot be the same as vendor email.";
        }
    }

    // If extra validation errors found
    if (!empty($extraErrors)) {
        return response()->json(['errors' => $extraErrors], 422);
    }

    DB::beginTransaction();

    try {
        // UPDATE VENDOR
        DB::table('vendors')->where('id', $id)->update([
            'vendor'     => $request->vendor,
            'gst_no'     => $request->gst_no,
            'email'      => $request->email,
            'pincode'    => $request->pincode,
            'city'       => $request->city,
            'state'      => $request->state,
            'district'   => $request->district,
            'address'    => $request->address,
            'mobile_no'  => $request->mobile_no,
            'updated_at' => now(),
            'updated_by' => auth()->id(),
        ]);

        // DELETE OLD CONTACT PERSONS
        DB::table('vendor_contact_person')->where('vendor_id', $id)->delete();

        // INSERT NEW CONTACT PERSONS
        foreach ($contacts as $cp) {
            DB::table('vendor_contact_person')->insert([
                'vendor_id'   => $id,
                'name'        => $cp['name'] ?? null,
                'designation' => $cp['designation'] ?? null,
                'mobile_no'   => $cp['mobile_no'] ?? null,
                'email'       => $cp['email'] ?? null,
                'status'      => $cp['status'] ?? 'Active',
                'is_main'     => !empty($cp['is_main']) ? 1 : 0,
                'created_at'  => now(),
                'updated_at'  => now(),
                'created_by'  => auth()->id(),
                'updated_by'  => auth()->id(),
            ]);
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

        // Check if vendor exists and not soft deleted
        $vendor = Vendor::where('id', $id)
            ->whereNull('deleted_at')
            ->with(['contactPersons' => function ($q) {
                $q->whereNull('deleted_at');
            }])
            ->first();

        if (!$vendor) {
            return response()->json(['error' => 'Vendor not found'], 404);
        }

        $hasPurchase = SparepartPurchase::where('vendor_id', $id)->exists();

        if ($hasPurchase) {
            return response()->json([
                'error' => 'Vendor cannot be deleted because it is linked with purchase records.'
            ], 409); // 409 = conflict
        }

        $userId = auth()->id() ?? 1;

        // Delete contact persons
        foreach ($vendor->contactPersons as $contact) {
            $contact->deleted_by = $userId;
            $contact->save();
            $contact->delete();
        }

        // Delete vendor
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
