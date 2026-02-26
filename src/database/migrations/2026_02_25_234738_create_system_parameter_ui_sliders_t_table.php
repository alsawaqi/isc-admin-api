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
        Schema::create('System_Parameter_UI_Sliders_T', function (Blueprint $table) {
            $table->id();

            $table->string('Slider_Code', 30)->unique()->nullable();

            $table->string('Title', 150)->nullable();
            $table->string('Title_Ar', 150)->nullable();

            $table->text('Description')->nullable();
            $table->text('Description_Ar')->nullable();

            $table->string('Button_Text', 50)->nullable();
            $table->string('Button_Text_Ar', 50)->nullable();

            $table->string('Link_Url', 500)->nullable();

            // R2 object key/path (e.g. "Sliders/xxxx.webp")
            $table->string('Image_Path', 500);
            $table->bigInteger('Image_Size')->nullable();
            $table->string('Image_Extension', 20)->nullable();
            $table->string('Image_Type', 100)->nullable();

            $table->integer('Sort_Order')->default(0);
            $table->boolean('Is_Active')->default(true);

            $table->dateTime('Active_From')->nullable();
            $table->dateTime('Active_To')->nullable();

            $table->foreignId('Created_By')
                ->nullable()
                ->constrained('Secx_Admin_User_Master_T')
                ->onDelete('no action');

            $table->dateTime('Created_Date')->nullable();

            $table->timestamps();

            $table->index(['Is_Active', 'Sort_Order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('System_Parameter_UI_Sliders_T');
    }
};
