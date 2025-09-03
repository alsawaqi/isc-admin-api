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
        Schema::create('Shipper_Box_Sizes_T', function (Blueprint $table) {
            $table->id(); // PK = id
            $table->unsignedBigInteger('Shippers_Id'); // FK → Shippers_Master_T.id

            // Box identity & dims
            $table->string('Shippers_Box_Code', 50);       // e.g. "SMALL", "MEDIUM"
            $table->string('Shippers_Box_Label', 100);     // human label
            $table->decimal('Shippers_Box_Length_Cm', 10, 2);
            $table->decimal('Shippers_Box_Width_Cm', 10, 2);
            $table->decimal('Shippers_Box_Height_Cm', 10, 2);
            $table->decimal('Shippers_Box_Max_Weight_Kg', 10, 3)->nullable();

            // Convenience (store at insert/update time from L×W×H / 1,000,000)
            $table->decimal('Shippers_Box_Volume_Cbm', 12, 6)->nullable();

            $table->boolean('Shippers_Box_Is_Active')->default(true);
            $table->timestamps();

            $table->foreign('Shippers_Id')
                ->references('id')->on('Shippers_Master_T')
                ->onDelete('no action');

            $table->unique(['Shippers_Id','Shippers_Box_Code'], 'uniq_shipper_box_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('Shipper_Box_Sizes_T');
    }
};
