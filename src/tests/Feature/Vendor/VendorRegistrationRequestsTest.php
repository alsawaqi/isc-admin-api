<?php

namespace Tests\Feature\Vendor;

use App\Models\VendorMaster;
use Tests\FeatureTestCase;

class VendorRegistrationRequestsTest extends FeatureTestCase
{
    private function makeVendor(array $overrides = []): VendorMaster
    {
        return VendorMaster::create(array_merge([
            'Vendor_Code'               => 'TESTREG_' . uniqid(),
            'Vendor_Name'               => 'Feature Test Vendor',
            'Approval_Status'           => 'pending',
            'Status'                    => 'pending',
            'Is_Active'                 => 0,
            'Submitted_For_Approval_At' => now(),
        ], $overrides));
    }

    public function test_pending_inactive_vendor_appears_in_registration_requests(): void
    {
        $this->actingAsAdmin();
        $vendor = $this->makeVendor();

        $res = $this->getJson('/api/admin/vendor-registrations?per_page=100');

        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($vendor->id, $ids);
    }

    public function test_active_approved_vendor_is_excluded(): void
    {
        $this->actingAsAdmin();
        $vendor = $this->makeVendor(['Approval_Status' => 'approved', 'Status' => 'active', 'Is_Active' => 1]);

        $res = $this->getJson('/api/admin/vendor-registrations?per_page=100');

        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertNotContains($vendor->id, $ids);
    }

    public function test_approval_activates_vendor_and_removes_it_from_requests(): void
    {
        $this->actingAsAdmin();
        $vendor = $this->makeVendor();

        $res = $this->patchJson("/api/vendors/{$vendor->id}/approval", [
            'approval_status' => 'approved',
            'note'            => 'ok from test',
        ]);
        $res->assertOk();

        $vendor->refresh();
        $this->assertSame(1, (int) $vendor->Is_Active);
        $this->assertSame('approved', $vendor->Approval_Status);

        $list = $this->getJson('/api/admin/vendor-registrations?per_page=100');
        $ids = collect($list->json('data'))->pluck('id')->all();
        $this->assertNotContains($vendor->id, $ids);
    }

    public function test_registration_requests_requires_authentication(): void
    {
        $this->getJson('/api/admin/vendor-registrations')->assertUnauthorized();
    }

    public function test_accept_activates_vendor_for_onboarding_and_removes_from_new_queue(): void
    {
        $this->actingAsAdmin();
        $vendor = $this->makeVendor(); // pending + Is_Active=0

        $this->patchJson("/api/vendors/{$vendor->id}/approval", ['approval_status' => 'accepted'])->assertOk();

        $vendor->refresh();
        $this->assertSame(1, (int) $vendor->Is_Active);          // can now log in
        $this->assertSame('accepted', $vendor->Approval_Status); // but not yet approved

        $newQueue = collect($this->getJson('/api/admin/vendor-registrations?status=pending&per_page=100')->json('data'))->pluck('id')->all();
        $this->assertNotContains($vendor->id, $newQueue);
    }

    public function test_under_review_queue_lists_submitted_profiles_only(): void
    {
        $this->actingAsAdmin();
        $submitted = $this->makeVendor(['Approval_Status' => 'under_review', 'Is_Active' => 1]);
        $brandNew  = $this->makeVendor(); // pending + Is_Active=0

        $reviewIds = collect($this->getJson('/api/admin/vendor-registrations?status=under_review&per_page=100')->json('data'))->pluck('id')->all();
        $this->assertContains($submitted->id, $reviewIds);
        $this->assertNotContains($brandNew->id, $reviewIds);

        $pendingIds = collect($this->getJson('/api/admin/vendor-registrations?status=pending&per_page=100')->json('data'))->pluck('id')->all();
        $this->assertContains($brandNew->id, $pendingIds);
        $this->assertNotContains($submitted->id, $pendingIds);
    }
}
