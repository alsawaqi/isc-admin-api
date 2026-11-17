<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Product_Specification_Description_T', function (Blueprint $table) {
            $table->boolean('show_in_table')->default(false);
        });
        // The existing is_active flag remains the filter visibility flag.
        // Preserve every existing specification's table visibility on upgrade.
        DB::table('Product_Specification_Description_T')->update(['show_in_table' => DB::raw('COALESCE(is_active, 0)')]);
    }

    public function down(): void
    {
        Schema::table('Product_Specification_Description_T', fn (Blueprint $table) => $table->dropColumn('show_in_table'));
    }
};
