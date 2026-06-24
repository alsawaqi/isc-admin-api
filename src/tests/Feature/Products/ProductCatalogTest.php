<?php

namespace Tests\Feature\Products;

use Tests\FeatureTestCase;

/**
 * Feature tests for the admin product catalog endpoints:
 * brands, types, manufacture, departments and the product master listing.
 *
 * Covers: index listings (200 + pagination keys), simple creates
 * (201 + assertDatabaseHas), validation (422) on the only endpoint that
 * validates (departments) and auth-required (401).
 */
class ProductCatalogTest extends FeatureTestCase
{
    public function test_brands_index_returns_paginated_payload(): void
    {
        $this->actingAsAdmin();

        $res = $this->getJson('/api/productbrands');

        $res->assertOk();
        $res->assertJsonStructure(['data', 'total', 'current_page', 'per_page']);
    }

    public function test_types_index_returns_paginated_payload(): void
    {
        $this->actingAsAdmin();

        $res = $this->getJson('/api/producttype');

        $res->assertOk();
        $res->assertJsonStructure(['data', 'total', 'current_page', 'per_page']);
    }

    public function test_brand_can_be_created(): void
    {
        $this->actingAsAdmin();

        $name = 'Feature Test Brand ' . uniqid();

        $res = $this->postJson('/api/productbrands', [
            'name'    => $name,
            'name_ar' => 'علامة اختبار',
        ]);

        $res->assertCreated();
        $this->assertDatabaseHas('Products_Brands_Master_T', [
            'Products_Brands_Name' => $name,
        ]);
    }

    public function test_type_can_be_created(): void
    {
        $this->actingAsAdmin();

        $name = 'Feature Test Type ' . uniqid();

        $res = $this->postJson('/api/producttype', [
            'name'    => $name,
            'name_ar' => 'نوع اختبار',
        ]);

        $res->assertCreated();
        $this->assertDatabaseHas('Products_Types_Master_T', [
            'Product_Types_Name' => $name,
        ]);
    }

    public function test_manufacture_can_be_created(): void
    {
        $this->actingAsAdmin();

        $name = 'Feature Test Mfr ' . uniqid();

        $res = $this->postJson('/api/productmanufacture', [
            'name'    => $name,
            'name_ar' => 'مصنع اختبار',
        ]);

        $res->assertCreated();
        $this->assertDatabaseHas('Products_Manufacture_Master_T', [
            'Products_Manufacture_Name' => $name,
        ]);
    }

    public function test_department_can_be_created(): void
    {
        $this->actingAsAdmin();

        $name = 'Feature Test Dept ' . uniqid();

        $res = $this->postJson('/api/productdepartment', [
            'name'   => $name,
            'namear' => 'قسم اختبار',
        ]);

        // Controller returns no explicit body on success (falls through => 200).
        $res->assertOk();
        $this->assertDatabaseHas('Products_Departments_T', [
            'Product_Department_Name' => $name,
        ]);
    }

    public function test_department_create_rejects_empty_payload(): void
    {
        $this->actingAsAdmin();

        // store() validates name/namear and now returns a proper 422 (the validation
        // error is no longer swallowed into a 500 by the catch block).
        $this->postJson('/api/productdepartment', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'namear']);
    }

    public function test_product_master_index_returns_ok(): void
    {
        $this->actingAsAdmin();

        $res = $this->getJson('/api/productmaster?per_page=5');

        $res->assertOk();
        $res->assertJsonStructure(['data']);
    }

    public function test_catalog_endpoints_require_authentication(): void
    {
        $this->getJson('/api/productbrands')->assertUnauthorized();
        $this->getJson('/api/productmaster')->assertUnauthorized();
    }
}
