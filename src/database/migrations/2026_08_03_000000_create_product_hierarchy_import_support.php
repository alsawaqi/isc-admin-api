<?php

use App\Support\HierarchyName;
use App\Support\ProductHierarchyMigrationPreflight;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $this->assertPreflightSafe();

        Schema::table('Products_Departments_T', function (Blueprint $table) {
            if (! Schema::hasColumn('Products_Departments_T', 'Source_Main_Id')) {
                $table->string('Source_Main_Id', 100)->nullable();
            }
            if (! Schema::hasColumn('Products_Departments_T', 'Source_Main_Sequence')) {
                $table->unsignedInteger('Source_Main_Sequence')->nullable();
            }
            if (! Schema::hasColumn('Products_Departments_T', 'Hierarchy_Code_Period')) {
                $table->string('Hierarchy_Code_Period', 7)->nullable();
            }
        });
        Schema::table('Products_Sub_Department_T', function (Blueprint $table) {
            if (! Schema::hasColumn('Products_Sub_Department_T', 'Name_Fingerprint')) {
                $table->string('Name_Fingerprint', 64)->nullable();
            }
            if (! Schema::hasColumn('Products_Sub_Department_T', 'Source_Sub_Sequence')) {
                $table->unsignedInteger('Source_Sub_Sequence')->nullable();
            }
        });
        Schema::table('Products_Sub_Sub_Department_T', function (Blueprint $table) {
            if (! Schema::hasColumn('Products_Sub_Sub_Department_T', 'Name_Fingerprint')) {
                $table->string('Name_Fingerprint', 64)->nullable();
            }
            if (! Schema::hasColumn('Products_Sub_Sub_Department_T', 'Source_Sub_Sub_Sequence')) {
                $table->unsignedInteger('Source_Sub_Sub_Sequence')->nullable();
            }
        });

        $this->backfillFingerprints();
        $this->assertNoNormalizedDuplicates();
        $this->assertNoDuplicateSlugs();
        $this->dropLegacyIndexes();
        $this->widenCodes();
        $this->widenAndRelaxNames();
        $this->makeFingerprintsRequired();
        $this->createHierarchyIndexes();
        $this->createImportJobsTable();
        $this->createPermissionAndCloneAccess();
    }

    public function down(): void
    {
        $this->assertSafeToRestoreLegacySchema();
        $this->dropNewIndexes();
        Schema::dropIfExists('Product_Hierarchy_Import_Jobs_T');
        $this->restoreLegacyNamesAndIndexes();

        Schema::table('Products_Sub_Sub_Department_T', function (Blueprint $table) {
            if (Schema::hasColumn('Products_Sub_Sub_Department_T', 'Source_Sub_Sub_Sequence')) {
                $table->dropColumn('Source_Sub_Sub_Sequence');
            }
            if (Schema::hasColumn('Products_Sub_Sub_Department_T', 'Name_Fingerprint')) {
                $table->dropColumn('Name_Fingerprint');
            }
        });
        Schema::table('Products_Sub_Department_T', function (Blueprint $table) {
            if (Schema::hasColumn('Products_Sub_Department_T', 'Source_Sub_Sequence')) {
                $table->dropColumn('Source_Sub_Sequence');
            }
            if (Schema::hasColumn('Products_Sub_Department_T', 'Name_Fingerprint')) {
                $table->dropColumn('Name_Fingerprint');
            }
        });
        Schema::table('Products_Departments_T', function (Blueprint $table) {
            if (Schema::hasColumn('Products_Departments_T', 'Hierarchy_Code_Period')) {
                $table->dropColumn('Hierarchy_Code_Period');
            }
            if (Schema::hasColumn('Products_Departments_T', 'Source_Main_Sequence')) {
                $table->dropColumn('Source_Main_Sequence');
            }
            if (Schema::hasColumn('Products_Departments_T', 'Source_Main_Id')) {
                $table->dropColumn('Source_Main_Id');
            }
        });
    }

    private function assertPreflightSafe(): void
    {
        $this->assertNoDuplicateSlugs();

        foreach ([
            ['Products_Sub_Department_T', 'Products_Departments_Id', 'Sub_Department_Name', 'sub-departments'],
            ['Products_Sub_Sub_Department_T', 'Product_Sub_Department_Id', 'Product_Sub_Sub_Department_Name', 'sub-sub-departments'],
        ] as [$table, $parentColumn, $nameColumn, $label]) {
            $seen = [];
            DB::table($table)
                ->select('id', $parentColumn, $nameColumn)
                ->orderBy('id')
                ->chunk(500, function ($rows) use (&$seen, $parentColumn, $nameColumn, $label) {
                    foreach ($rows as $row) {
                        $key = (string) $row->{$parentColumn}."\x1f".HierarchyName::fingerprint($row->{$nameColumn});
                        if (isset($seen[$key])) {
                            throw new \RuntimeException("Cannot migrate category uniqueness: normalized duplicate {$label} exist under the same parent.");
                        }
                        $seen[$key] = true;
                    }
                });
        }

        if (Schema::hasColumn('Products_Departments_T', 'Source_Main_Id')) {
            $seenSourceIds = [];
            DB::table('Products_Departments_T')
                ->select('id', 'Source_Main_Id')
                ->whereNotNull('Source_Main_Id')
                ->orderBy('id')
                ->chunk(500, function ($rows) use (&$seenSourceIds) {
                    foreach ($rows as $row) {
                        $key = HierarchyName::key($row->Source_Main_Id);
                        if (isset($seenSourceIds[$key])) {
                            throw new \RuntimeException('Cannot add source-ID uniqueness because duplicate normalized M-Ids already exist.');
                        }
                        $seenSourceIds[$key] = true;
                    }
                });
        }
        ProductHierarchyMigrationPreflight::assertDepartmentMetadata();
        ProductHierarchyMigrationPreflight::assertChildSequences('Products_Sub_Department_T', 'Products_Departments_Id', 'Source_Sub_Sequence');
        ProductHierarchyMigrationPreflight::assertChildSequences('Products_Sub_Sub_Department_T', 'Product_Sub_Department_Id', 'Source_Sub_Sub_Sequence');
    }

    private function dropLegacyIndexes(): void
    {
        $indexes = [
            ['Products_Departments_T', 'ux_pd_code'],
            ['Products_Departments_T', 'ux_pd_source_main_sequence'],
            ['Products_Departments_T', 'ux_pd_source_main_id'],
            ['Products_Sub_Department_T', 'ux_psd_code'],
            ['Products_Sub_Department_T', 'ux_psd_parent_source_sequence'],
            ['Products_Sub_Department_T', 'ux_psd_parent_fingerprint'],
            ['Products_Sub_Sub_Department_T', 'ux_pssd_code'],
            ['Products_Sub_Sub_Department_T', 'ux_pssd_parent_source_sequence'],
            ['Products_Sub_Sub_Department_T', 'ux_pssd_parent_fingerprint'],
            ['Products_Sub_Sub_Department_T', 'ux_pssd_slug'],
            ['Products_Departments_T', 'products_departments_t_product_department_code_unique'],
            ['Products_Sub_Department_T', 'products_sub_department_t_products_sub_department_code_unique'],
            ['Products_Sub_Sub_Department_T', 'products_sub_sub_department_t_product_sub_sub_department_code_unique'],
            ['Products_Sub_Department_T', 'products_sub_department_t_sub_department_name_unique'],
            ['Products_Sub_Department_T', 'products_sub_department_t_sub_department_name_ar_unique'],
            ['Products_Sub_Department_T', 'idx_psd_parent_name'],
            ['Products_Sub_Sub_Department_T', 'products_sub_sub_department_t_product_sub_sub_department_name_unique'],
            ['Products_Sub_Sub_Department_T', 'products_sub_sub_department_t_product_sub_sub_department_name_ar_unique'],
            ['Products_Sub_Sub_Department_T', 'idx_pssd_parent_name'],
            ['Products_Sub_Sub_Department_T', 'idx_pssd_slug'],
        ];

        foreach ($indexes as [$table, $index]) {
            $this->dropIndexOrConstraint($table, $index);
        }
    }

    private function widenCodes(): void
    {
        if (DB::connection()->getDriverName() === 'sqlsrv') {
            DB::statement('ALTER TABLE [Products_Departments_T] ALTER COLUMN [Product_Department_Code] NVARCHAR(100) NULL');
            DB::statement('ALTER TABLE [Products_Sub_Department_T] ALTER COLUMN [Products_Sub_Department_Code] NVARCHAR(100) NULL');
            DB::statement('ALTER TABLE [Products_Sub_Sub_Department_T] ALTER COLUMN [Product_Sub_Sub_Department_Code] NVARCHAR(100) NULL');

            return;
        }

        Schema::table('Products_Departments_T', function (Blueprint $table) {
            $table->string('Product_Department_Code', 100)->nullable()->change();
        });
        Schema::table('Products_Sub_Department_T', function (Blueprint $table) {
            $table->string('Products_Sub_Department_Code', 100)->nullable()->change();
        });
        Schema::table('Products_Sub_Sub_Department_T', function (Blueprint $table) {
            $table->string('Product_Sub_Sub_Department_Code', 100)->nullable()->change();
        });
    }

    private function widenAndRelaxNames(): void
    {
        if (DB::connection()->getDriverName() === 'sqlsrv') {
            DB::statement('ALTER TABLE [Products_Sub_Department_T] ALTER COLUMN [Sub_Department_Name] NVARCHAR(255) NOT NULL');
            DB::statement('ALTER TABLE [Products_Sub_Department_T] ALTER COLUMN [Sub_Department_Name_Ar] NVARCHAR(255) NULL');
            DB::statement('ALTER TABLE [Products_Sub_Sub_Department_T] ALTER COLUMN [Product_Sub_Sub_Department_Name] NVARCHAR(255) NOT NULL');
            DB::statement('ALTER TABLE [Products_Sub_Sub_Department_T] ALTER COLUMN [Product_Sub_Sub_Department_Name_Ar] NVARCHAR(255) NULL');

            return;
        }

        Schema::table('Products_Sub_Department_T', function (Blueprint $table) {
            $table->string('Sub_Department_Name', 255)->change();
            $table->string('Sub_Department_Name_Ar', 255)->nullable()->change();
        });
        Schema::table('Products_Sub_Sub_Department_T', function (Blueprint $table) {
            $table->string('Product_Sub_Sub_Department_Name', 255)->change();
            $table->string('Product_Sub_Sub_Department_Name_Ar', 255)->nullable()->change();
        });
    }

    private function backfillFingerprints(): void
    {
        DB::table('Products_Sub_Department_T')->select('id', 'Sub_Department_Name')->orderBy('id')->chunk(500, function ($rows) {
            foreach ($rows as $row) {
                DB::table('Products_Sub_Department_T')->where('id', $row->id)->update([
                    'Name_Fingerprint' => HierarchyName::fingerprint($row->Sub_Department_Name),
                ]);
            }
        });
        DB::table('Products_Sub_Sub_Department_T')->select('id', 'Product_Sub_Sub_Department_Name')->orderBy('id')->chunk(500, function ($rows) {
            foreach ($rows as $row) {
                DB::table('Products_Sub_Sub_Department_T')->where('id', $row->id)->update([
                    'Name_Fingerprint' => HierarchyName::fingerprint($row->Product_Sub_Sub_Department_Name),
                ]);
            }
        });
    }

    private function assertNoNormalizedDuplicates(): void
    {
        $duplicateSub = DB::table('Products_Sub_Department_T')
            ->select('Products_Departments_Id', 'Name_Fingerprint')
            ->groupBy('Products_Departments_Id', 'Name_Fingerprint')
            ->havingRaw('COUNT(*) > 1')->first();
        if ($duplicateSub) {
            throw new \RuntimeException('Cannot add category uniqueness: normalized duplicate sub-departments exist under the same parent.');
        }

        $duplicateLeaf = DB::table('Products_Sub_Sub_Department_T')
            ->select('Product_Sub_Department_Id', 'Name_Fingerprint')
            ->groupBy('Product_Sub_Department_Id', 'Name_Fingerprint')
            ->havingRaw('COUNT(*) > 1')->first();
        if ($duplicateLeaf) {
            throw new \RuntimeException('Cannot add category uniqueness: normalized duplicate sub-sub-departments exist under the same parent.');
        }
    }

    private function assertNoDuplicateSlugs(): void
    {
        $duplicate = DB::table('Products_Sub_Sub_Department_T')
            ->select('Slug')->whereNotNull('Slug')->groupBy('Slug')->havingRaw('COUNT(*) > 1')->first();
        if ($duplicate) {
            throw new \RuntimeException('Cannot add global slug uniqueness because duplicate non-null category slugs exist.');
        }
    }

    private function makeFingerprintsRequired(): void
    {
        if (DB::connection()->getDriverName() === 'sqlsrv') {
            DB::statement('ALTER TABLE [Products_Sub_Department_T] ALTER COLUMN [Name_Fingerprint] NVARCHAR(64) NOT NULL');
            DB::statement('ALTER TABLE [Products_Sub_Sub_Department_T] ALTER COLUMN [Name_Fingerprint] NVARCHAR(64) NOT NULL');

            return;
        }

        Schema::table('Products_Sub_Department_T', fn (Blueprint $table) => $table->string('Name_Fingerprint', 64)->nullable(false)->change());
        Schema::table('Products_Sub_Sub_Department_T', fn (Blueprint $table) => $table->string('Name_Fingerprint', 64)->nullable(false)->change());
    }

    private function createHierarchyIndexes(): void
    {
        if (DB::connection()->getDriverName() === 'sqlsrv') {
            DB::statement('CREATE UNIQUE INDEX [products_departments_t_product_department_code_unique] ON [Products_Departments_T] ([Product_Department_Code]) WHERE [Product_Department_Code] IS NOT NULL');
            DB::statement('CREATE UNIQUE INDEX [products_sub_department_t_products_sub_department_code_unique] ON [Products_Sub_Department_T] ([Products_Sub_Department_Code]) WHERE [Products_Sub_Department_Code] IS NOT NULL');
            DB::statement('CREATE UNIQUE INDEX [products_sub_sub_department_t_product_sub_sub_department_code_unique] ON [Products_Sub_Sub_Department_T] ([Product_Sub_Sub_Department_Code]) WHERE [Product_Sub_Sub_Department_Code] IS NOT NULL');
            DB::statement('CREATE UNIQUE INDEX [ux_pd_source_main_sequence] ON [Products_Departments_T] ([Source_Main_Sequence]) WHERE [Source_Main_Sequence] IS NOT NULL');
            DB::statement('CREATE UNIQUE INDEX [ux_psd_parent_source_sequence] ON [Products_Sub_Department_T] ([Products_Departments_Id], [Source_Sub_Sequence]) WHERE [Source_Sub_Sequence] IS NOT NULL');
            DB::statement('CREATE UNIQUE INDEX [ux_pssd_parent_source_sequence] ON [Products_Sub_Sub_Department_T] ([Product_Sub_Department_Id], [Source_Sub_Sub_Sequence]) WHERE [Source_Sub_Sub_Sequence] IS NOT NULL');
            DB::statement('CREATE UNIQUE INDEX [ux_pd_source_main_id] ON [Products_Departments_T] ([Source_Main_Id]) WHERE [Source_Main_Id] IS NOT NULL');
            DB::statement('CREATE UNIQUE INDEX [ux_psd_parent_fingerprint] ON [Products_Sub_Department_T] ([Products_Departments_Id], [Name_Fingerprint])');
            DB::statement('CREATE UNIQUE INDEX [ux_pssd_parent_fingerprint] ON [Products_Sub_Sub_Department_T] ([Product_Sub_Department_Id], [Name_Fingerprint])');
            DB::statement('CREATE UNIQUE INDEX [ux_pssd_slug] ON [Products_Sub_Sub_Department_T] ([Slug]) WHERE [Slug] IS NOT NULL');
            DB::statement('CREATE INDEX [idx_psd_parent_name] ON [Products_Sub_Department_T] ([Products_Departments_Id], [Sub_Department_Name])');
            DB::statement('CREATE INDEX [idx_pssd_parent_name] ON [Products_Sub_Sub_Department_T] ([Product_Sub_Department_Id], [Product_Sub_Sub_Department_Name])');

            return;
        }

        Schema::table('Products_Departments_T', function (Blueprint $table) {
            $table->unique('Product_Department_Code', 'ux_pd_code');
            $table->unique('Source_Main_Sequence', 'ux_pd_source_main_sequence');
            $table->unique('Source_Main_Id', 'ux_pd_source_main_id');
        });
        Schema::table('Products_Sub_Department_T', function (Blueprint $table) {
            $table->unique('Products_Sub_Department_Code', 'ux_psd_code');
            $table->unique(['Products_Departments_Id', 'Source_Sub_Sequence'], 'ux_psd_parent_source_sequence');
            $table->unique(['Products_Departments_Id', 'Name_Fingerprint'], 'ux_psd_parent_fingerprint');
            $table->index(['Products_Departments_Id', 'Sub_Department_Name'], 'idx_psd_parent_name');
        });
        Schema::table('Products_Sub_Sub_Department_T', function (Blueprint $table) {
            $table->unique('Product_Sub_Sub_Department_Code', 'ux_pssd_code');
            $table->unique(['Product_Sub_Department_Id', 'Source_Sub_Sub_Sequence'], 'ux_pssd_parent_source_sequence');
            $table->unique(['Product_Sub_Department_Id', 'Name_Fingerprint'], 'ux_pssd_parent_fingerprint');
            $table->unique('Slug', 'ux_pssd_slug');
            $table->index(['Product_Sub_Department_Id', 'Product_Sub_Sub_Department_Name'], 'idx_pssd_parent_name');
        });
    }

    private function createImportJobsTable(): void
    {
        if (Schema::hasTable('Product_Hierarchy_Import_Jobs_T')) {
            $required = [
                'Token', 'User_Id', 'File_Name', 'File_Size', 'File_Sha256',
                'Payload_Digest', 'Canonical_Payload', 'Summary', 'Status',
                'Can_Commit', 'Expires_At', 'Committed_At', 'Result',
            ];
            $missing = array_values(array_filter(
                $required,
                fn (string $column): bool => ! Schema::hasColumn('Product_Hierarchy_Import_Jobs_T', $column),
            ));
            if ($missing !== []) {
                throw new \RuntimeException('The existing hierarchy import jobs table is incomplete: '.implode(', ', $missing));
            }

            return;
        }

        Schema::create('Product_Hierarchy_Import_Jobs_T', function (Blueprint $table) {
            $table->id();
            $table->uuid('Token')->unique();
            $table->unsignedBigInteger('User_Id');
            $table->string('File_Name', 255);
            $table->unsignedBigInteger('File_Size');
            $table->char('File_Sha256', 64);
            $table->char('Payload_Digest', 64);
            $table->longText('Canonical_Payload');
            $table->longText('Summary')->nullable();
            $table->string('Status', 20)->default('pending');
            $table->boolean('Can_Commit')->default(false);
            $table->dateTime('Expires_At');
            $table->dateTime('Committed_At')->nullable();
            $table->longText('Result')->nullable();
            $table->timestamps();
            $table->index(['User_Id', 'Status'], 'idx_phi_job_user_status');
            $table->index('Expires_At', 'idx_phi_job_expiry');
            $table->foreign('User_Id', 'fk_phi_job_user')
                ->references('id')->on('Secx_Admin_User_Master_T')->cascadeOnDelete();
        });
    }

    private function createPermissionAndCloneAccess(): void
    {
        $departmentPermission = DB::table('Security_Permissions_T')
            ->where('name', 'departments')
            ->first(['id', 'guard_name']);
        $guardName = trim((string) ($departmentPermission->guard_name ?? ''));
        if ($guardName === '') {
            $guardName = (string) config('auth.defaults.guard', 'web');
        }

        $permission = DB::table('Security_Permissions_T')->where('name', 'import product categories')->first();
        if (! $permission) {
            $id = DB::table('Security_Permissions_T')->insertGetId([
                'name' => 'import product categories', 'guard_name' => $guardName, 'created_at' => now(), 'updated_at' => now(),
            ]);
        } else {
            $id = $permission->id;
            if ((string) $permission->guard_name !== $guardName) {
                DB::table('Security_Permissions_T')->where('id', $id)->update([
                    'guard_name' => $guardName,
                    'updated_at' => now(),
                ]);
            }
        }

        $departmentPermissionId = $departmentPermission->id ?? null;
        if (! $departmentPermissionId) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return;
        }
        foreach (DB::table('Security_Role_Has_Permissions_T')->where('permission_id', $departmentPermissionId)->pluck('role_id') as $roleId) {
            DB::table('Security_Role_Has_Permissions_T')->updateOrInsert(['role_id' => $roleId, 'permission_id' => $id]);
        }
        foreach (DB::table('Security_Model_Has_Permissions_T')->where('permission_id', $departmentPermissionId)->get() as $assignment) {
            DB::table('Security_Model_Has_Permissions_T')->updateOrInsert([
                'permission_id' => $id, 'model_id' => $assignment->model_id, 'model_type' => $assignment->model_type,
            ]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function removePermission(): void
    {
        $id = DB::table('Security_Permissions_T')->where('name', 'import product categories')->value('id');
        if (! $id) {
            return;
        }
        DB::table('Security_Role_Has_Permissions_T')->where('permission_id', $id)->delete();
        DB::table('Security_Model_Has_Permissions_T')->where('permission_id', $id)->delete();
        DB::table('Security_Permissions_T')->where('id', $id)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function assertSafeToRestoreLegacySchema(): void
    {
        if (
            DB::table('Products_Departments_T')->whereNotNull('Source_Main_Id')->exists()
            || DB::table('Products_Departments_T')->whereNotNull('Source_Main_Sequence')->exists()
            || DB::table('Products_Departments_T')->whereNotNull('Hierarchy_Code_Period')->exists()
            || DB::table('Products_Sub_Department_T')->whereNotNull('Source_Sub_Sequence')->exists()
            || DB::table('Products_Sub_Sub_Department_T')->whereNotNull('Source_Sub_Sub_Sequence')->exists()
        ) {
            throw new \RuntimeException('Cannot roll back hierarchy support after a department has been linked to an imported M-Id.');
        }

        $lengthFunction = match (DB::connection()->getDriverName()) {
            'sqlsrv' => 'LEN',
            'sqlite' => 'LENGTH',
            default => 'CHAR_LENGTH',
        };
        if (
            DB::table('Products_Sub_Department_T')->whereNull('Sub_Department_Name_Ar')->exists()
            || DB::table('Products_Sub_Sub_Department_T')->whereNull('Product_Sub_Sub_Department_Name_Ar')->exists()
            || DB::table('Products_Sub_Department_T')->whereRaw("{$lengthFunction}(Sub_Department_Name) > 50 OR {$lengthFunction}(Sub_Department_Name_Ar) > 50")->exists()
            || DB::table('Products_Sub_Sub_Department_T')->whereRaw("{$lengthFunction}(Product_Sub_Sub_Department_Name) > 50 OR {$lengthFunction}(Product_Sub_Sub_Department_Name_Ar) > 50")->exists()
            || DB::table('Products_Sub_Department_T')->select('Sub_Department_Name')->groupBy('Sub_Department_Name')->havingRaw('COUNT(*) > 1')->exists()
            || DB::table('Products_Sub_Department_T')->select('Sub_Department_Name_Ar')->groupBy('Sub_Department_Name_Ar')->havingRaw('COUNT(*) > 1')->exists()
            || DB::table('Products_Sub_Sub_Department_T')->select('Product_Sub_Sub_Department_Name')->groupBy('Product_Sub_Sub_Department_Name')->havingRaw('COUNT(*) > 1')->exists()
            || DB::table('Products_Sub_Sub_Department_T')->select('Product_Sub_Sub_Department_Name_Ar')->groupBy('Product_Sub_Sub_Department_Name_Ar')->havingRaw('COUNT(*) > 1')->exists()
        ) {
            throw new \RuntimeException('Cannot safely restore the legacy global-unique, 50-character, required-Arabic category schema.');
        }
    }

    private function restoreLegacyNamesAndIndexes(): void
    {
        if (DB::connection()->getDriverName() === 'sqlsrv') {
            DB::statement('ALTER TABLE [Products_Sub_Department_T] ALTER COLUMN [Sub_Department_Name] NVARCHAR(50) NOT NULL');
            DB::statement('ALTER TABLE [Products_Sub_Department_T] ALTER COLUMN [Sub_Department_Name_Ar] NVARCHAR(50) NOT NULL');
            DB::statement('ALTER TABLE [Products_Sub_Sub_Department_T] ALTER COLUMN [Product_Sub_Sub_Department_Name] NVARCHAR(50) NOT NULL');
            DB::statement('ALTER TABLE [Products_Sub_Sub_Department_T] ALTER COLUMN [Product_Sub_Sub_Department_Name_Ar] NVARCHAR(50) NOT NULL');
            DB::statement('CREATE UNIQUE INDEX [products_sub_department_t_sub_department_name_unique] ON [Products_Sub_Department_T] ([Sub_Department_Name])');
            DB::statement('CREATE UNIQUE INDEX [products_sub_department_t_sub_department_name_ar_unique] ON [Products_Sub_Department_T] ([Sub_Department_Name_Ar])');
            DB::statement('CREATE UNIQUE INDEX [products_sub_sub_department_t_product_sub_sub_department_name_unique] ON [Products_Sub_Sub_Department_T] ([Product_Sub_Sub_Department_Name])');
            DB::statement('CREATE UNIQUE INDEX [products_sub_sub_department_t_product_sub_sub_department_name_ar_unique] ON [Products_Sub_Sub_Department_T] ([Product_Sub_Sub_Department_Name_Ar])');
            DB::statement('CREATE INDEX [idx_psd_parent_name] ON [Products_Sub_Department_T] ([Products_Departments_Id], [Sub_Department_Name])');
            DB::statement('CREATE INDEX [idx_pssd_parent_name] ON [Products_Sub_Sub_Department_T] ([Product_Sub_Department_Id], [Product_Sub_Sub_Department_Name])');
            DB::statement('CREATE INDEX [idx_pssd_slug] ON [Products_Sub_Sub_Department_T] ([Slug])');

            return;
        }

        Schema::table('Products_Sub_Department_T', function (Blueprint $table) {
            $table->string('Sub_Department_Name', 50)->change();
            $table->string('Sub_Department_Name_Ar', 50)->nullable(false)->change();
            $table->unique('Sub_Department_Name', 'products_sub_department_t_sub_department_name_unique');
            $table->unique('Sub_Department_Name_Ar', 'products_sub_department_t_sub_department_name_ar_unique');
            $table->index(['Products_Departments_Id', 'Sub_Department_Name'], 'idx_psd_parent_name');
        });
        Schema::table('Products_Sub_Sub_Department_T', function (Blueprint $table) {
            $table->string('Product_Sub_Sub_Department_Name', 50)->change();
            $table->string('Product_Sub_Sub_Department_Name_Ar', 50)->nullable(false)->change();
            $table->unique('Product_Sub_Sub_Department_Name', 'products_sub_sub_department_t_product_sub_sub_department_name_unique');
            $table->unique('Product_Sub_Sub_Department_Name_Ar', 'products_sub_sub_department_t_product_sub_sub_department_name_ar_unique');
            $table->index(['Product_Sub_Department_Id', 'Product_Sub_Sub_Department_Name'], 'idx_pssd_parent_name');
            $table->index('Slug', 'idx_pssd_slug');
        });
    }

    private function dropNewIndexes(): void
    {
        foreach ([
            ['Products_Departments_T', 'ux_pd_source_main_sequence'],
            ['Products_Departments_T', 'ux_pd_source_main_id'],
            ['Products_Sub_Department_T', 'ux_psd_parent_source_sequence'],
            ['Products_Sub_Department_T', 'ux_psd_parent_fingerprint'],
            ['Products_Sub_Department_T', 'idx_psd_parent_name'],
            ['Products_Sub_Sub_Department_T', 'ux_pssd_parent_source_sequence'],
            ['Products_Sub_Sub_Department_T', 'ux_pssd_parent_fingerprint'],
            ['Products_Sub_Sub_Department_T', 'ux_pssd_slug'],
            ['Products_Sub_Sub_Department_T', 'idx_pssd_parent_name'],
        ] as [$table, $index]) {
            $this->dropIndexOrConstraint($table, $index);
        }
    }

    private function dropIndexOrConstraint(string $table, string $name): void
    {
        if (DB::connection()->getDriverName() === 'sqlsrv') {
            DB::unprepared("DECLARE @sql NVARCHAR(MAX); IF EXISTS (SELECT 1 FROM sys.key_constraints WHERE [name] = N'{$name}' AND [parent_object_id] = OBJECT_ID(N'{$table}')) SET @sql = N'ALTER TABLE [{$table}] DROP CONSTRAINT [{$name}]'; ELSE IF EXISTS (SELECT 1 FROM sys.indexes WHERE [name] = N'{$name}' AND [object_id] = OBJECT_ID(N'{$table}')) SET @sql = N'DROP INDEX [{$name}] ON [{$table}]'; IF @sql IS NOT NULL EXEC sp_executesql @sql;");

            return;
        }

        $index = collect(Schema::getIndexes($table))->first(
            fn (array $candidate): bool => strcasecmp((string) ($candidate['name'] ?? ''), $name) === 0,
        );
        if ($index === null) {
            return;
        }

        Schema::table($table, fn (Blueprint $blueprint) => ($index['unique'] ?? false)
            ? $blueprint->dropUnique($name)
            : $blueprint->dropIndex($name));
    }
};
