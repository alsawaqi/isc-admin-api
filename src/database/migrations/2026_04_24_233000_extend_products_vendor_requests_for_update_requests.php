<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('Products_Vendor_Requests_T', 'Products_Temporary_Id')) {
            DB::statement('ALTER TABLE Products_Vendor_Requests_T ALTER COLUMN Products_Temporary_Id BIGINT NULL');
        }

        Schema::table('Products_Vendor_Requests_T', function (Blueprint $table) {
            if (!Schema::hasColumn('Products_Vendor_Requests_T', 'Request_Type')) {
                $table->string('Request_Type', 50)->nullable();
            }

            if (!Schema::hasColumn('Products_Vendor_Requests_T', 'Requested_Changes_Json')) {
                $table->text('Requested_Changes_Json')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('Products_Vendor_Requests_T', function (Blueprint $table) {
            if (Schema::hasColumn('Products_Vendor_Requests_T', 'Requested_Changes_Json')) {
                $table->dropColumn('Requested_Changes_Json');
            }

            if (Schema::hasColumn('Products_Vendor_Requests_T', 'Request_Type')) {
                $table->dropColumn('Request_Type');
            }
        });

        if (Schema::hasColumn('Products_Vendor_Requests_T', 'Products_Temporary_Id')) {
            DB::table('Products_Vendor_Requests_T')
                ->whereNull('Products_Temporary_Id')
                ->delete();

            DB::statement('ALTER TABLE Products_Vendor_Requests_T ALTER COLUMN Products_Temporary_Id BIGINT NOT NULL');
        }
    }
};
