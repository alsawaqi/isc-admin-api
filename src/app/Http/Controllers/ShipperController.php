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
use Illuminate\Support\Facades\Storage;
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

        //  ---- quick inline validation (minimum required) ----
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

            // default values
            $imagePath = null;
            $imageSize = null;
            $imageExtension = null;
            $imageType = null;

            // handle image upload (optional)

            $file = $request->file('file');

            if ($file) {
                // store under "shippers/{code}" on R2
                $path = Storage::disk('r2')->put("shippers", $file, 'public');

                $imagePath      = $path;
                $imageSize      = $file->getSize();
                $imageExtension = $file->getClientOriginalExtension();
                $imageType      = $file->getMimeType();
            }

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

                // 👇 new image fields
                'Shippers_Image_Path'               => $imagePath,
                'Shippers_Size'                     => $imageSize,
                'Shippers_Extension'                => $imageExtension,
                'Shippers_Image_Type'               => $imageType,
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



        $shipper = Shipper::with([
            'contacts',
            'contacts.department',
            'destinations',
            'destinations.volumeBands',
            'destinations.weightBands',
            'destinations.country',
            'destinations.region',
            'destinations.district',

            'destinations.volumetricRules',
            'destinations.heavyRates',
            'vehicles',
            'standardBoxes',
        ])->findOrFail($id);



        $heavyRates = ShipperHeavyRate::where('Shippers_Id', $id)->get();


        $heavyRatesFlat = $heavyRates->map(function ($r) {
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
            'shipper'      => [
                'Shippers_Code'          => $shipper->Shippers_Code,
                'Shippers_Name'          => $shipper->Shippers_Name,
                'Shippers_Address'       => $shipper->Shippers_Address,
                'Shippers_Office_No'     => $shipper->Shippers_Office_No,
                'Shippers_GSM_No'        => $shipper->Shippers_GSM_No,
                'Shippers_Email_Address' => $shipper->Shippers_Email_Address,
                'Shippers_Official_Website_Address' => $shipper->Shippers_Official_Website_Address,
                'Shippers_GPS_Location'  => $shipper->Shippers_GPS_Location,
                'Shippers_Scope'         => $shipper->Shippers_Scope,
                'Shippers_Type'          => $shipper->Shippers_Type,
                'Shippers_Rate_Mode'     => $shipper->Shippers_Rate_Mode,
                'Shippers_Is_Active'     => (bool) $shipper->Shippers_Is_Active,
                'Shippers_COD'           => (bool) $shipper->Shippers_COD,
                'Shippers_Image_Path'    => $shipper->Shippers_Image_Path,
                'Shippers_Size'          => $shipper->Shippers_Size,
                'Shippers_Extenstion'    => $shipper->Shippers_Extenstion,
                'Shippers_Image_Type'    => $shipper->Shippers_Image_Type,
            ],

            'contacts'    => $shipper->contacts,
            'destinations' => $shipper->destinations->map(function ($dest) {
                return [
                    'id' => $dest->id,
                    'basic' => [
                        'Shippers_Destination_Country_Id'      => $dest->Shippers_Destination_Country_Id,
                        'Shippers_Destination_Region_Id'       => $dest->Shippers_Destination_Region_Id,
                        'Shippers_Destination_District_Id'     => $dest->Shippers_Destination_District_Id,
                        'Shippers_Destination_Country'         => $dest->Shippers_Destination_Country,
                        'Shippers_Destination_Region'          => $dest->Shippers_Destination_Region,
                        'Shippers_Destination_District'        => $dest->Shippers_Destination_District,
                        'Shippers_Destination_Rate_Applicability' => $dest->Shippers_Destination_Rate_Applicability,
                        'Shippers_Destination_Country_Preference' => $dest->Shippers_Destination_Country_Preference,
                        'Shippers_Destination_Region_Preference'  => $dest->Shippers_Destination_Region_Preference,
                        'Shippers_Destination_District_Preference' => $dest->Shippers_Destination_District_Preference,
                    ],
                    'country'        => $dest->country,
                    'region'         => $dest->region,
                    'district'       => $dest->district,
                    'rate_mode'      => $dest->rate_mode,
                    'flags'          => [
                        'Shippers_Destination_Rate_Volume'     => (bool) $dest->Shippers_Destination_Rate_Volume,
                        'Shippers_Destination_Rate_Weight'     => (bool) $dest->Shippers_Destination_Rate_Weight,
                        'Shippers_Destination_Rate_Applicable' => (bool) $dest->Shippers_Destination_Rate_Applicable,
                        'Shippers_Destination_Rate_Box'        => (bool) $dest->Shippers_Destination_Rate_Box,
                    ],
                    'volume_bands'   => $dest->volumeBands,
                    'weight_bands'   => $dest->weightBands,
                    'volumetric_rule' => $dest->volumetricRule
                        ? [
                            'enabled' => (bool) $dest->volumetricRule->Enabled,
                            'divisor' => $dest->volumetricRule->Divisor,
                            'maxL_cm' => $dest->volumetricRule->Max_L_cm,
                            'maxW_cm' => $dest->volumetricRule->Max_W_cm,
                            'maxH_cm' => $dest->volumetricRule->Max_H_cm,
                        ]
                        : null,

                    'heavy_rates'    => $dest->heavyRates,
                ];
            })->values(),

            'vehicles'      => $shipper->vehicles,
            'standard_boxes' => $shipper->standardBoxes,
            'heavy_rates'    => $heavyRatesFlat,
        ]);
    }


    public function update(Request $request, $id)
    {
        $shipper = Shipper::findOrFail($id);

        $payload = $request->input();

        $payloadJson = $request->input('payload');
        $payload = $payloadJson ? json_decode($payloadJson, true) : $request->input();




        $shipperData = $payload['shipper'] ?? [];

        $shipper->fill([
            'Shippers_Name'          => $shipperData['Shippers_Name']          ?? $shipper->Shippers_Name,
            'Shippers_Address'       => $shipperData['Shippers_Address']       ?? null,
            'Shippers_Office_No'     => $shipperData['Shippers_Office_No']     ?? null,
            'Shippers_GSM_No'        => $shipperData['Shippers_GSM_No']        ?? null,
            'Shippers_Email_Address' => $shipperData['Shippers_Email_Address'] ?? null,
            'Shippers_Official_Website_Address' => $shipperData['Shippers_Official_Website_Address'] ?? null,
            'Shippers_GPS_Location'  => $shipperData['Shippers_GPS_Location']  ?? null,
            'Shippers_Scope'         => $shipperData['Shippers_Scope']         ?? 'local',
            'Shippers_Type'          => $shipperData['Shippers_Type']          ?? '',
            'Shippers_Rate_Mode'     => $shipperData['Shippers_Rate_Mode']     ?? 'weight',
            'Shippers_Is_Active'     => !empty($shipperData['Shippers_Is_Active']),
            'Shippers_COD'           => !empty($shipperData['Shippers_COD']),
        ]);

        // 🔹 If a new file is provided, overwrite old image
        if ($request->hasFile('file')) {
            $file = $request->file('file');

            if ($shipper->Shippers_Image_Path) {
                Storage::disk('r2')->delete($shipper->Shippers_Image_Path);
            }

            $path = Storage::disk('r2')->putFile('shippers', $file, 'public');

            $shipper->Shippers_Image_Path   = $path;
            $shipper->Shippers_Size         = $file->getSize();
            $shipper->Shippers_Extension   = $file->getClientOriginalExtension();
            $shipper->Shippers_Image_Type   = $file->getMimeType();
        }

        $shipper->save();

        // 🔹 Sync contacts (simple example: delete + recreate)
        $shipper->contacts()->delete();
        foreach ($payload['contacts'] ?? [] as $c) {
            $shipper->contacts()->create($c);
        }

        // 🔹 Sync destinations + rates + volumetric
        //$shipper->destinations()->delete();

        $existingDestinations = $shipper->destinations()->get()->keyBy('id');
        $seenDestinationIds   = [];

        foreach ($payload['destinations'] ?? [] as $destPayload) {
            $basic  = $destPayload['basic'] ?? [];
            $flags  = $destPayload['flags'] ?? [];
            $mode   = $destPayload['rate_mode'] ?? 'weight';
            $destId = $destPayload['id'] ?? null;     // 👈 comes from Nuxt now

            $data = [
                'Shippers_Destination_Country_Id'      => $basic['Shippers_Destination_Country_Id'] ?? null,
                'Shippers_Destination_Region_Id'       => $basic['Shippers_Destination_Region_Id'] ?? null,
                'Shippers_Destination_District_Id'     => $basic['Shippers_Destination_District_Id'] ?? null,

                'Shippers_Destination_Rate_Applicability'
                => $basic['Shippers_Destination_Rate_Applicability'] ?? null,
                'Shippers_Destination_Country_Preference'
                => $basic['Shippers_Destination_Country_Preference'] ?? null,
                'Shippers_Destination_Region_Preference'
                => $basic['Shippers_Destination_Region_Preference'] ?? null,
                'Shippers_Destination_District_Preference'
                => $basic['Shippers_Destination_District_Preference'] ?? null,
            ];

            // 🔹 1) UPDATE existing destination, or 2) CREATE new
            if ($destId && $existingDestinations->has($destId)) {
                $destination = $existingDestinations[$destId];
                $destination->update($data);

                // Clear old bands/rates for this destination so we don't duplicate rows
                $destination->volumeBands()->delete();
                $destination->weightBands()->delete();
                $destination->heavyRates()->delete();
                $destination->volumetricRule()->delete();
            } else {
                $destination = $shipper->destinations()->create($data);
            }

            $seenDestinationIds[] = $destination->id;

            // 🔹 volumetric_rule
            $vr = $destPayload['volumetric_rule'] ?? null;
            if ($vr) {
                $destination->volumetricRule()->updateOrCreate(
                    [], // relation constrains by Shippers_Destination_Id internally
                    [
                        'Shippers_Id' => $shipper->getKey(),
                        'Enabled'     => !empty($vr['enabled']),
                        'Divisor'     => $vr['divisor'] ?? null,
                        'Max_L_cm'    => $vr['maxL_cm'] ?? null,
                        'Max_W_cm'    => $vr['maxW_cm'] ?? null,
                        'Max_H_cm'    => $vr['maxH_cm'] ?? null,
                    ]
                );
            }

           // When creating weight bands
foreach ($destPayload['weight_bands'] ?? [] as $wb) {
    $destination->weightBands()->create(array_merge($wb, [
        'Shippers_Id' => $shipper->id, // Make sure to set Shippers_Id here
    ]));
}

// Similarly for volume bands if needed
foreach ($destPayload['volume_bands'] ?? [] as $vb) {
    $destination->volumeBands()->create(array_merge($vb, [
        'Shippers_Id' => $shipper->id, // Include Shippers_Id here too if needed
    ]));
}

            // 🔹 heavy rates (if heavy)
            foreach ($destPayload['heavy_rates'] ?? [] as $hr) {
                $destination->heavyRates()->create($hr);
            }
        }

        // 🔹 Optionally: delete destinations that were removed in the form
        if (empty($seenDestinationIds)) {
            // delete ALL destinations (and children)
            $toDelete = $shipper->destinations()->get();

            foreach ($toDelete as $destination) {
                $destination->volumeBands()->delete();
                $destination->weightBands()->delete();
                $destination->heavyRates()->delete();
                $destination->volumetricRule()->delete();
                $destination->delete();
            }
        } else {
            // delete only the removed ones
            $toDelete = $shipper->destinations()
                ->whereNotIn('id', $seenDestinationIds)
                ->get();

            foreach ($toDelete as $destination) {
                $destination->volumeBands()->delete();
                $destination->weightBands()->delete();
                $destination->heavyRates()->delete();
                $destination->volumetricRule()->delete();
                $destination->delete();
            }
        }




        // 🔹 Vehicles
        $shipper->vehicles()->delete();
        foreach ($payload['vehicles'] ?? [] as $v) {
            $shipper->vehicles()->create($v);
        }

        // 🔹 Standard boxes
        // Get existing boxes keyed by code
        $existing = $shipper->standardBoxes()->get()->keyBy('Shippers_Box_Code');
        $seenCodes = [];

        foreach ($payload['standard_boxes'] ?? [] as $b) {
            // Use existing code if provided, otherwise generate a new one
            $code = $b['Shippers_Box_Code']
                ?? CodeGenerator::createCode('BOX', 'Shipper_Box_Sizes_T', 'Shippers_Box_Code');

            $data = [
                'Shippers_Box_Code' => $code,
                'Box_Label'         => $b['Box_Label']         ?? null,
                'Length_cm'         => $b['Length_cm']         ?? null,
                'Width_cm'          => $b['Width_cm']          ?? null,
                'Height_cm'         => $b['Height_cm']         ?? null,
                'Max_Weight_Kg'     => $b['Max_Weight_Kg']     ?? null,
                'Notes'             => $b['Notes']             ?? null,
            ];

            if ($existing->has($code)) {
                // UPDATE
                $existing[$code]->update($data);
            } else {
                // CREATE
                $shipper->standardBoxes()->create($data);
            }

            $seenCodes[] = $code;
        }

        // Optionally delete boxes that are not in the payload anymore
        $shipper->standardBoxes()
            ->whereNotIn('Shippers_Box_Code', $seenCodes)
            ->delete();

        return response()->json([
            'success' => true,
            'message' => 'Shipper updated successfully',
        ]);
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
