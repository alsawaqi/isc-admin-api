<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DEPLOY ORDER: run this migration FIRST (isc-admin-api) before deploying the
 * Titles/Designations changes in laravel-api, isc-admin-ui and nuxt-ts-app —
 * they all read the tables/columns created here (shared MSSQL `isc` database).
 *
 * Creates the Titles (honorifics: Mr/Mrs/Dr...) and Designations (job
 * positions) lookup tables, links them to customer contacts and shipper
 * contacts, seeds the titles that were previously hardcoded in the storefront
 * address form, and backfills Customers_Contact_T.Title_Id from the legacy
 * free-text Designation column (which actually held title strings).
 */
return new class extends Migration
{
    /**
     * Titles previously hardcoded in the storefront address form
     * (nuxt-ts-app AccountAddresses.vue) — seeded so the UX carries over.
     */
    private array $seedTitles = ['Mr', 'Ms', 'Mrs', 'Dr', 'Prof', 'Sir', 'Eng'];

    public function up(): void
    {
        if (! Schema::hasTable('Titles_Master_T')) {
            Schema::create('Titles_Master_T', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('Title_Name', 100);
                $table->string('Title_Name_Ar', 100)->nullable();
                $table->boolean('Is_Active')->default(true);
                $table->unsignedBigInteger('Created_By')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('Designations_Master_T')) {
            Schema::create('Designations_Master_T', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('Designation_Name', 100);
                $table->string('Designation_Name_Ar', 100)->nullable();
                $table->boolean('Is_Active')->default(true);
                $table->unsignedBigInteger('Created_By')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('Customers_Contact_T')
            && ! Schema::hasColumn('Customers_Contact_T', 'Title_Id')) {
            Schema::table('Customers_Contact_T', function (Blueprint $table) {
                $table->unsignedBigInteger('Title_Id')->nullable();
            });
        }

        if (Schema::hasTable('Shipper_Contacts_T')) {
            Schema::table('Shipper_Contacts_T', function (Blueprint $table) {
                if (! Schema::hasColumn('Shipper_Contacts_T', 'Title_Id')) {
                    $table->unsignedBigInteger('Title_Id')->nullable();
                }

                if (! Schema::hasColumn('Shipper_Contacts_T', 'Designation_Id')) {
                    $table->unsignedBigInteger('Designation_Id')->nullable();
                }
            });
        }

        // Seed the honorific titles (guarded: only insert missing names).
        foreach ($this->seedTitles as $title) {
            $exists = DB::table('Titles_Master_T')
                ->whereRaw('LOWER(LTRIM(RTRIM(Title_Name))) = ?', [mb_strtolower($title)])
                ->exists();

            if (! $exists) {
                DB::table('Titles_Master_T')->insert([
                    'Title_Name' => $title,
                    'Is_Active'  => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // Backfill: legacy Customers_Contact_T.Designation values are actually
        // title strings — link them via Title_Id where they match a seeded
        // title exactly (trimmed, case-insensitive). The legacy Designation
        // column and its data are left untouched.
        if (Schema::hasColumn('Customers_Contact_T', 'Title_Id')
            && Schema::hasColumn('Customers_Contact_T', 'Designation')) {
            foreach ($this->seedTitles as $title) {
                $titleId = DB::table('Titles_Master_T')
                    ->whereRaw('LOWER(LTRIM(RTRIM(Title_Name))) = ?', [mb_strtolower($title)])
                    ->value('id');

                if (! $titleId) {
                    continue;
                }

                DB::table('Customers_Contact_T')
                    ->whereNull('Title_Id')
                    ->whereNotNull('Designation')
                    ->whereRaw('LOWER(LTRIM(RTRIM(Designation))) = ?', [mb_strtolower($title)])
                    ->update(['Title_Id' => $titleId]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('Shipper_Contacts_T')) {
            Schema::table('Shipper_Contacts_T', function (Blueprint $table) {
                foreach (['Title_Id', 'Designation_Id'] as $column) {
                    if (Schema::hasColumn('Shipper_Contacts_T', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('Customers_Contact_T')
            && Schema::hasColumn('Customers_Contact_T', 'Title_Id')) {
            Schema::table('Customers_Contact_T', function (Blueprint $table) {
                $table->dropColumn('Title_Id');
            });
        }

        Schema::dropIfExists('Designations_Master_T');
        Schema::dropIfExists('Titles_Master_T');
    }
};
