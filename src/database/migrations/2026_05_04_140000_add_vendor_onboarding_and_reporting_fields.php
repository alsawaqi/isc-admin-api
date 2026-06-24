<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Vendors_Master_T', function (Blueprint $table) {
            foreach ($this->vendorColumns() as $name => $definition) {
                if (!Schema::hasColumn('Vendors_Master_T', $name)) {
                    $definition($table);
                }
            }
        });

        if (!Schema::hasTable('Vendor_Documents_T')) {
            Schema::create('Vendor_Documents_T', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('Vendor_Id');
                $table->string('Document_Type', 60);
                $table->string('Document_Name', 150)->nullable();
                $table->text('File_Path')->nullable();
                $table->string('File_Mime', 80)->nullable();
                $table->integer('File_Size')->nullable();
                $table->string('Status', 30)->default('pending');
                $table->text('Review_Note')->nullable();
                $table->unsignedBigInteger('Uploaded_By_Vendor_User_Id')->nullable();
                $table->unsignedBigInteger('Reviewed_By_Admin_Id')->nullable();
                $table->dateTime('Reviewed_At')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('Vendor_Id')
                    ->references('id')
                    ->on('Vendors_Master_T')
                    ->onUpdate('no action')
                    ->onDelete('cascade');

                $table->index(['Vendor_Id', 'Document_Type'], 'idx_vendor_docs_vendor_type');
                $table->index(['Status'], 'idx_vendor_docs_status');
            });
        }

        if (Schema::hasTable('Vendors_Master_T')) {
            DB::table('Vendors_Master_T')->whereNull('Approval_Status')->update(['Approval_Status' => 'pending']);
            DB::table('Vendors_Master_T')->whereNull('Onboarding_Status')->update(['Onboarding_Status' => 'incomplete']);
            DB::table('Vendors_Master_T')->whereNull('Payout_Status')->update(['Payout_Status' => 'not_configured']);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('Vendor_Documents_T');

        Schema::table('Vendors_Master_T', function (Blueprint $table) {
            foreach (array_keys($this->vendorColumns()) as $column) {
                if (Schema::hasColumn('Vendors_Master_T', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    /**
     * @return array<string, callable(Blueprint): void>
     */
    private function vendorColumns(): array
    {
        return [
            'Business_Type' => fn (Blueprint $table) => $table->string('Business_Type', 80)->nullable(),
            'Contact_Person_Name' => fn (Blueprint $table) => $table->string('Contact_Person_Name', 150)->nullable(),
            'Contact_Person_Title' => fn (Blueprint $table) => $table->string('Contact_Person_Title', 100)->nullable(),
            'Contact_Person_Email' => fn (Blueprint $table) => $table->string('Contact_Person_Email', 255)->nullable(),
            'Contact_Person_Phone' => fn (Blueprint $table) => $table->string('Contact_Person_Phone', 50)->nullable(),
            'Bank_Name' => fn (Blueprint $table) => $table->string('Bank_Name', 150)->nullable(),
            'Bank_Account_Name' => fn (Blueprint $table) => $table->string('Bank_Account_Name', 150)->nullable(),
            'Bank_Account_Number' => fn (Blueprint $table) => $table->string('Bank_Account_Number', 80)->nullable(),
            'Bank_IBAN' => fn (Blueprint $table) => $table->string('Bank_IBAN', 80)->nullable(),
            'Bank_Swift_Code' => fn (Blueprint $table) => $table->string('Bank_Swift_Code', 40)->nullable(),
            'Payout_Method' => fn (Blueprint $table) => $table->string('Payout_Method', 40)->nullable(),
            'Payout_Status' => fn (Blueprint $table) => $table->string('Payout_Status', 30)->default('not_configured'),
            'Approval_Status' => fn (Blueprint $table) => $table->string('Approval_Status', 30)->default('pending'),
            'Onboarding_Status' => fn (Blueprint $table) => $table->string('Onboarding_Status', 30)->default('incomplete'),
            'Onboarding_Completeness' => fn (Blueprint $table) => $table->integer('Onboarding_Completeness')->default(0),
            'Approved_By' => fn (Blueprint $table) => $table->unsignedBigInteger('Approved_By')->nullable(),
            'Approved_At' => fn (Blueprint $table) => $table->dateTime('Approved_At')->nullable(),
            'Approval_Note' => fn (Blueprint $table) => $table->text('Approval_Note')->nullable(),
            'Submitted_For_Approval_At' => fn (Blueprint $table) => $table->dateTime('Submitted_For_Approval_At')->nullable(),
        ];
    }
};
