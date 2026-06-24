<?php

namespace App\Http\Controllers;

use Illuminate\Support\Str;
use App\Models\VendorMaster;
use App\Models\VendorDocument;
use App\Models\ConxDatabaseNotification;
use Illuminate\Http\Request;
use App\Helpers\CodeGenerator;
use App\Http\Controllers\Controller;
use App\Http\Requests\VendorStoreRequest;
use App\Http\Requests\VendorUpdateRequest;
use App\Support\Vendors\VendorOnboardingChecklist;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class VendorController extends Controller
{

    public function all()
    {
        return VendorMaster::query()
            ->select('id', 'Vendor_Code', 'Vendor_Name')
            ->orderBy('Vendor_Name')
            ->get();
    }


  public function index(Request $request)
  {
    $perPage = (int) ($request->input('per_page', 10));
    $search  = trim((string) $request->input('search', ''));
    $sortBy  = (string) $request->input('sort_by', 'Id');
    $sortDir = strtolower((string) $request->input('sort_dir', 'desc')) === 'asc' ? 'asc' : 'desc';

    // allowlist to prevent SQL injection via sort_by
    $allowedSort = ['Id','Vendor_Code','Vendor_Name','Email_1','Phone_No','Status','Is_Active','created_at'];
    if (!in_array($sortBy, $allowedSort, true)) $sortBy = 'Id';

    $q = VendorMaster::query()
      ->when($this->documentsReady(), fn ($query) => $query->with('documents'));

    if ($search !== '') {
      $q->where(function ($qq) use ($search) {
        $qq->where('Vendor_Name', 'like', "%{$search}%")
          ->orWhere('Vendor_Code', 'like', "%{$search}%")
          ->orWhere('Email_1', 'like', "%{$search}%")
          ->orWhere('Phone_No', 'like', "%{$search}%")
          ->orWhere('CR_Number', 'like', "%{$search}%");
      });
    }

    $q->orderBy($sortBy, $sortDir);

    $page = $q->paginate($perPage);
    $page->getCollection()->transform(fn (VendorMaster $vendor) => $this->decorateVendor($vendor));

    return $page;
  }

  /**
   * Self-registration pipeline queues (two gates):
   *  - status=pending      → NEW registrations awaiting Accept (pending + Is_Active=0)
   *  - status=under_review → completed profiles the vendor SUBMITTED, awaiting Approve
   * Accept/Approve/Reject are handled by updateApproval().
   */
  public function registrationRequests(Request $request)
  {
    $perPage = (int) ($request->input('per_page', 10));
    $search  = trim((string) $request->input('search', ''));
    $status  = (string) $request->input('status', 'pending');

    $q = VendorMaster::query()
      ->when($this->documentsReady(), fn ($query) => $query->with('documents'));

    if ($status === 'under_review') {
      $q->where('Approval_Status', 'under_review');
    } else {
      $q->where('Approval_Status', 'pending')->where('Is_Active', 0);
    }

    if ($search !== '') {
      $q->where(function ($qq) use ($search) {
        $qq->where('Vendor_Name', 'like', "%{$search}%")
          ->orWhere('Vendor_Code', 'like', "%{$search}%")
          ->orWhere('Email_1', 'like', "%{$search}%")
          ->orWhere('Phone_No', 'like', "%{$search}%")
          ->orWhere('CR_Number', 'like', "%{$search}%");
      });
    }

    if (Schema::hasColumn('Vendors_Master_T', 'Submitted_For_Approval_At')) {
      $q->orderByDesc('Submitted_For_Approval_At');
    }
    $q->orderByDesc('Id');

    $page = $q->paginate($perPage);
    $page->getCollection()->transform(fn (VendorMaster $vendor) => $this->decorateVendor($vendor));

    return $page;
  }

  /**
   * Return a short-lived presigned URL to view/download a vendor document
   * (stored on the private R2 bucket).
   */
  public function documentUrl($id)
  {
    $document = VendorDocument::findOrFail($id);

    if (! $document->File_Path) {
      return response()->json(['message' => 'This document has no file.'], 404);
    }

    try {
      $url = Storage::disk('r2')->temporaryUrl($document->File_Path, now()->addMinutes(10));
    } catch (\Throwable $e) {
      $url = Storage::disk('r2')->url($document->File_Path);
    }

    return response()->json(['url' => $url]);
  }

  public function store(VendorStoreRequest $request)
  {
    $code = CodeGenerator::createCode('VENDOR', 'Vendors_Master_T', 'Vendor_Code');



    $payload = $this->filterVendorPayload($request->validated());
    $payload['Vendor_Code'] = $code;
    $payload['Approval_Status'] = $payload['Approval_Status'] ?? 'pending';
    $payload['Onboarding_Status'] = $payload['Onboarding_Status'] ?? 'incomplete';
    $payload['Payout_Status'] = $payload['Payout_Status'] ?? 'not_configured';

    $vendor = VendorMaster::create($payload);
    $this->syncOnboardingSnapshot($vendor);

    return response()->json([
      'message' => 'Vendor created successfully.',
      'data' => $this->decorateVendor($vendor->fresh()),
    ], 201);
  }

  public function show($id)
  {
    $vendor = VendorMaster::query()
      ->when($this->documentsReady(), fn ($query) => $query->with('documents'))
      ->where('Id', $id)
      ->firstOrFail();

    return response()->json([
      'data' => $this->decorateVendor($vendor),
    ]);
  }

  public function update(VendorUpdateRequest $request, $id)
  {
    $vendor = VendorMaster::where('Id', $id)->firstOrFail();

    $vendor->update($this->filterVendorPayload($request->validated()));
    $this->syncOnboardingSnapshot($vendor->fresh());

    return response()->json([
      'message' => 'Vendor updated successfully.',
      'data' => $this->decorateVendor($vendor->fresh()),
    ]);
  }

  public function upsertDocuments(Request $request, $id)
  {
    if (! $this->documentsReady()) {
      return response()->json(['message' => 'Vendor document table is not migrated yet.'], 409);
    }

    $vendor = VendorMaster::where('Id', $id)->firstOrFail();

    $data = $request->validate([
      'documents' => ['required', 'array', 'min:1'],
      'documents.*.Document_Type' => ['required', 'string', 'max:60'],
      'documents.*.Document_Name' => ['nullable', 'string', 'max:150'],
      'documents.*.File_Path' => ['nullable', 'string', 'max:2000'],
      'documents.*.File_Mime' => ['nullable', 'string', 'max:80'],
      'documents.*.File_Size' => ['nullable', 'integer', 'min:0'],
      'documents.*.Status' => ['nullable', Rule::in(['pending', 'approved', 'rejected', 'expired'])],
      'documents.*.Review_Note' => ['nullable', 'string', 'max:2000'],
    ]);

    foreach ($data['documents'] as $document) {
      VendorDocument::updateOrCreate(
        [
          'Vendor_Id' => $vendor->id,
          'Document_Type' => $document['Document_Type'],
        ],
        [
          'Document_Name' => $document['Document_Name'] ?? null,
          'File_Path' => $document['File_Path'] ?? null,
          'File_Mime' => $document['File_Mime'] ?? null,
          'File_Size' => $document['File_Size'] ?? null,
          'Status' => $document['Status'] ?? 'pending',
          'Review_Note' => $document['Review_Note'] ?? null,
          'Reviewed_By_Admin_Id' => Auth::id(),
          'Reviewed_At' => now(),
        ]
      );
    }

    $this->syncOnboardingSnapshot($vendor->fresh());

    return response()->json([
      'message' => 'Vendor documents updated successfully.',
      'data' => $this->decorateVendor($vendor->fresh()),
    ]);
  }

  public function updateApproval(Request $request, $id)
  {
    $vendor = VendorMaster::where('Id', $id)->firstOrFail();
    $data = $request->validate([
      'approval_status' => ['required', Rule::in(['pending', 'accepted', 'under_review', 'approved', 'rejected'])],
      'note' => ['nullable', 'string', 'max:2000'],
    ]);

    $status = $data['approval_status'];
    $payload = [
      'Approval_Status' => $status,
      'Approval_Note' => $data['note'] ?? null,
    ];

    if ($status === 'accepted') {
      // Gate 1: registration accepted — vendor may now log in to complete the
      // profile (products stay locked until 'approved').
      $payload['Status'] = 'active';
      $payload['Is_Active'] = 1;
    }

    if ($status === 'approved') {
      // Gate 2: full access.
      $payload['Approved_By'] = Auth::id();
      $payload['Approved_At'] = now();
      $payload['Status'] = 'active';
      $payload['Is_Active'] = 1;
    }

    if ($status === 'rejected') {
      $payload['Is_Active'] = 0;
    }

    if ($status === 'under_review' && Schema::hasColumn('Vendors_Master_T', 'Submitted_For_Approval_At')) {
      $payload['Submitted_For_Approval_At'] = $vendor->Submitted_For_Approval_At ?: now();
    }

    $vendor->update($this->filterVendorPayload($payload));
    $this->syncOnboardingSnapshot($vendor->fresh());

    $this->notifyVendorOfApproval($vendor->fresh(), $status);

    return response()->json([
      'message' => 'Vendor approval status updated.',
      'data' => $this->decorateVendor($vendor->fresh()),
    ]);
  }

  /**
   * Notify the vendor (in-portal bell) when the admin accepts / approves / rejects.
   */
  private function notifyVendorOfApproval(VendorMaster $vendor, string $status): void
  {
    $map = [
      'accepted' => ['title' => 'Registration accepted', 'message' => 'Your registration was accepted. Log in and complete your profile to continue.', 'url' => '/profile'],
      'approved' => ['title' => 'Vendor approved', 'message' => 'Your account is approved — you can now access your dashboard and add products.', 'url' => '/dashboard'],
      'rejected' => ['title' => 'Application not approved', 'message' => 'Your vendor application was not approved. Please contact ISC support.', 'url' => '/'],
    ];

    if (! isset($map[$status]) || ! Schema::hasTable('Conx_Notifications_T')) {
      return;
    }

    try {
      ConxDatabaseNotification::create([
        'type' => 'App\\Notifications\\VendorApproval',
        'notifiable_type' => 'App\\Models\\Vendor',
        'notifiable_id' => $vendor->id,
        'data' => $map[$status],
      ]);
    } catch (\Throwable $e) {
      report($e);
    }
  }

  public function destroy($id)
  {
    $vendor = VendorMaster::where('Id', $id)->firstOrFail();
    $vendor->delete();

    return response()->json(['message' => 'Vendor deleted successfully.']);
  }

  private function documentsReady(): bool
  {
    return Schema::hasTable('Vendor_Documents_T');
  }

  /**
   * @param array<string, mixed> $payload
   * @return array<string, mixed>
   */
  private function filterVendorPayload(array $payload): array
  {
    return collect($payload)
      ->filter(fn ($value, string $key) => Schema::hasColumn('Vendors_Master_T', $key))
      ->all();
  }

  private function decorateVendor(?VendorMaster $vendor): ?VendorMaster
  {
    if (!$vendor) {
      return null;
    }

    if ($this->documentsReady() && !$vendor->relationLoaded('documents')) {
      $vendor->load('documents');
    }

    $documents = $this->documentsReady()
      ? $vendor->documents->all()
      : [];

    $checklist = VendorOnboardingChecklist::evaluate($vendor->getAttributes(), $documents);
    $vendor->setAttribute('onboarding_checklist', $checklist);
    $vendor->syncOriginalAttribute('onboarding_checklist');

    return $vendor;
  }

  private function syncOnboardingSnapshot(?VendorMaster $vendor): void
  {
    if (!$vendor || !Schema::hasColumn('Vendors_Master_T', 'Onboarding_Completeness')) {
      return;
    }

    if ($this->documentsReady() && !$vendor->relationLoaded('documents')) {
      $vendor->load('documents');
    }

    $checklist = VendorOnboardingChecklist::evaluate(
      $vendor->getAttributes(),
      $this->documentsReady() ? $vendor->documents->all() : []
    );

    $snapshot = $this->filterVendorPayload([
      'Onboarding_Completeness' => $checklist['completeness_percent'],
      'Onboarding_Status' => $checklist['readiness'],
      'Payout_Status' => ($checklist['items'][3]['complete'] ?? false) ? 'pending_review' : 'not_configured',
    ]);

    if ($snapshot !== []) {
      $vendor->forceFill($snapshot)->save();
    }
  }
}
