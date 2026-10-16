<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\Request;

class CompanyController extends Controller
{
    public function index(Request $request)
    {
        return $request->user()
            ->companies()
            ->wherePivot('status', 'active')
            ->get();
    }

    public function show(Company $company)
    {
        return response()->json([
            'data' => $company,
        ]);
    }
}