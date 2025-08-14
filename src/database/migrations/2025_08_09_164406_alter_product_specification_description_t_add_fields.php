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
        //
         Schema::table('Product_Specification_Description_T', function (Blueprint $table) {
            // You can use enum or string+check. Enum is fine here.
            $table->enum('input_type', ['text','number','select','multiselect','boolean'])
                ->default('text')
                ->after('product_sub_sub_department_id');

            // SQL Server stores JSON as NVARCHAR(MAX). Laravel's json() works fine.
            $table->json('options_json')->nullable()->after('input_type');

            $table->boolean('is_required')->default(false)->after('options_json');
            $table->integer('sort_order')->default(0)->after('is_required');

            $table->index(['sort_order'], 'psd_sort_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
          Schema::table('Product_Specification_Description_T', function (Blueprint $table) {
            $table->dropIndex('psd_sort_idx');
            $table->dropColumn(['input_type','options_json','is_required','sort_order']);
        });
    }
};
