<?php

namespace Tests\Feature\Geo;

use App\Models\Country;
use App\Models\Region;
use Tests\FeatureTestCase;

class GeoTest extends FeatureTestCase
{
    private function makeCountry(array $overrides = []): Country
    {
        return Country::create(array_merge([
            'Country_Code'  => 'CT_' . uniqid(),
            'Country_Name'  => 'Testland ' . uniqid(),
            'Created_By'    => auth()->id() ?? 1,
            'Created_Date'  => now(),
        ], $overrides));
    }

    private function makeRegion(int $countryId, array $overrides = []): Region
    {
        return Region::create(array_merge([
            'Region_Code'  => 'RG_' . uniqid(),
            'Country_Id'   => $countryId,
            'Region_Name'  => 'Test Region ' . uniqid(),
            'Created_By'   => auth()->id() ?? 1,
            'Created_Date' => now(),
        ], $overrides));
    }

    public function test_countries_all_requires_authentication(): void
    {
        $this->getJson('/api/geo/countries/all')->assertUnauthorized();
    }

    public function test_countries_all_returns_ok_with_list(): void
    {
        $this->actingAsAdmin();
        $country = $this->makeCountry();

        $res = $this->getJson('/api/geo/countries/all');

        $res->assertOk();
        $ids = collect($res->json())->pluck('id')->all();
        $this->assertContains($country->id, $ids);
    }

    public function test_regions_by_country_returns_only_that_countrys_regions(): void
    {
        $this->actingAsAdmin();
        $country = $this->makeCountry();
        $region  = $this->makeRegion($country->id);

        $res = $this->getJson("/api/geo/regions/by-country/{$country->id}");

        $res->assertOk();
        $ids = collect($res->json())->pluck('id')->all();
        $this->assertContains($region->id, $ids);
    }

    public function test_create_country_validation_fails_on_empty_payload(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/countries', [])->assertStatus(422);
    }

    public function test_create_country_persists_row(): void
    {
        $this->actingAsAdmin();

        $name = 'Created Country ' . uniqid();
        $res = $this->postJson('/api/countries', [
            'Country_Name'    => $name,
            'Country_Name_Ar' => 'دولة',
        ]);

        $res->assertStatus(201);
        $this->assertDatabaseHas('Geox_Country_Master_T', [
            'Country_Name' => $name,
        ]);
    }

    public function test_create_region_validation_fails_on_empty_payload(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/regions', [])->assertStatus(422);
    }

    public function test_create_region_persists_row(): void
    {
        $this->actingAsAdmin();
        $country = $this->makeCountry();

        $name = 'Created Region ' . uniqid();
        $res = $this->postJson('/api/regions', [
            'Country_Id'   => $country->id,
            'Region_Name'  => $name,
        ]);

        $res->assertStatus(201);
        $this->assertDatabaseHas('Geox_Region_Master_T', [
            'Region_Name' => $name,
            'Country_Id'  => $country->id,
        ]);
    }
}
