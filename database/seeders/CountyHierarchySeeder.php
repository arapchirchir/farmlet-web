<?php

namespace Database\Seeders;

use App\Models\County;
use App\Models\Subcounty;
use App\Models\Ward;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CountyHierarchySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $defaultPath = base_path('database/seeders/data/addesses.json');
        $path = env('FARMLET_ADDRESS_JSON', $defaultPath);

        if (filesize($path) === 0) {
            $this->command?->warn("CountyHierarchySeeder skipped: JSON file is empty at {$path}");
            return;
        }

        $payload = json_decode(file_get_contents($path), true);
        if (! is_array($payload)) {
            $this->command?->warn('CountyHierarchySeeder skipped: invalid JSON structure (expected array/object).');
            return;
        }

        $data = $payload['data'] ?? $payload['counties'] ?? null;
        if (! is_array($data)) {
            $this->command?->warn('CountyHierarchySeeder skipped: invalid JSON structure (expected data array).');
            return;
        }

        DB::disableQueryLog();

        foreach ($data as $countyData) {
            $countyName = trim((string) ($countyData['county_name'] ?? $countyData['name'] ?? ''));
            if ($countyName === '') {
                continue;
            }

            $countyCode = $countyData['county_code'] ?? $countyData['code'] ?? null;
            $countyShort = $this->makeShortCode($countyName, $countyCode);

            $county = County::firstOrCreate(
                ['name' => $countyName],
                ['county_code' => $countyCode, 'county_short_code' => $countyShort]
            );

            if (! $county->county_code && $countyCode) {
                $county->update(['county_code' => $countyCode]);
            }

            if (! $county->county_short_code && $countyShort) {
                $county->update(['county_short_code' => $countyShort]);
            }

            $subcounties = $countyData['subcounties'] ?? $countyData['constituencies'] ?? [];
            foreach ($subcounties as $subcountyData) {
                $subcountyName = trim((string) ($subcountyData['name'] ?? $subcountyData['const_name'] ?? ''));
                if ($subcountyName === '') {
                    continue;
                }

                $subcounty = Subcounty::firstOrCreate([
                    'county_id' => $county->id,
                    'name' => $subcountyName,
                ]);

                foreach (($subcountyData['wards'] ?? []) as $wardData) {
                    if (is_string($wardData)) {
                        $wardName = trim($wardData);
                    } else {
                        $wardName = trim((string) ($wardData['ward_name'] ?? $wardData['name'] ?? ''));
                    }
                    if ($wardName === '') {
                        continue;
                    }

                    Ward::firstOrCreate([
                        'subcounty_id' => $subcounty->id,
                        'name' => $wardName,
                    ]);
                }
            }
        }
    }

    private function makeShortCode(string $name, ?string $code): ?string
    {
        $letters = preg_replace('/[^A-Za-z]/', '', $name);
        $short = Str::upper(Str::substr($letters, 0, 4));

        if ($short !== '') {
            return $short;
        }

        return $code ?: null;
    }
}
