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
        Schema::create('Order_Process_Log_T', function (Blueprint $table) {
            $table->id();

            // Link to the order
            $table->unsignedBigInteger('Orders_Placed_Id');

            // Step & status
            // Suggested codes: accounts_confirmed, packaging_completed, dispatched, shipper_confirmed, delivered, verified
            $table->string('Step_Code', 40);
            $table->string('Status', 24)->default('completed');   // completed|rejected|pending|corrected

            // Who signed/performed the action
            $table->unsignedBigInteger('Actor_User_Id')->nullable(); // your internal users table (if any)
            $table->string('Actor_Name', 120)->nullable();
            $table->string('Actor_Role', 60)->nullable();            // e.g. Accounts, OOB, Dispatch, Shipper
            $table->boolean('Is_External')->default(false);          // e.g. shipper stamp/sign

            // Per-step references / evidence
            $table->string('Dispatch_Document_No', 100)->nullable();
            $table->string('Shippers_Tracking_No', 100)->nullable();
            $table->boolean('Stamp_Received')->nullable();           // “authorized signature & stamp” at dispatch
            $table->json('Evidence_Photos')->nullable();             // array of URLs (packing photos, etc.)
            $table->text('Notes')->nullable();

            // Signature storage (either URL or binary)
            $table->string('Signature_Storage', 12)->default('url'); // url|blob
            $table->text('Signature_Url')->nullable();               // if stored in S3/R2
            $table->binary('Signature_Blob')->nullable();            // VARBINARY(MAX) on SQL Server
            $table->string('Signature_Mime', 50)->nullable();        // e.g. image/png
            $table->dateTime('Signed_At')->nullable();

            $table->timestamps();

            $table->foreign('Orders_Placed_Id')
                  ->references('id')->on('Orders_Placed_T')
                  ->onUpdate('no action')->onDelete('no action');


            $table->foreign('Actor_User_Id')
                  ->references('id')
                  ->on('Secx_Admin_User_Master_T')
                  ->onUpdate('no action')
                  ->onDelete('no action');      

            $table->index(['Orders_Placed_Id', 'Step_Code']);
            $table->index(['Orders_Placed_Id', 'Created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('Order_Process_Log_T');
    }
};
