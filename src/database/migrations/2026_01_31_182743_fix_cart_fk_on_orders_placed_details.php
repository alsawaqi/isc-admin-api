<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;


return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('Orders_Placed_Details_T', function (Blueprint $table) {
            // Make sure Cart_Id is nullable (required for SET NULL)
            $table->unsignedBigInteger('Cart_Id')->nullable()->change();
        });

        // Drop the existing FK (name from your error)
        DB::statement("ALTER TABLE Orders_Placed_Details_T DROP CONSTRAINT orders_placed_details_t_cart_id_foreign");

        // Re-add FK with ON DELETE SET NULL
        DB::statement("
            ALTER TABLE Orders_Placed_Details_T
            ADD CONSTRAINT orders_placed_details_t_cart_id_foreign
            FOREIGN KEY (Cart_Id) REFERENCES Customers_Carts_T(id)
            ON DELETE SET NULL
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE Orders_Placed_Details_T DROP CONSTRAINT orders_placed_details_t_cart_id_foreign");

        DB::statement("
            ALTER TABLE Orders_Placed_Details_T
            ADD CONSTRAINT orders_placed_details_t_cart_id_foreign
            FOREIGN KEY (Cart_Id) REFERENCES Customers_Carts_T(id)
        ");

        Schema::table('Orders_Placed_Details_T', function (Blueprint $table) {
            $table->unsignedBigInteger('Cart_Id')->nullable(false)->change();
        });
    }
};
