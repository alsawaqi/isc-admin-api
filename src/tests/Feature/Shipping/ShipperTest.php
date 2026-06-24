<?php

namespace Tests\Feature\Shipping;

use App\Models\Shipper;
use Tests\FeatureTestCase;

class ShipperTest extends FeatureTestCase
{
    private function makeShipper(array $overrides = []): Shipper
    {
        return Shipper::create(array_merge([
            'Shippers_Code'      => 'SHIPR_' . uniqid(),
            'Shippers_Name'      => 'Feature Test Shipper',
            'Shippers_Scope'     => 'local',
            'Shippers_Type'      => 'courier',
            'Shippers_Rate_Mode' => 'weight',
            'Shippers_Is_Active' => true,
        ], $overrides));
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/v1/shipping/shippers')->assertUnauthorized();
    }

    public function test_index_returns_paginated_list(): void
    {
        $this->actingAsAdmin();
        $shipper = $this->makeShipper();

        $res = $this->getJson('/api/v1/shipping/shippers?per_page=100');

        $res->assertOk();
        $res->assertJsonStructure([
            'data' => [],
            'links',
            'meta',
        ]);

        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($shipper->id, $ids);
    }

    public function test_index_can_be_filtered_by_search(): void
    {
        $this->actingAsAdmin();
        $unique = 'SRCH' . uniqid();
        $shipper = $this->makeShipper(['Shippers_Name' => $unique . ' Logistics']);

        $res = $this->getJson('/api/v1/shipping/shippers?search=' . $unique . '&per_page=100');

        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($shipper->id, $ids);
    }

    public function test_store_validation_fails_on_empty_payload(): void
    {
        $this->actingAsAdmin();

        $res = $this->postJson('/api/v1/shipping/shippers', []);

        $res->assertStatus(422);
        $res->assertJsonValidationErrors([
            'shipper',
        ]);
    }

    public function test_store_validation_fails_on_invalid_scope(): void
    {
        $this->actingAsAdmin();

        $res = $this->postJson('/api/v1/shipping/shippers', [
            'shipper' => [
                'Shippers_Name'      => 'Bad Scope Shipper',
                'Shippers_Scope'     => 'galactic',
                'Shippers_Type'      => 'courier',
                'Shippers_Rate_Mode' => 'weight',
            ],
        ]);

        $res->assertStatus(422);
        $res->assertJsonValidationErrors([
            'shipper.Shippers_Scope',
        ]);
    }

    public function test_store_creates_shipper_with_valid_payload(): void
    {
        $this->actingAsAdmin();

        $name = 'Created Shipper ' . uniqid();

        $res = $this->postJson('/api/v1/shipping/shippers', [
            'shipper' => [
                'Shippers_Name'      => $name,
                'Shippers_Scope'     => 'local',
                'Shippers_Type'      => 'courier',
                'Shippers_Rate_Mode' => 'weight',
            ],
        ]);

        $res->assertStatus(201);
        $res->assertJsonStructure(['id', 'message']);

        $this->assertDatabaseHas('Shippers_Master_T', [
            'id'            => $res->json('id'),
            'Shippers_Name' => $name,
            'Shippers_Scope' => 'local',
        ]);
    }

    public function test_toggle_flips_active_flag(): void
    {
        $this->actingAsAdmin();
        $shipper = $this->makeShipper(['Shippers_Is_Active' => true]);

        $res = $this->postJson("/api/v1/shipping/shippers/{$shipper->id}/toggle");

        $res->assertOk();

        $shipper->refresh();
        $this->assertFalse((bool) $shipper->Shippers_Is_Active);
    }
}
