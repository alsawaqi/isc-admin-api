<?php

namespace App\Http\Controllers;
use App\Models\Shipper;
use App\Models\HeavyVehicle;
use Illuminate\Http\Request;
use App\Helpers\CodeGenerator;
use App\Models\ShipperContact;
use App\Models\ShipperHeavyRate;
use App\Models\ShipperVolumeRate;
use App\Models\ShipperWeightRate;
 
use Illuminate\Http\JsonResponse;
use App\Models\ShipperDestination;
use Illuminate\Support\Facades\DB;
use App\Models\ShipperShippingRate;
use Illuminate\Support\Facades\Auth;
use App\Http\Resources\ShipperResource;
use Illuminate\Support\Facades\Validator;
use App\Http\Requests\StoreShipperRequest;
use App\Http\Requests\UpdateShipperRequest;
 



class ShipperController extends Controller
{
  public function index(Request $request)
    {
        $q = Shipper::query();

        // simple filters
        if ($s = $request->input('search')) {
            $q->where(function($qq) use ($s) {
                $qq->where('Shippers_Name', 'like', "%$s%")
                   ->orWhere('Shippers_Code', 'like', "%$s%");
            });
        }
        if ($scope = $request->input('scope')) {
            $q->where('Shippers_Scope', $scope);
        }
        if ($type = $request->input('type')) {
            $q->where('Shippers_Type', $type);
        }
        if (!is_null($active = $request->input('active'))) {
            $q->where('Shippers_Is_Active', (bool)$active);
        }

        $q->withCount('contacts')->latest('id');

        return ShipperResource::collection(
            $q->paginate($request->integer('per_page', 20))
        );
    }

