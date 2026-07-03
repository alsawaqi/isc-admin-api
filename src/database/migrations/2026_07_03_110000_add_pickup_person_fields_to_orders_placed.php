<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pickup handover: record WHO collected a pickup order (name + contact +
 * an ID photo/scan stored privately on R2) and when/by which staff member.
 *
 * ⚠️ DEPLOY-ORDER WARNING (VPS):
 * pickupComplete only enforces + persists the collector fields when
 * Schema::hasColumn('Orders_Placed_T', 'Pickup_Person_Name') is true, so the
 * new code is safe to deploy before this migration runs (old behavior kept).
 * Run this migration to actually turn on the ID-capture gate:
 *   php artisan migrate --path=database/migrations/2026_07_03_110000_add_pickup_person_fields_to_orders_placed.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('Orders_Placed_T')) {
            Schema::table('Orders_Placed_T', function (Blueprint $table) {
                if (!Schema::hasColumn('Orders_Placed_T', 'Pickup_Person_Name')) {
                    $table->string('Pickup_Person_Name', 255)->nullable();
                }

                if (!Schema::hasColumn('Orders_Placed_T', 'Pickup_Person_Contact')) {
                    $table->string('Pickup_Person_Contact', 100)->nullable();
                }

                // Private R2 object key (PickupIds/{orderId}/...); never a public URL.
                if (!Schema::hasColumn('Orders_Placed_T', 'Pickup_Id_Image_Path')) {
                    $table->string('Pickup_Id_Image_Path', 500)->nullable();
                }

                if (!Schema::hasColumn('Orders_Placed_T', 'Picked_Up_At')) {
                    $table->dateTime('Picked_Up_At')->nullable();
                }

                // Admin user (Secx_Admin_User_Master_T.id) who completed the handover.
                if (!Schema::hasColumn('Orders_Placed_T', 'Picked_Up_By')) {
                    $table->unsignedBigInteger('Picked_Up_By')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('Orders_Placed_T')) {
            Schema::table('Orders_Placed_T', function (Blueprint $table) {
                foreach ([
                    'Pickup_Person_Name',
                    'Pickup_Person_Contact',
                    'Pickup_Id_Image_Path',
                    'Picked_Up_At',
                    'Picked_Up_By',
                ] as $column) {
                    if (Schema::hasColumn('Orders_Placed_T', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
