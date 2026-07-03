<?php

namespace Tests\Feature\Shipping;

use App\Models\Designation;
use App\Models\Shipper;
use App\Models\ShipperContact;
use App\Models\Title;
use Tests\FeatureTestCase;

class ShipperContactTest extends FeatureTestCase
{
    private function makeShipper(): Shipper
    {
        return Shipper::create([
            'Shippers_Code'      => 'SHIPR_' . uniqid(),
            'Shippers_Name'      => 'Contact Test Shipper',
            'Shippers_Scope'     => 'local',
            'Shippers_Type'      => 'courier',
            'Shippers_Rate_Mode' => 'weight',
            'Shippers_Is_Active' => true,
        ]);
    }

    private function makeTitle(): Title
    {
        return Title::create([
            'Title_Name' => 'CT_Title_' . uniqid(),
            'Is_Active'  => true,
        ]);
    }

    private function makeDesignation(): Designation
    {
        return Designation::create([
            'Designation_Name' => 'CT_Desig_' . uniqid(),
            'Is_Active'        => true,
        ]);
    }

    public function test_store_persists_title_and_designation_and_returns_names(): void
    {
        $this->actingAsAdmin();
        $shipper     = $this->makeShipper();
        $title       = $this->makeTitle();
        $designation = $this->makeDesignation();

        $res = $this->postJson("/api/v1/shipping/shippers/{$shipper->id}/contacts", [
            'Shippers_Contact_Name' => 'Contact With Lookups',
            'Title_Id'              => $title->id,
            'Designation_Id'        => $designation->id,
        ]);

        $res->assertStatus(201);
        $res->assertJsonPath('data.Title_Id', $title->id);
        $res->assertJsonPath('data.Designation_Id', $designation->id);
        $res->assertJsonPath('data.title_name', $title->Title_Name);
        $res->assertJsonPath('data.designation_name', $designation->Designation_Name);

        $this->assertDatabaseHas('Shipper_Contacts_T', [
            'Shippers_Id'           => $shipper->id,
            'Shippers_Contact_Name' => 'Contact With Lookups',
            'Title_Id'              => $title->id,
            'Designation_Id'        => $designation->id,
        ]);
    }

    public function test_store_works_without_title_and_designation(): void
    {
        $this->actingAsAdmin();
        $shipper = $this->makeShipper();

        $res = $this->postJson("/api/v1/shipping/shippers/{$shipper->id}/contacts", [
            'Shippers_Contact_Name' => 'Plain Contact',
        ]);

        $res->assertStatus(201);
        $res->assertJsonPath('data.Title_Id', null);
        $res->assertJsonPath('data.Designation_Id', null);
        $res->assertJsonPath('data.title_name', null);
        $res->assertJsonPath('data.designation_name', null);
    }

    public function test_store_validation_fails_on_unknown_lookup_ids(): void
    {
        $this->actingAsAdmin();
        $shipper = $this->makeShipper();

        $res = $this->postJson("/api/v1/shipping/shippers/{$shipper->id}/contacts", [
            'Shippers_Contact_Name' => 'Bad Lookups',
            'Title_Id'              => 999999999,
            'Designation_Id'        => 999999999,
        ]);

        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['Title_Id', 'Designation_Id']);
    }

    public function test_update_persists_title_and_designation(): void
    {
        $this->actingAsAdmin();
        $shipper     = $this->makeShipper();
        $title       = $this->makeTitle();
        $designation = $this->makeDesignation();
        $contact = ShipperContact::create([
            'Shippers_Id'           => $shipper->id,
            'Shippers_Contact_Name' => 'Contact To Update',
        ]);

        $res = $this->putJson("/api/v1/shipping/shippers/{$shipper->id}/contacts/{$contact->id}", [
            'Title_Id'       => $title->id,
            'Designation_Id' => $designation->id,
        ]);

        $res->assertOk();
        $res->assertJsonPath('data.title_name', $title->Title_Name);
        $res->assertJsonPath('data.designation_name', $designation->Designation_Name);

        $contact->refresh();
        $this->assertSame($title->id, (int) $contact->Title_Id);
        $this->assertSame($designation->id, (int) $contact->Designation_Id);
    }

    public function test_index_includes_lookup_names(): void
    {
        $this->actingAsAdmin();
        $shipper     = $this->makeShipper();
        $title       = $this->makeTitle();
        $designation = $this->makeDesignation();
        $contact = ShipperContact::create([
            'Shippers_Id'           => $shipper->id,
            'Shippers_Contact_Name' => 'Listed Contact',
            'Title_Id'              => $title->id,
            'Designation_Id'        => $designation->id,
        ]);

        $res = $this->getJson("/api/v1/shipping/shippers/{$shipper->id}/contacts");

        $res->assertOk();
        $row = collect($res->json('data'))->firstWhere('id', $contact->id);
        $this->assertNotNull($row);
        $this->assertSame($title->Title_Name, $row['title_name']);
        $this->assertSame($designation->Designation_Name, $row['designation_name']);
    }
}
