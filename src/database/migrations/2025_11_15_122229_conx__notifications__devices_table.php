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
      Schema::create('Conx_Notification_Devices_T', function (Blueprint $table) {


          $table->bigIncrements('id');

            // Who owns this device
            $table->unsignedBigInteger('User_Id');


            $table->foreign('User_Id')
                ->references('id')->on('Secx_Admin_User_Master_T')
                ->onDelete('no action');

            // Pusher Beams internal device id (web-xxxx-xxxx-xxxx)
            $table->string('Beams_Device_Id', 255);

            // Extra metadata (everything optional)
            $table->string('Device_Name', 255)->nullable();      // e.g. "Chrome on Windows"
            $table->string('Browser_Name', 100)->nullable();     // e.g. "Chrome"
            $table->string('Browser_Version', 50)->nullable();   // e.g. "124.0"
            $table->string('Os_Name', 100)->nullable();          // e.g. "Windows"
            $table->string('Os_Version', 50)->nullable();        // e.g. "10"
            $table->string('User_Agent', 1024)->nullable();
            $table->string('Ip_Address', 45)->nullable();

            // Is this device currently allowed to receive notifications?
            $table->boolean('Is_Enabled')->default(true);

            // Last time we saw this device register/refresh
            $table->timestamp('Last_Seen_At')->nullable();

            $table->timestamps();

            // Avoid duplicate rows for same user + device
            $table->unique(['User_Id', 'Beams_Device_Id']);

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
