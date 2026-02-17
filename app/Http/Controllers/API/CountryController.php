<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\CountryResource;
use App\Models\Country;
use Illuminate\Support\Facades\Cache;

class CountryController extends Controller
{
    public function index()
    {
        $supportedCountry = (string) config('farmlet.supported_country', 'Kenya');
        $cacheKey = 'countries.'.mb_strtolower($supportedCountry);

        $countries = Cache::rememberForever($cacheKey, function () use ($supportedCountry) {
            return Country::query()
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($supportedCountry)])
                ->get();
        });

        return $this->json('all countries', [
            'countries' => CountryResource::collection($countries),
        ]);
    }
}
