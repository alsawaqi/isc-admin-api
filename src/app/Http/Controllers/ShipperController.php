<?php

namespace App\Http\Controllers;
use App\Models\Shipper;
use App\Models\HeavyVehicle;
use Illuminate\Http\Request;
use App\Helpers\CodeGenerator;
use App\Models\ShipperBoxRate;
use App\Models\ShipperBoxSize;
use App\Models\ShipperContact;
use App\Models\ShipperHeavyRate;
 
use App\Models\ShipperVolumeRate;
use App\Models\ShipperWeightRate;
use Illuminate\Http\JsonResponse;
use App\Models\ShipperDestination;
use Illuminate\Support\Facades\DB;
use App\Models\ShipperShippingRate;
use Illuminate\Support\Facades\Auth;
use App\Models\ShipperVolumetricRule;
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
            $q->where(function ($qq) use ($s) {
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

        //return response()->json($request->all());

        // ---- quick inline validation (minimum required) ----
        $v = Validator::make($request->all(), [
            'shipper' => 'required|array',
            'shipper.Shippers_Name'       => 'required|string|max:255',
            'shipper.Shippers_Scope'      => 'required|in:local,international',
            'shipper.Shippers_Type'       => 'required|string|max:30',
            // can be 'weight'|'volume'|'both' or 1|2|3
            'shipper.Shippers_Rate_Mode'  => 'required',

            'contacts'                                  => 'sometimes|array',
            // If a contact item exists, require its name; empty rows will be ignored below
            'contacts.*.Shippers_Contact_Name'          => 'required_with:contacts.*|string|max:255',
            'contacts.*.Shippers_Contact_Position'      => 'nullable|string|max:255',
            'contacts.*.Shippers_Contact_Office_No'     => 'nullable|string|max:255',
            'contacts.*.Shippers_Contact_GSM_No'        => 'nullable|string|max:255',
            'contacts.*.Shippers_Contact_Email_Address' => 'nullable|email|max:255',
            'contacts.*.Shippers_Is_Primary'            => 'nullable|boolean',

            'destinations.*.basic.Shippers_Destination_Country_Id'  => 'nullable|integer',
            'destinations.*.basic.Shippers_Destination_Region_Id'   => 'nullable|integer',
            'destinations.*.basic.Shippers_Destination_District_Id' => 'nullable|integer',
            'destinations.*.flags'         => 'nullable|array',
            'destinations.*.volume_bands'  => 'nullable|array',
            'destinations.*.weight_bands'  => 'nullable|array',
            'destinations.*.heavy_rates'   => 'nullable|array',


            'destinations.*.rate_mode' => 'nullable|in:weight,volume',

            // volumetric rule (only when rate_mode = volumetric)
            'destinations.*.volumetric_rule' => 'nullable|array',
            'destinations.*.volumetric_rule.enabled' => 'nullable|boolean',
            'destinations.*.volumetric_rule.divisor' => 'nullable|numeric|min:1',
            'destinations.*.volumetric_rule.maxL_cm' => 'nullable|numeric|min:0',
            'destinations.*.volumetric_rule.maxW_cm' => 'nullable|numeric|min:0',
            'destinations.*.volumetric_rule.maxH_cm' => 'nullable|numeric|min:0',
            'destinations.*.volumetric_rule.note' => 'nullable|string|max:255',

            'vehicles'                                 => 'sometimes|array',
            'vehicles.*.Shippers_Vehicle_Type'         => 'required|string|max:255',
            'vehicles.*.Shippers_Vehicle_Capacity_Ton' => 'nullable|numeric|min:0',

            // NEW: standard boxes
            'standard_boxes'                 => 'sometimes|array',
            'standard_boxes.*.Box_Code'      => 'nullable|string|max:50',
            'standard_boxes.*.Box_Label'     => 'nullable|string|max:255',
            'standard_boxes.*.Length_cm'     => 'nullable|numeric|min:0',
            'standard_boxes.*.Width_cm'      => 'nullable|numeric|min:0',
            'standard_boxes.*.Height_cm'     => 'nullable|numeric|min:0',
            'standard_boxes.*.Max_Weight_Kg' => 'nullable|numeric|min:0',
            'standard_boxes.*.Flat_Rate_Price' => 'nullable|numeric|min:0',
            'standard_boxes.*.Currency'      => 'nullable|string|size:3',
            'standard_boxes.*.Notes'         => 'nullable|string|max:1000',
        ]);

        if ($v->fails()) {
            return response()->json(['message' => 'Validation error', 'errors' => $v->errors()], 422);
        }

        // helper: map numeric rate modes to strings if needed
        $mapRateMode = function ($val) {
            if (is_numeric($val)) {
                return match ((int)$val) {
                    1 => 'weight',
                    2 => 'volume',
                    3 => 'both',
                    default => 'weight'
                };
            }
            $val = strtolower((string)$val);
            return in_array($val, ['weight', 'volume', 'both'], true) ? $val : 'weight';
        };

        return DB::transaction(function () use ($request, $mapRateMode) {

            // ---- 1) Shipper (generate code) ----
            $shippercode = CodeGenerator::createCode('SHIPR', 'Shippers_Master_T', 'Shippers_Code');

            $s = $request->input('shipper', []);
            $shipper = Shipper::create([
                'Shippers_Code'                     => $shippercode,
                'Shippers_Name'                     => $s['Shippers_Name'],
                'Shippers_Address'                  => $s['Shippers_Address'] ?? null,
                'Shippers_Office_No'                => $s['Shippers_Office_No'] ?? null,
                'Shippers_GSM_No'                   => $s['Shippers_GSM_No'] ?? null,
                'Shippers_Email_Address'            => $s['Shippers_Email_Address'] ?? null,
                'Shippers_Official_Website_Address' => $s['Shippers_Official_Website_Address'] ?? null,
                'Shippers_GPS_Location'             => $s['Shippers_GPS_Location'] ?? null,
                'Shippers_Scope'                    => $s['Shippers_Scope'],
                'Shippers_Type'                     => $s['Shippers_Type'],
                'Shippers_Rate_Mode'                => $mapRateMode($s['Shippers_Rate_Mode']),
                'Shippers_Is_Active'                => (bool)($s['Shippers_Is_Active'] ?? true),
                'Shippers_Meta'                     => $s['Shippers_Meta'] ?? null,
            ]);

            // ---- 2) Contacts ----
            $contacts = $request->input('contacts', []);
            foreach ($contacts as $c) {
                // ignore completely empty rows
                if (!isset($c['Shippers_Contact_Name']) || trim((string)$c['Shippers_Contact_Name']) === '') {
                    continue;
                }
                ShipperContact::create([
                    'Shippers_Id'                      => $shipper->id,
                    'Contact_Department_Id'            => $c['Contact_Department_Id'] ?? null,
                    'Shippers_Contact_Name'            => $c['Shippers_Contact_Name'],
                    'Shippers_Contact_Position'        => $c['Shippers_Contact_Position'] ?? null,
                    'Shippers_Contact_Office_No'       => $c['Shippers_Contact_Office_No'] ?? null,
                    'Shippers_Contact_GSM_No'          => $c['Shippers_Contact_GSM_No'] ?? null,
                    'Shippers_Contact_Email_Address'   => $c['Shippers_Contact_Email_Address'] ?? null,
                    'Shippers_Is_Primary'              => (bool)($c['Shippers_Is_Primary'] ?? false),
                ]);
            }
            // (b) Optional single quick contact at top-level
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

            $normalizeMode = function ($m) {
                $m = strtolower((string)$m);
                return in_array($m, ['volume', 'weight'], true) ? $m : 'weight';
            };

            foreach ($destBlocks as $destIdx => $block) {
                $basic = $block['basic'] ?? [];
                $mode  = $normalizeMode($block['rate_mode'] ?? null);

                $dest = ShipperDestination::create([
                    'Shippers_Id'                              => $shipper->id,

                    // NEW: persist IDs
                    'Shippers_Destination_Country_Id'          => $basic['Shippers_Destination_Country_Id']  ?? null,
                    'Shippers_Destination_Region_Id'           => $basic['Shippers_Destination_Region_Id']   ?? null,
                    'Shippers_Destination_District_Id'         => $basic['Shippers_Destination_District_Id'] ?? null,

                   

                    'Shippers_Destination_Rate_Applicability'  => $basic['Shippers_Destination_Rate_Applicability'] ?? null,
                    'Shippers_Destination_Country_Preference'  => $basic['Shippers_Destination_Country_Preference'] ?? null,
                    'Shippers_Destination_Region_Preference'   => $basic['Shippers_Destination_Region_Preference'] ?? null,
                    'Shippers_Destination_District_Preference' => $basic['Shippers_Destination_District_Preference'] ?? null,
                ]);

                $createdDestIds[$destIdx] = $dest->id;

                // Flags consistent with mode
                $flags = $block['flags'] ?? [];
                ShipperShippingRate::create([
                    'Shippers_Id'                          => $shipper->id,
                    'Shippers_Destination_Id'              => $dest->id,
                    'Shippers_Destination_Rate_Volume'     => ($mode === 'volume'),
                    'Shippers_Destination_Rate_Weight'     => ($mode === 'weight'),
                    'Shippers_Destination_Rate_Applicable' => (bool)($flags['Shippers_Destination_Rate_Applicable'] ?? true),
                    'Shippers_Destination_Rate_Box'        => (bool)($flags['Shippers_Destination_Rate_Box'] ?? false),
                ]);

                if (!empty($block['volumetric_rule'])) {
                    $vr = $block['volumetric_rule'];
                    ShipperVolumetricRule::updateOrCreate(
                        ['Shippers_Id' => $shipper->id, 'Shippers_Destination_Id' => $dest->id],
                        [
                            'Enabled'  => (bool)($vr['enabled'] ?? true),
                            'Divisor'  => (float)($vr['divisor'] ?? 4000),
                            'Max_L_cm' => isset($vr['maxL_cm']) ? (float)$vr['maxL_cm'] : null,
                            'Max_W_cm' => isset($vr['maxW_cm']) ? (float)$vr['maxW_cm'] : null,
                            'Max_H_cm' => isset($vr['maxH_cm']) ? (float)$vr['maxH_cm'] : null,
                            'Note'     => $vr['note'] ?? null,
                        ]
                    );
                }

                // Save bands based on mode
                if ($mode === 'volume') {
                    foreach (($block['volume_bands'] ?? []) as $v) {
                        ShipperVolumeRate::create([
                            'Shippers_Id'                            => $shipper->id,
                            'Shippers_Destination_Id'                => $dest->id,
                            'Shippers_Standard_Shipping_Volume_Size' => $v['Shippers_Standard_Shipping_Volume_Size'] ?? null,
                            'Shippers_Standard_Shipping_Volume_Rate' => (float)($v['Shippers_Standard_Shipping_Volume_Rate'] ?? 0),
                            'Shippers_Currency'                      => $v['Shippers_Currency'] ?? 'OMR',
                            'Shippers_Min_Volume_Cbm'                => $v['Shippers_Min_Volume_Cbm'] ?? null,
                            'Shippers_Max_Volume_Cbm'                => $v['Shippers_Max_Volume_Cbm'] ?? null,
                            'Shippers_Base_Fee'                      => $v['Shippers_Base_Fee'] ?? null,
                            'Shippers_Per_Cbm_Fee'                   => $v['Shippers_Per_Cbm_Fee'] ?? null,
                            'Shippers_Flat_Fee'                      => $v['Shippers_Flat_Fee'] ?? null,
                        ]);
                    }
                } else { // weight
                    foreach (($block['weight_bands'] ?? []) as $w) {
                        ShipperWeightRate::create([
                            'Shippers_Id'                             => $shipper->id,
                            'Shippers_Destination_Id'                 => $dest->id,
                            'Shippers_Standard_Shipping_Weight_Size'  => $w['Shippers_Standard_Shipping_Weight_Size'] ?? null,
                            'Shippers_Standard_Shipping_Weight_Rate'  => (float)($w['Shippers_Standard_Shipping_Weight_Rate'] ?? 0),
                            'Shippers_Currency'                       => $w['Shippers_Currency'] ?? 'OMR',
                            'Shippers_Min_Weight_Kg'                  => $w['Shippers_Min_Weight_Kg'] ?? null,
                            'Shippers_Max_Weight_Kg'                  => $w['Shippers_Max_Weight_Kg'] ?? null,
                            'Shippers_Base_Fee'                       => $w['Shippers_Base_Fee'] ?? null,
                            'Shippers_Per_Kg_Fee'                     => $w['Shippers_Per_Kg_Fee'] ?? null,
                            'Shippers_Flat_Fee'                       => $w['Shippers_Flat_Fee'] ?? null,
                        ]);
                    }
                }
                // carry heavy rates for later
                $destBlocks[$destIdx]['__heavy_rates_tmp'] = $block['heavy_rates'] ?? [];
            }



            // ---- 4) Vehicles ----
            $vehicleIdByType = [];
            foreach ($request->input('vehicles', []) as $veh) {
                $created = HeavyVehicle::create([
                    'Shippers_Id'                  => $shipper->id,
                    'Shippers_Vehicle_Type'        => $veh['Shippers_Vehicle_Type'],
                    'Shippers_Vehicle_Capacity_Ton' => $veh['Shippers_Vehicle_Capacity_Ton'] ?? null,
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


            // ---- 6) Box Sizes + Default Box Rates per Destination ----
            $boxSizesInput = $request->input('standard_boxes', []);
            $boxSizeIdByCode = [];                                     // <<< define it

            foreach ($boxSizesInput as $b) {
                $hasAny = array_filter($b, fn($v) => !is_null($v) && $v !== '');
                if (!$hasAny) continue;
                $code  = trim((string)($b['Box_Code']  ?? ''));
                $label = trim((string)($b['Box_Label'] ?? ''));
                if ($code === '' || $label === '') continue;

                $L = isset($b['Length_cm']) ? (float)$b['Length_cm'] : 0.0;
                $W = isset($b['Width_cm'])  ? (float)$b['Width_cm']  : 0.0;
                $H = isset($b['Height_cm']) ? (float)$b['Height_cm'] : 0.0;
                $volCbm = ($L > 0 && $W > 0 && $H > 0) ? ($L * $W * $H) / 1_000_000 : null;

                $size = ShipperBoxSize::updateOrCreate(
                    ['Shippers_Id' => $shipper->id, 'Shippers_Box_Code' => $code],
                    [
                        'Shippers_Box_Label'         => $label,
                        'Shippers_Box_Length_Cm'     => $L,
                        'Shippers_Box_Width_Cm'      => $W,
                        'Shippers_Box_Height_Cm'     => $H,
                        'Shispers_Box_Max_Weight_Kg' => isset($b['Max_Weight_Kg']) ? (float)$b['Max_Weight_Kg'] : null,
                        'Shippers_Box_Volume_Cbm'    => $volCbm,
                        'Shippers_Box_Notes'         => $b['Notes'] ?? null,
                        'Shippers_Box_Is_Active'     => true,
                    ]
                );

                $boxSizeIdByCode[$code] = $size->id;                   // <<< capture id
            }

            // now you can safely use $boxSizeIdByCode[...] below
            foreach ($destBlocks as $idx => $blk) {
                $destId = $createdDestIds[$idx] ?? null;
                if (!$destId) continue;

                $flags = $blk['flags'] ?? [];
                $boxEnabled = (bool)($flags['Shippers_Destination_Rate_Box'] ?? false);
                if (!$boxEnabled) continue;

                foreach ($boxSizesInput as $b) {
                    $code = trim((string)($b['Box_Code'] ?? ''));
                    if ($code === '' || !isset($boxSizeIdByCode[$code])) continue;
                    if (!isset($b['Flat_Rate_Price'])) continue;

                    ShipperBoxRate::create([
                        'Shippers_Id'               => $shipper->id,
                        'Shippers_Destination_Id'   => $destId,
                        'Shippers_Box_Size_Id'      => $boxSizeIdByCode[$code],
                        'Shippers_Flat_Box_Rate'    => (float)$b['Flat_Rate_Price'],
                        'Shippers_Base_Fee'         => isset($b['Base_Fee']) ? (float)$b['Base_Fee'] : null,
                        'Shippers_Currency'         => $b['Currency'] ?? 'OMR',
                        'Shippers_Max_Weight_Kg'    => isset($b['Max_Weight_Kg']) ? (float)$b['Max_Weight_Kg'] : null,
                    ]);
                }
            }

            return response()->json([
                'id'      => $shipper->id,
                'message' => 'Shipper and related data created successfully (single endpoint).',
            ], 201);
        });
    }


    public function show(int $id): JsonResponse
    {
        $shipper = Shipper::findOrFail($id);

        // Eager load everything we need
        $contacts = ShipperContact::where('Shippers_Id', $id)->get();

        $destinations = ShipperDestination::where('Shippers_Id', $id)->get();
        $flagsByDest = ShipperShippingRate::where('Shippers_Id', $id)
            ->get()->keyBy('Shippers_Destination_Id');

        $volBandsByDest = ShipperVolumeRate::where('Shippers_Id', $id)->get()
            ->groupBy('Shippers_Destination_Id');
        $wtBandsByDest  = ShipperWeightRate::where('Shippers_Id', $id)->get()
            ->groupBy('Shippers_Destination_Id');

        $vehicles = HeavyVehicle::where('Shippers_Id', $id)->get();
        $heavyRates = ShipperHeavyRate::where('Shippers_Id', $id)->get();

        // Box sizes (admin reference) – default pricing is applied per destination server-side on create,
        // for edit we just let admin edit sizes here. (You can add per-destination box rate UI later.)
        $boxSizes = ShipperBoxSize::where('Shippers_Id', $id)->get();

        // Map heavyRates into wizard-friendly rows: destinationIndex is resolved on the client
        $destList = $destinations->values(); // for stable index use client side
        $heavyRatesFlat = $heavyRates->map(function($r){
            return [
                'id'                   => $r->id,
                'destination_id'       => $r->Shippers_Destination_Id,
                'vehicle_id'           => $r->Shippers_Vehicle_Id,
                'Shippers_Flat_Rate'   => $r->Shippers_Flat_Rate,
                'Shippers_Hourly_Rate' => $r->Shippers_Hourly_Rate,
                'Shippers_Min_Hours'   => $r->Shippers_Min_Hours,
                'Shippers_Currency'    => $r->Shippers_Currency,
            ];
        })->values();

        return response()->json([
            'shipper' => [
                'id'                          => $shipper->id,
                'Shippers_Name'               => $shipper->Shippers_Name,
                'Shippers_Address'            => $shipper->Shippers_Address,
                'Shippers_Office_No'          => $shipper->Shippers_Office_No,
                'Shippers_GSM_No'             => $shipper->Shippers_GSM_No,
                'Shippers_Email_Address'      => $shipper->Shippers_Email_Address,
                'Shippers_Official_Website_Address' => $shipper->Shippers_Official_Website_Address,
                'Shippers_GPS_Location'       => $shipper->Shippers_GPS_Location,
                'Shippers_Scope'              => $shipper->Shippers_Scope,
                'Shippers_Type'               => $shipper->Shippers_Type,
                'Shippers_Rate_Mode'          => $shipper->Shippers_Rate_Mode,
                'Shippers_Is_Active'          => (bool)$shipper->Shippers_Is_Active,
            ],
            'contacts' => $contacts->map(fn($c)=>[
                'id'                                   => $c->id,
                'Shippers_Contact_Name'                => $c->Shippers_Contact_Name,
                'Shippers_Contact_Position'            => $c->Shippers_Contact_Position,
                'Shippers_Contact_Office_No'           => $c->Shippers_Contact_Office_No,
                'Shippers_Contact_GSM_No'              => $c->Shippers_Contact_GSM_No,
                'Shippers_Contact_Email_Address'       => $c->Shippers_Contact_Email_Address,
                'Shippers_Is_Primary'                  => (bool)$c->Shippers_Is_Primary,
            ])->values(),

            'destinations' => $destList->map(function($d) use ($flagsByDest, $volBandsByDest, $wtBandsByDest){
                $flags = $flagsByDest->get($d->id);
                return [
                    'id'                                  => $d->id,
                    'Shippers_Destination_Country'        => $d->Shippers_Destination_Country,
                    'Shippers_Destination_Region'         => $d->Shippers_Destination_Region,
                    'Shippers_Destination_District'       => $d->Shippers_Destination_District,
                    'Shippers_Destination_Rate_Applicability' => $d->Shippers_Destination_Rate_Applicability,
                    'Shippers_Destination_Country_Preference' => $d->Shippers_Destination_Country_Preference,
                    'Shippers_Destination_Region_Preference'  => $d->Shippers_Destination_Region_Preference,
                    'Shippers_Destination_District_Preference'=> $d->Shippers_Destination_District_Preference,
                    // flags used by wizard
                    'Shippers_Destination_Rate_Volume'    => (bool)optional($flags)->Shippers_Destination_Rate_Volume,
                    'Shippers_Destination_Rate_Weight'    => (bool)optional($flags)->Shippers_Destination_Rate_Weight,
                    'Shippers_Destination_Rate_Applicable'=> (bool)optional($flags)->Shippers_Destination_Rate_Applicable,
                    'Shippers_Destination_Rate_Box'       => (bool)optional($flags)->Shippers_Destination_Rate_Box,
                    // bands (wizard expects arrays by index; Nuxt will align them into rates[])
                    'volume_bands' => ($volBandsByDest[$d->id] ?? collect())->map(fn($v)=>[
                        'id'                                      => $v->id,
                        'Shippers_Standard_Shipping_Volume_Size'  => $v->Shippers_Standard_Shipping_Volume_Size,
                        'Shippers_Standard_Shipping_Volume_Rate'  => $v->Shippers_Standard_Shipping_Volume_Rate,
                        'Shippers_Currency'                       => $v->Shippers_Currency,
                        'Shippers_Min_Volume_Cbm'                 => $v->Shippers_Min_Volume_Cbm,
                        'Shippers_Max_Volume_Cbm'                 => $v->Shippers_Max_Volume_Cbm,
                        'Shippers_Base_Fee'                       => $v->Shippers_Base_Fee,
                        'Shippers_Per_Cbm_Fee'                    => $v->Shippers_Per_Cbm_Fee,
                        'Shippers_Flat_Fee'                       => $v->Shippers_Flat_Fee,
                    ])->values(),
                    'weight_bands' => ($wtBandsByDest[$d->id] ?? collect())->map(fn($w)=>[
                        'id'                                      => $w->id,
                        'Shippers_Standard_Shipping_Weight_Size'  => $w->Shippers_Standard_Shipping_Weight_Size,
                        'Shippers_Standard_Shipping_Weight_Rate'  => $w->Shippers_Standard_Shipping_Weight_Rate,
                        'Shippers_Currency'                       => $w->Shippers_Currency,
                        'Shippers_Min_Weight_Kg'                  => $w->Shippers_Min_Weight_Kg,
                        'Shippers_Max_Weight_Kg'                  => $w->Shippers_Max_Weight_Kg,
                        'Shippers_Base_Fee'                       => $w->Shippers_Base_Fee,
                        'Shippers_Per_Kg_Fee'                     => $w->Shippers_Per_Kg_Fee,
                        'Shippers_Flat_Fee'                       => $w->Shippers_Flat_Fee,
                    ])->values(),
                ];
            })->values(),

            'vehicles' => $vehicles->map(fn($v)=>[
                'id'                            => $v->id,
                'Shippers_Vehicle_Type'         => $v->Shippers_Vehicle_Type,
                'Shippers_Vehicle_Capacity_Ton' => $v->Shippers_Vehicle_Capacity_Ton,
            ])->values(),

            // heavy rates returned separately (client maps by destination id → index)
            'heavy_rates' => $heavyRatesFlat,

            // boxes (admin-only sizes)
            'standard_boxes' => $boxSizes->map(fn($b)=>[
                'id'               => $b->id,
                'Box_Code'         => $b->Shippers_Box_Code,
                'Box_Label'        => $b->Shippers_Box_Label,
                'Length_cm'        => $b->Shippers_Box_Length_Cm,
                'Width_cm'         => $b->Shippers_Box_Width_Cm,
                'Height_cm'        => $b->Shippers_Box_Height_Cm,
                'Max_Weight_Kg'    => $b->Shippers_Box_Max_Weight_Kg,
                // no price here; destination rates are separate
                'Currency'         => 'OMR',
                'Notes'            => null,
            ])->values(),
        ]);
    }


    public function update(Request $request, int $id): JsonResponse
    {
        $shipper = Shipper::findOrFail($id);

        // Reuse your create() rules, plus box flag + standard_boxes rules we added earlier
        $v = Validator::make($request->all(), [
            'shipper' => 'required|array',
            'shipper.Shippers_Name'       => 'required|string|max:255',
            'shipper.Shippers_Scope'      => 'required|in:local,international',
            'shipper.Shippers_Type'       => 'required|string|max:30',
            'shipper.Shippers_Rate_Mode'  => 'required',
            'contacts' => 'sometimes|array',
            'contacts.*.Shippers_Contact_Name' => 'required_with:contacts.*|string|max:255',
            'destinations' => 'sometimes|array',
            'destinations.*.basic' => 'required|array',
            'destinations.*.flags.Shippers_Destination_Rate_Box' => 'nullable|boolean',
            'vehicles' => 'sometimes|array',
            'vehicles.*.Shippers_Vehicle_Type' => 'required|string|max:255',
            'standard_boxes' => 'sometimes|array',
            'standard_boxes.*.Box_Code'  => 'nullable|string|max:50',
            'standard_boxes.*.Box_Label' => 'nullable|string|max:100',
            'standard_boxes.*.Length_cm' => 'nullable|numeric|min:0',
            'standard_boxes.*.Width_cm'  => 'nullable|numeric|min:0',
            'standard_boxes.*.Height_cm' => 'nullable|numeric|min:0',
            'standard_boxes.*.Max_Weight_Kg'   => 'nullable|numeric|min:0',
            'standard_boxes.*.Flat_Rate_Price' => 'nullable|numeric|min:0',
            'standard_boxes.*.Currency'        => 'nullable|string|size:3',
            'standard_boxes.*.Notes'           => 'nullable|string|max:1000',
        ]);

        if ($v->fails()) {
            return response()->json(['message' => 'Validation error', 'errors' => $v->errors()], 422);
        }

        $mapRateMode = function ($val) {
            if (is_numeric($val)) {
                return match ((int)$val) {
                    1 => 'weight',
                    2 => 'volume',
                    3 => 'both',
                    default => 'weight'
                };
            }
            $val = strtolower((string)$val);
            return in_array($val, ['weight', 'volume', 'both'], true) ? $val : 'weight';
        };

        return DB::transaction(function () use ($request, $shipper, $mapRateMode) {

            // 1) Update master
            $s = $request->input('shipper', []);
            $shipper->update([
                'Shippers_Name'                     => $s['Shippers_Name'],
                'Shippers_Address'                  => $s['Shippers_Address'] ?? null,
                'Shippers_Office_No'                => $s['Shippers_Office_No'] ?? null,
                'Shippers_GSM_No'                   => $s['Shippers_GSM_No'] ?? null,
                'Shippers_Email_Address'            => $s['Shippers_Email_Address'] ?? null,
                'Shippers_Official_Website_Address' => $s['Shippers_Official_Website_Address'] ?? null,
                'Shippers_GPS_Location'             => $s['Shippers_GPS_Location'] ?? null,
                'Shippers_Scope'                    => $s['Shippers_Scope'],
                'Shippers_Type'                     => $s['Shippers_Type'],
                'Shippers_Rate_Mode'                => $mapRateMode($s['Shippers_Rate_Mode']),
                'Shippers_Is_Active'                => (bool)($s['Shippers_Is_Active'] ?? true),
                'Shippers_Meta'                     => $s['Shippers_Meta'] ?? null,
            ]);

            $sid = $shipper->id;

            // 2) Nuke children (simple, robust) – replace-all
            ShipperContact::where('Shippers_Id', $sid)->delete();
            $oldDestIds = ShipperDestination::where('Shippers_Id', $sid)->pluck('id');
            ShipperShippingRate::where('Shippers_Id', $sid)->delete();
            ShipperVolumeRate::where('Shippers_Id', $sid)->delete();
            ShipperWeightRate::where('Shippers_Id', $sid)->delete();
            ShipperHeavyRate::where('Shippers_Id', $sid)->delete();
            HeavyVehicle::where('Shippers_Id', $sid)->delete();

            // Box rates depend on destination & size ids; delete them, then sizes
            ShipperBoxRate::where('Shippers_Id', $sid)->delete();
            ShipperBoxSize::where('Shippers_Id', $sid)->delete();

            ShipperDestination::whereIn('id', $oldDestIds)->delete();

            // 3) Recreate (reuse your create() logic)

            // Contacts
            foreach ($request->input('contacts', []) as $c) {
                if (!isset($c['Shippers_Contact_Name']) || trim((string)$c['Shippers_Contact_Name']) === '') continue;
                ShipperContact::create([
                    'Shippers_Id'                    => $sid,
                    'Shippers_Contact_Name'          => $c['Shippers_Contact_Name'],
                    'Shippers_Contact_Position'      => $c['Shippers_Contact_Position'] ?? null,
                    'Shippers_Contact_Office_No'     => $c['Shippers_Contact_Office_No'] ?? null,
                    'Shippers_Contact_GSM_No'        => $c['Shippers_Contact_GSM_No'] ?? null,
                    'Shippers_Contact_Email_Address' => $c['Shippers_Contact_Email_Address'] ?? null,
                    'Shippers_Is_Primary'            => (bool)($c['Shippers_Is_Primary'] ?? false),
                ]);
            }

            // Destinations (+ flags + bands + temp heavy rates carry)
            $createdDestIds = [];
            $destBlocks = $request->input('destinations', []);
            foreach ($destBlocks as $idx => $block) {
                $basic = $block['basic'] ?? [];

                $dest = ShipperDestination::create([
                    'Shippers_Id'                              => $sid,
                    'Shippers_Destination_Country'             => $basic['Shippers_Destination_Country'] ?? null,
                    'Shippers_Destination_Region'              => $basic['Shippers_Destination_Region'] ?? null,
                    'Shippers_Destination_District'            => $basic['Shippers_Destination_District'] ?? null,
                    'Shippers_Destination_Rate_Applicability'  => $basic['Shippers_Destination_Rate_Applicability'] ?? null,
                    'Shippers_Destination_Country_Preference'  => $basic['Shippers_Destination_Country_Preference'] ?? null,
                    'Shippers_Destination_Region_Preference'   => $basic['Shippers_Destination_Region_Preference'] ?? null,
                    'Shippers_Destination_District_Preference' => $basic['Shippers_Destination_District_Preference'] ?? null,
                ]);
                $createdDestIds[$idx] = $dest->id;

                $flags = $block['flags'] ?? [];
                ShipperShippingRate::create([
                    'Shippers_Id'                          => $sid,
                    'Shippers_Destination_Id'              => $dest->id,
                    'Shippers_Destination_Rate_Volume'     => (bool)($flags['Shippers_Destination_Rate_Volume'] ?? false),
                    'Shippers_Destination_Rate_Weight'     => (bool)($flags['Shippers_Destination_Rate_Weight'] ?? false),
                    'Shippers_Destination_Rate_Applicable' => (bool)($flags['Shippers_Destination_Rate_Applicable'] ?? true),
                    'Shippers_Destination_Rate_Box'        => (bool)($flags['Shippers_Destination_Rate_Box'] ?? false),
                ]);

                foreach (($block['volume_bands'] ?? []) as $v) {
                    ShipperVolumeRate::create([
                        'Shippers_Id'                            => $sid,
                        'Shippers_Destination_Id'                => $dest->id,
                        'Shippers_Standard_Shipping_Volume_Size' => $v['Shippers_Standard_Shipping_Volume_Size'] ?? null,
                        'Shippers_Standard_Shipping_Volume_Rate' => (float)($v['Shippers_Standard_Shipping_Volume_Rate'] ?? 0),
                        'Shippers_Currency'                      => $v['Shippers_Currency'] ?? 'OMR',
                        'Shippers_Min_Volume_Cbm'                => $v['Shippers_Min_Volume_Cbm'] ?? null,
                        'Shippers_Max_Volume_Cbm'                => $v['Shippers_Max_Volume_Cbm'] ?? null,
                        'Shippers_Base_Fee'                      => $v['Shippers_Base_Fee'] ?? null,
                        'Shippers_Per_Cbm_Fee'                   => $v['Shippers_Per_Cbm_Fee'] ?? null,
                        'Shippers_Flat_Fee'                      => $v['Shippers_Flat_Fee'] ?? null,
                    ]);
                }

                foreach (($block['weight_bands'] ?? []) as $w) {
                    ShipperWeightRate::create([
                        'Shippers_Id'                             => $sid,
                        'Shippers_Destination_Id'                 => $dest->id,
                        'Shippers_Standard_Shipping_Weight_Size'  => $w['Shippers_Standard_Shipping_Weight_Size'] ?? null,
                        'Shippers_Standard_Shipping_Weight_Rate'  => (float)($w['Shippers_Standard_Shipping_Weight_Rate'] ?? 0),
                        'Shippers_Currency'                       => $w['Shippers_Currency'] ?? 'OMR',
                        'Shippers_Min_Weight_Kg'                  => $w['Shippers_Min_Weight_Kg'] ?? null,
                        'Shippers_Max_Weight_Kg'                  => $w['Shippers_Max_Weight_Kg'] ?? null,
                        'Shippers_Base_Fee'                       => $w['Shippers_Base_Fee'] ?? null,
                        'Shippers_Per_Kg_Fee'                     => $w['Shippers_Per_Kg_Fee'] ?? null,
                        'Shippers_Flat_Fee'                       => $w['Shippers_Flat_Fee'] ?? null,
                    ]);
                }

                // carry heavy rates to bind later
                $destBlocks[$idx]['__heavy_rates_tmp'] = $block['heavy_rates'] ?? [];
            }

            // Vehicles
            $vehicleIdByType = [];
            foreach ($request->input('vehicles', []) as $veh) {
                $created = HeavyVehicle::create([
                    'Shippers_Id'                  => $sid,
                    'Shippers_Vehicle_Type'        => $veh['Shippers_Vehicle_Type'],
                    'Shippers_Vehicle_Capacity_Ton' => $veh['Shippers_Vehicle_Capacity_Ton'] ?? null,
                ]);
                $vehicleIdByType[$created->Shippers_Vehicle_Type] = $created->id;
            }

            // Heavy rates (bind to newly created dest/vehicle ids)
            foreach ($destBlocks as $idx => $blk) {
                $destId = $createdDestIds[$idx] ?? null;
                if (!$destId) continue;
                foreach (($blk['__heavy_rates_tmp'] ?? []) as $hr) {
                    $vehType = $hr['vehicle_type'] ?? null;
                    if (!$vehType || !isset($vehicleIdByType[$vehType])) continue;

                    ShipperHeavyRate::create([
                        'Shippers_Id'             => $sid,
                        'Shippers_Destination_Id' => $destId,
                        'Shippers_Vehicle_Id'     => $vehicleIdByType[$vehType],
                        'Shippers_Flat_Rate'      => $hr['Shippers_Flat_Rate'] ?? null,
                        'Shippers_Hourly_Rate'    => $hr['Shippers_Hourly_Rate'] ?? null,
                        'Shippers_Min_Hours'      => $hr['Shippers_Min_Hours'] ?? 0,
                        'Shippers_Currency'       => $hr['Shippers_Currency'] ?? 'OMR',
                    ]);
                }
            }

            // Box sizes + default per-destination rates (same as create)
            $boxSizesInput = $request->input('standard_boxes', []);
            $boxSizeIdByCode = [];
            foreach ($boxSizesInput as $b) {
                $hasAny = array_filter($b, fn($v) => !is_null($v) && $v !== '');
                if (!$hasAny) continue;
                $code  = trim((string)($b['Box_Code']  ?? ''));
                $label = trim((string)($b['Box_Label'] ?? ''));
                if ($code === '' || $label === '') continue;

                $L = isset($b['Length_cm']) ? (float)$b['Length_cm'] : 0.0;
                $W = isset($b['Width_cm'])  ? (float)$b['Width_cm']  : 0.0;
                $H = isset($b['Height_cm']) ? (float)$b['Height_cm'] : 0.0;
                $volCbm = ($L > 0 && $W > 0 && $H > 0) ? ($L * $W * $H) / 1_000_000 : null;

                $size = ShipperBoxSize::create([
                    'Shippers_Id'                => $sid,
                    'Shippers_Box_Code'          => $code,
                    'Shippers_Box_Label'         => $label,
                    'Shippers_Box_Length_Cm'     => $L,
                    'Shippers_Box_Width_Cm'      => $W,
                    'Shippers_Box_Height_Cm'     => $H,
                    'Shippers_Box_Max_Weight_Kg' => isset($b['Max_Weight_Kg']) ? (float)$b['Max_Weight_Kg'] : null,
                    'Shippers_Box_Volume_Cbm'    => $volCbm,
                    'Shippers_Box_Is_Active'     => true,
                ]);
                $boxSizeIdByCode[$code] = $size->id;
            }

            foreach ($destBlocks as $idx => $blk) {
                $destId = $createdDestIds[$idx] ?? null;
                if (!$destId) continue;
                $flags = $blk['flags'] ?? [];
                if (!(bool)($flags['Shippers_Destination_Rate_Box'] ?? false)) continue;

                foreach ($boxSizesInput as $b) {
                    $code = trim((string)($b['Box_Code'] ?? ''));
                    if ($code === '' || !isset($boxSizeIdByCode[$code])) continue;
                    if (!isset($b['Flat_Rate_Price'])) continue;

                    ShipperBoxRate::create([
                        'Shippers_Id'               => $sid,
                        'Shippers_Destination_Id'   => $destId,
                        'Shippers_Box_Size_Id'      => $boxSizeIdByCode[$code],
                        'Shippers_Flat_Box_Rate'    => (float)$b['Flat_Rate_Price'],
                        'Shippers_Base_Fee'         => isset($b['Base_Fee']) ? (float)$b['Base_Fee'] : null,
                        'Shippers_Currency'         => $b['Currency'] ?? 'OMR',
                        'Shippers_Max_Weight_Kg'    => isset($b['Max_Weight_Kg']) ? (float)$b['Max_Weight_Kg'] : null,
                    ]);
                }
            }

            return response()->json([
                'id'      => $sid,
                'message' => 'Shipper updated successfully.',
            ], 200);
        });
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
