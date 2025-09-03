<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('Shipper_Box_Rates_T', function (Blueprint $table) {
            $table->id(); // PK = id
            $table->unsignedBigInteger('Shippers_Id');             // FK → Shippers_Master_T.id
            $table->unsignedBigInteger('Shippers_Destination_Id'); // FK → Shipper_Destinations_T.id
            $table->unsignedBigInteger('Shippers_Box_Size_Id');    // FK → Shipper_Box_Sizes_T.id

            // Pricing (flat per box is the common model; base is optional)
            $table->decimal('Shippers_Flat_Box_Rate', 12, 3)->default(0);
            $table->decimal('Shippers_Base_Fee', 12, 3)->nullable();
            $table->string('Shippers_Currency', 3)->default('OMR');

            // Optional constraints per rate (override max if needed)
            $table->decimal('Shippers_Max_Weight_Kg', 10, 3)->nullable();

            $table->timestamps();

            $table->unique(
                ['Shippers_Id','Shippers_Destination_Id','Shippers_Box_Size_Id'],
                'uniq_shipper_box_rate_combo'
            );

            $table->foreign('Shippers_Id')
                ->references('id')->on('Shippers_Master_T')
                ->onDelete('no action');
            $table->foreign('Shippers_Destination_Id')
                ->references('id')->on('Shipper_Destinations_T')
                ->onDelete('no action');
            $table->foreign('Shippers_Box_Size_Id')
                ->references('id')->on('Shipper_Box_Sizes_T')
                ->onDelete('no action');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('Shipper_Box_Rates_T');
    }
};
