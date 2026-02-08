<?php

namespace App\Http\Controllers;

use App\Models\Subcounty;
use App\Models\Ward;
use Illuminate\Http\Request;

class LocationController extends Controller
{
    public function subcounties(Request $request)
    {
        $countyId = $request->query('county_id');
        if (! $countyId) {
            return response()->json([]);
        }

        $subcounties = Subcounty::query()
            ->where('county_id', $countyId)
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json($subcounties);
    }

    public function wards(Request $request)
    {
        $subcountyId = $request->query('subcounty_id');
        if (! $subcountyId) {
            return response()->json([]);
        }

        $wards = Ward::query()
            ->where('subcounty_id', $subcountyId)
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json($wards);
    }
}