    public function store(Request $request): JsonResponse
    {
         

     
        // ---- quick inline validation (minimum required) ----
        $v = Validator::make($request->all(), [

            'shipper' => 'required|array',
            'shipper.Shippers_Name'  => 'required|string|max:255',
            'shipper.Shippers_Scope' => 'required|in:local,international',
            'shipper.Shippers_Type'  => 'required|string|max:30',
            // can be 'weight'|'volume'|'both' or 1|2|3
            'shipper.Shippers_Rate_Mode' => 'required',

            'contacts' => 'sometimes|array',
            'contacts.*.Shippers_Contact_Name' => 'required|string|max:255',

            'destinations' => 'sometimes|array',
            'destinations.*.basic' => 'required|array',

            'vehicles' => 'sometimes|array',
            'vehicles.*.Shippers_Vehicle_Type' => 'required|string|max:255',
            
        ]);

        if ($v->fails()) {
            return response()->json(['message' => 'Validation error', 'errors' => $v->errors()], 422);
        }

        // helper: map numeric rate modes to strings if needed
        $mapRateMode = function($val) {
            if (is_numeric($val)) {
                return match((int)$val) { 1 => 'weight', 2 => 'volume', 3 => 'both', default => 'weight' };
            }
            $val = strtolower((string)$val);
            return in_array($val, ['weight','volume','both'], true) ? $val : 'weight';
        };

        return DB::transaction(function () use ($request, $mapRateMode) {

            // ---- 1) Shipper (generate code) ----
            $shippercode = CodeGenerator::createCode('SHIPR', 'Shippers_Master_T', 'Shippers_Code');

            $s = $request->input('shipper', []);
            $shipper = Shipper::create([
                'Shippers_Code'        => $shippercode,
                'Shippers_Name'        => $s['Shippers_Name'],
                'Shippers_Address'     => $s['Shippers_Address'] ?? null,
                'Shippers_Office_No'   => $s['Shippers_Office_No'] ?? null,
                'Shippers_GSM_No'      => $s['Shippers_GSM_No'] ?? null,
                'Shippers_Email_Address'            => $s['Shippers_Email_Address'] ?? null,
                'Shippers_Official_Website_Address' => $s['Shippers_Official_Website_Address'] ?? null,
                'Shippers_GPS_Location'             => $s['Shippers_GPS_Location'] ?? null,
                'Shippers_Scope'      => $s['Shippers_Scope'],
                'Shippers_Type'       => $s['Shippers_Type'],
                'Shippers_Rate_Mode'  => $mapRateMode($s['Shippers_Rate_Mode']),
                'Shippers_Is_Active'  => (bool)($s['Shippers_Is_Active'] ?? true),
                'Shippers_Meta'       => $s['Shippers_Meta'] ?? null,
            ]);

            // ---- 2) Contacts ----
            // (a) Array of contacts
            $contacts = $request->input('contacts', []);
            foreach ($contacts as $c) {
                ShipperContact::create([
                    'Shippers_Id'                      => $shipper->id,
                    'Shippers_Contact_Name'            => $c['Shippers_Contact_Name'],
                    'Shippers_Contact_Position'        => $c['Shippers_Contact_Position'] ?? null,
                    'Shippers_Contact_Office_No'       => $c['Shippers_Contact_Office_No'] ?? null,
                    'Shippers_Contact_GSM_No'          => $c['Shippers_Contact_GSM_No'] ?? null,
                    'Shippers_Contact_Email_Address'   => $c['Shippers_Contact_Email_Address'] ?? null,
                    'Shippers_Is_Primary'              => (bool)($c['Shippers_Is_Primary'] ?? false),
                ]);
            }
            // (b) Optional single quick contact using your field names (if you send them at top-level)
            if ($request->filled('Shippers_Contact_Name')) {
                ShipperContact::create([
                    'Shippers_Id'                    => $shipper->id,
                    'Shippers_Contact_Name'          => $request->string('Shippers_Contact_Name'),
                    'Shippers_Contact_Position'      => $request->input('Shippers_Contact_Position'),
                    'Shippers_Contact_Office_No'     => $request->input('Shippers_Contact_Office_No'),
                    'Shippers_Contact_GSM_No'        => $request->input('Shippers_Contact_GSM_No'),
                    'Shippers_Contact_Email_Address' => $request->input('Shippers_Contact_Email_Address'),
                    'Shippers_Is_Primary'            => (bool)$request->input('Shippers_Is_Primary', false),
                ]);
            }

            // ---- 3) Destinations (+ flags + bands + heavy rates) ----
            $createdDestIds = [];
            $destBlocks = $request->input('destinations', []);
            foreach ($destBlocks as $destIdx => $block) {
                $basic = $block['basic'] ?? [];
                $dest = ShipperDestination::create([
                    'Shippers_Id'                                 => $shipper->id,
                    'Shippers_Destination_Country'                => $basic['Shippers_Destination_Country'] ?? null,
                    'Shippers_Destination_Region'                 => $basic['Shippers_Destination_Region'] ?? null,
                    'Shippers_Destination_District'               => $basic['Shippers_Destination_District'] ?? null,
                    'Shippers_Destination_Rate_Applicability'     => $basic['Shippers_Destination_Rate_Applicability'] ?? null,
                    'Shippers_Destination_Country_Preference'     => $basic['Shippers_Destination_Country_Preference'] ?? null,
                    'Shippers_Destination_Region_Preference'      => $basic['Shippers_Destination_Region_Preference'] ?? null,
                    'Shippers_Destination_District_Preference'    => $basic['Shippers_Destination_District_Preference'] ?? null,
                ]);
                $createdDestIds[$destIdx] = $dest->id;

                // flags row
                $flags = $block['flags'] ?? [];
                ShipperShippingRate::create([
                    'Shippers_Id'                         => $shipper->id,
                    'Shippers_Destination_Id'             => $dest->id,
                    'Shippers_Destination_Rate_Volume'    => (bool)($flags['Shippers_Destination_Rate_Volume'] ?? false),
                    'Shippers_Destination_Rate_Weight'    => (bool)($flags['Shippers_Destination_Rate_Weight'] ?? false),
                    'Shippers_Destination_Rate_Applicable'=> (bool)($flags['Shippers_Destination_Rate_Applicable'] ?? true),
                ]);

                // volume bands
                foreach (($block['volume_bands'] ?? []) as $v) {
                    ShipperVolumeRate::create([
                        'Shippers_Id'                          => $shipper->id,
                        'Shippers_Destination_Id'              => $dest->id,
                        'Shippers_Standard_Shipping_Volume_Size'=> $v['Shippers_Standard_Shipping_Volume_Size'] ?? null,
                        'Shippers_Standard_Shipping_Volume_Rate'=> (float)($v['Shippers_Standard_Shipping_Volume_Rate'] ?? 0),
                        'Shippers_Currency'                      => $v['Shippers_Currency'] ?? 'OMR',
                        'Shippers_Min_Volume_Cbm'                => $v['Shippers_Min_Volume_Cbm'] ?? null,
                        'Shippers_Max_Volume_Cbm'                => $v['Shippers_Max_Volume_Cbm'] ?? null,
                        'Shippers_Base_Fee'                      => $v['Shippers_Base_Fee'] ?? null,
                        'Shippers_Per_Cbm_Fee'                   => $v['Shippers_Per_Cbm_Fee'] ?? null,
                        'Shippers_Flat_Fee'                      => $v['Shippers_Flat_Fee'] ?? null,
                    ]);
                }

                // weight bands
                foreach (($block['weight_bands'] ?? []) as $w) {
                    ShipperWeightRate::create([
                        'Shippers_Id'                           => $shipper->id,
                        'Shippers_Destination_Id'               => $dest->id,
                        'Shippers_Standard_Shipping_Weight_Size'=> $w['Shippers_Standard_Shipping_Weight_Size'] ?? null,
                        'Shippers_Standard_Shipping_Weight_Rate'=> (float)($w['Shippers_Standard_Shipping_Weight_Rate'] ?? 0),
                        'Shippers_Currency'                       => $w['Shippers_Currency'] ?? 'OMR',
                        'Shippers_Min_Weight_Kg'                  => $w['Shippers_Min_Weight_Kg'] ?? null,
                        'Shippers_Max_Weight_Kg'                  => $w['Shippers_Max_Weight_Kg'] ?? null,
                        'Shippers_Base_Fee'                       => $w['Shippers_Base_Fee'] ?? null,
                        'Shippers_Per_Kg_Fee'                     => $w['Shippers_Per_Kg_Fee'] ?? null,
                        'Shippers_Flat_Fee'                       => $w['Shippers_Flat_Fee'] ?? null,
                    ]);
                }

                // temporarily carry heavy rates; we'll bind to vehicles after we create them
                $destBlocks[$destIdx]['__heavy_rates_tmp'] = $block['heavy_rates'] ?? [];
            }

            // ---- 4) Vehicles ----
            $vehicleIdByType = [];
            foreach ($request->input('vehicles', []) as $veh) {
                $created = HeavyVehicle::create([
                    'Shippers_Id'                 => $shipper->id,
                    'Shippers_Vehicle_Type'       => $veh['Shippers_Vehicle_Type'],
                    'Shippers_Vehicle_Capacity_Ton'=> $veh['Shippers_Vehicle_Capacity_Ton'] ?? null,
                ]);
                $vehicleIdByType[$created->Shippers_Vehicle_Type] = $created->id;
            }

            // ---- 5) Heavy rates (bind now that we have vehicle ids) ----
            foreach ($destBlocks as $idx => $blk) {
                $destId = $createdDestIds[$idx] ?? null;
                if (!$destId) continue;

                foreach (($blk['__heavy_rates_tmp'] ?? []) as $hr) {
                    $vehType = $hr['vehicle_type'] ?? null;
                    if (!$vehType || !isset($vehicleIdByType[$vehType])) continue;

                    ShipperHeavyRate::create([
                        'Shippers_Id'             => $shipper->id,
                        'Shippers_Destination_Id' => $destId,
                        'Shippers_Vehicle_Id'     => $vehicleIdByType[$vehType],
                        'Shippers_Flat_Rate'      => $hr['Shippers_Flat_Rate'] ?? null,
                        'Shippers_Hourly_Rate'    => $hr['Shippers_Hourly_Rate'] ?? null,
                        'Shippers_Min_Hours'      => $hr['Shippers_Min_Hours'] ?? 0,
                        'Shippers_Currency'       => $hr['Shippers_Currency'] ?? 'OMR',
                    ]);
                }
            }

            return response()->json([
                'id'          => $shipper->id,
                'message'     => 'Shipper and related data created successfully (single endpoint).',
            ], 201);
        });
   
         
    }

    public function show(Shipper $shipper)
    {
        return new ShipperResource($shipper->loadCount('contacts'));
    }

    public function update(UpdateShipperRequest $request, Shipper $shipper)
    {
        $shipper->update($request->validated());
        return new ShipperResource($shipper->loadCount('contacts'));
    }

    public function destroy(Shipper $shipper)
    {
        $shipper->delete();
        return response()->noContent();
    }

    public function toggle(Shipper $shipper)
    {
        $shipper->Shippers_Is_Active = ! $shipper->Shippers_Is_Active;
        $shipper->save();
        return new ShipperResource($shipper->loadCount('contacts'));
    }
}
