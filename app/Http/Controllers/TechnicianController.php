<?php

namespace App\Http\Controllers;

use App\Models\Technician;
use Illuminate\Http\Request;

class TechnicianController extends Controller
{
    public function index()
    {
        return response()->json(Technician::all());
    }

    public function store(Request $request)
    {
        $request->validate([
            'employee_id' => 'required|unique:technicians',
            'name' => 'required',
            'email' => 'nullable|email',
            'phone' => 'nullable|string',
        ]);

        $technician = Technician::create($request->all());
        return response()->json($technician, 201);
    }

    public function show($id)
    {
        return response()->json(Technician::findOrFail($id));
    }

    public function update(Request $request, $id)
    {
        $technician = Technician::findOrFail($id);

        $request->validate([
            'employee_id' => 'required|unique:technicians,employee_id,' . $id,
            'name' => 'required',
            'email' => 'nullable|email',
            'phone' => 'nullable|string',
        ]);

        $technician->update($request->all());
        return response()->json($technician);
    }

    public function destroy($id)
    {
        $technician = Technician::findOrFail($id);
        $technician->delete();
        return response()->json(['message' => 'Technician deleted']);
    }
}
