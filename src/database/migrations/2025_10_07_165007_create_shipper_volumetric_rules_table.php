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
        Schema::create('Shipper_Volumetric_Rules_T', function (Blueprint $table) {
            // INT identity, to match your existing style
            $table->increments('id');

            // Foreign keys (keep as plain ints to avoid schema-name headaches)
            $table->integer('Shippers_Id');             // references Shippers_T.id (or equivalent)
            $table->integer('Shippers_Destination_Id'); // references Shipper_Destinations_T.id (or equivalent)

            // The rule
            $table->boolean('Enabled')->default(true);
            $table->decimal('Divisor', 10, 2);          // e.g. 4000 or 5000
            $table->decimal('Max_L_cm', 10, 2)->nullable();
            $table->decimal('Max_W_cm', 10, 2)->nullable();
            $table->decimal('Max_H_cm', 10, 2)->nullable();
            $table->string('Note', 255)->nullable();

            $table->timestamps();

            // One rule per (shipper,destination)
            $table->unique(['Shippers_Id', 'Shippers_Destination_Id'], 'ux_shipper_dest_rule');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shipper_volumetric_rules');
    }
};
