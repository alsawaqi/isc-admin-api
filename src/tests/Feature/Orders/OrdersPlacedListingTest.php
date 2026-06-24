<?php

namespace Tests\Feature\Orders;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\FeatureTestCase;

class OrdersPlacedListingTest extends FeatureTestCase
{
    /**
     * Endpoints that return a Laravel paginator at the top level
     * (i.e. they have a `data` array plus pagination meta).
     *
     * @return array<string, array{0:string}>
     */
    public static function paginatedEndpoints(): array
    {
        return [
            'index (pending)'      => ['/api/orders-placed'],
            'packing'              => ['/api/orders-placed/packing'],
            'dispatch'             => ['/api/orders-placed/dispatch'],
            'shipment'             => ['/api/orders-placed/shipment'],
            'pickup'               => ['/api/orders-placed/pickup'],
            'delivered'            => ['/api/orders-placed/delivered'],
        ];
    }

    #[DataProvider('paginatedEndpoints')]
    public function test_listing_endpoints_return_paginated_shape(string $url): void
    {
        $this->actingAsAdmin();

        $res = $this->getJson($url . '?per_page=5');

        $res->assertOk();
        // Laravel paginator top-level keys
        $res->assertJsonStructure([
            'data',
            'current_page',
            'per_page',
            'total',
        ]);
    }

    public function test_customer_listing_returns_data_and_customer_keys(): void
    {
        $this->actingAsAdmin();

        $res = $this->getJson('/api/orders-placed/customer?per_page=5');

        $res->assertOk();
        // index_customer wraps the paginator under `data` and adds `customer`
        $res->assertJsonStructure([
            'data' => [
                'data',
                'current_page',
                'per_page',
                'total',
            ],
        ]);
        $this->assertArrayHasKey('customer', $res->json());
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/orders-placed')->assertUnauthorized();
    }

    public function test_delivered_requires_authentication(): void
    {
        $this->getJson('/api/orders-placed/delivered')->assertUnauthorized();
    }

    public function test_show_returns_404_for_missing_order(): void
    {
        $this->actingAsAdmin();

        // findOrFail on a non-existent id must yield a 404.
        $this->getJson('/api/orders-placed/999999999')->assertNotFound();
    }

    public function test_overview_returns_404_for_missing_order(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/orders-placed/999999999/overview')->assertNotFound();
    }

    public function test_complete_transition_returns_404_for_missing_order(): void
    {
        $this->actingAsAdmin();

        // complete() does findOrFail($id) before any work, so a bad id => 404.
        $this->postJson('/api/orders-placed/complete/999999999')->assertNotFound();
    }
}
