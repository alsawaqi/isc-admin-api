<?php

namespace Tests\Feature\System;

use App\Models\Designation;
use App\Models\ShipperContact;
use App\Models\Shipper;
use App\Models\Title;
use Tests\FeatureTestCase;

class TitlesDesignationsTest extends FeatureTestCase
{
    private function makeTitle(array $overrides = []): Title
    {
        return Title::create(array_merge([
            'Title_Name' => 'TestTitle_' . uniqid(),
            'Is_Active'  => true,
        ], $overrides));
    }

    private function makeDesignation(array $overrides = []): Designation
    {
        return Designation::create(array_merge([
            'Designation_Name' => 'TestDesig_' . uniqid(),
            'Is_Active'        => true,
        ], $overrides));
    }

    private function makeShipper(): Shipper
    {
        return Shipper::create([
            'Shippers_Code'      => 'SHIPR_' . uniqid(),
            'Shippers_Name'      => 'Lookup Test Shipper',
            'Shippers_Scope'     => 'local',
            'Shippers_Type'      => 'courier',
            'Shippers_Rate_Mode' => 'weight',
            'Shippers_Is_Active' => true,
        ]);
    }

    // ---------------------------------------------------------------- Titles

    public function test_titles_index_requires_authentication(): void
    {
        $this->getJson('/api/titles')->assertUnauthorized();
    }

    public function test_titles_index_returns_paginated_list_with_search(): void
    {
        $this->actingAsAdmin();
        $unique = 'SRCH' . uniqid();
        $title = $this->makeTitle(['Title_Name' => $unique]);

        $res = $this->getJson('/api/titles?search=' . $unique . '&per_page=100');

        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($title->id, $ids);
    }

    public function test_titles_all_returns_only_active(): void
    {
        $this->actingAsAdmin();
        $active   = $this->makeTitle();
        $inactive = $this->makeTitle(['Is_Active' => false]);

        $res = $this->getJson('/api/titles/all');

        $res->assertOk();
        $ids = collect($res->json())->pluck('id')->all();
        $this->assertContains($active->id, $ids);
        $this->assertNotContains($inactive->id, $ids);
    }

    public function test_titles_store_creates_title(): void
    {
        $this->actingAsAdmin();
        $name = 'Created Title ' . uniqid();

        $res = $this->postJson('/api/titles', [
            'name'    => $name,
            'name_ar' => 'عنوان',
        ]);

        $res->assertStatus(201);
        $this->assertDatabaseHas('Titles_Master_T', [
            'Title_Name'    => $name,
            'Title_Name_Ar' => 'عنوان',
            'Is_Active'     => 1,
        ]);
    }

    public function test_titles_store_validation_fails_without_name(): void
    {
        $this->actingAsAdmin();

        $res = $this->postJson('/api/titles', ['name_ar' => 'بدون اسم']);

        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['name']);
    }

    public function test_titles_update_changes_names(): void
    {
        $this->actingAsAdmin();
        $title = $this->makeTitle();
        $newName = 'Updated Title ' . uniqid();

        $res = $this->putJson('/api/titles/' . $title->id, [
            'name'    => $newName,
            'name_ar' => 'محدث',
        ]);

        $res->assertOk();
        $title->refresh();
        $this->assertSame($newName, $title->Title_Name);
        $this->assertSame('محدث', $title->Title_Name_Ar);
    }

    public function test_titles_toggle_flips_active_flag(): void
    {
        $this->actingAsAdmin();
        $title = $this->makeTitle(['Is_Active' => true]);

        $this->postJson('/api/titles/' . $title->id . '/toggle')->assertOk();

        $title->refresh();
        $this->assertFalse($title->Is_Active);
    }

    public function test_titles_destroy_deletes_unused_title(): void
    {
        $this->actingAsAdmin();
        $title = $this->makeTitle();

        $this->deleteJson('/api/titles/' . $title->id)->assertOk();

        $this->assertDatabaseMissing('Titles_Master_T', ['id' => $title->id]);
    }

    public function test_titles_destroy_blocks_title_in_use(): void
    {
        $this->actingAsAdmin();
        $title   = $this->makeTitle();
        $shipper = $this->makeShipper();
        ShipperContact::create([
            'Shippers_Id'           => $shipper->id,
            'Shippers_Contact_Name' => 'Uses Title',
            'Title_Id'              => $title->id,
        ]);

        $this->deleteJson('/api/titles/' . $title->id)->assertStatus(422);

        $this->assertDatabaseHas('Titles_Master_T', ['id' => $title->id]);
    }

    // ---------------------------------------------------------- Designations

    public function test_designations_index_requires_authentication(): void
    {
        $this->getJson('/api/designations')->assertUnauthorized();
    }

    public function test_designations_all_returns_only_active(): void
    {
        $this->actingAsAdmin();
        $active   = $this->makeDesignation();
        $inactive = $this->makeDesignation(['Is_Active' => false]);

        $res = $this->getJson('/api/designations/all');

        $res->assertOk();
        $ids = collect($res->json())->pluck('id')->all();
        $this->assertContains($active->id, $ids);
        $this->assertNotContains($inactive->id, $ids);
    }

    public function test_designations_store_creates_designation(): void
    {
        $this->actingAsAdmin();
        $name = 'Created Designation ' . uniqid();

        $res = $this->postJson('/api/designations', [
            'name'    => $name,
            'name_ar' => 'مسمى وظيفي',
        ]);

        $res->assertStatus(201);
        $this->assertDatabaseHas('Designations_Master_T', [
            'Designation_Name'    => $name,
            'Designation_Name_Ar' => 'مسمى وظيفي',
            'Is_Active'           => 1,
        ]);
    }

    public function test_designations_store_validation_fails_without_name(): void
    {
        $this->actingAsAdmin();

        $res = $this->postJson('/api/designations', []);

        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['name']);
    }

    public function test_designations_update_changes_names(): void
    {
        $this->actingAsAdmin();
        $designation = $this->makeDesignation();
        $newName = 'Updated Designation ' . uniqid();

        $res = $this->putJson('/api/designations/' . $designation->id, [
            'name' => $newName,
        ]);

        $res->assertOk();
        $designation->refresh();
        $this->assertSame($newName, $designation->Designation_Name);
    }

    public function test_designations_toggle_flips_active_flag(): void
    {
        $this->actingAsAdmin();
        $designation = $this->makeDesignation(['Is_Active' => true]);

        $this->postJson('/api/designations/' . $designation->id . '/toggle')->assertOk();

        $designation->refresh();
        $this->assertFalse($designation->Is_Active);
    }

    public function test_designations_destroy_deletes_unused_designation(): void
    {
        $this->actingAsAdmin();
        $designation = $this->makeDesignation();

        $this->deleteJson('/api/designations/' . $designation->id)->assertOk();

        $this->assertDatabaseMissing('Designations_Master_T', ['id' => $designation->id]);
    }

    public function test_designations_destroy_blocks_designation_in_use(): void
    {
        $this->actingAsAdmin();
        $designation = $this->makeDesignation();
        $shipper     = $this->makeShipper();
        ShipperContact::create([
            'Shippers_Id'           => $shipper->id,
            'Shippers_Contact_Name' => 'Uses Designation',
            'Designation_Id'        => $designation->id,
        ]);

        $this->deleteJson('/api/designations/' . $designation->id)->assertStatus(422);

        $this->assertDatabaseHas('Designations_Master_T', ['id' => $designation->id]);
    }
}
