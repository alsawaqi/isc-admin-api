<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('Product_Hierarchy_Import_Jobs_T')) {
            return;
        }

        Schema::table('Product_Hierarchy_Import_Jobs_T', function (Blueprint $table) {
            if (! Schema::hasColumn('Product_Hierarchy_Import_Jobs_T', 'Rolled_Back_At')) {
                $table->dateTime('Rolled_Back_At')->nullable();
            }
            if (! Schema::hasColumn('Product_Hierarchy_Import_Jobs_T', 'Rolled_Back_By')) {
                $table->unsignedBigInteger('Rolled_Back_By')->nullable();
            }
        });

        if (! $this->foreignKeyExists('fk_phi_job_rollback_user')) {
            Schema::table('Product_Hierarchy_Import_Jobs_T', function (Blueprint $table) {
                $table->foreign('Rolled_Back_By', 'fk_phi_job_rollback_user')
                    ->references('id')->on('Secx_Admin_User_Master_T');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('Product_Hierarchy_Import_Jobs_T')) {
            return;
        }

        Schema::table('Product_Hierarchy_Import_Jobs_T', function (Blueprint $table) {
            if (Schema::hasColumn('Product_Hierarchy_Import_Jobs_T', 'Rolled_Back_By')) {
                if ($this->foreignKeyExists('fk_phi_job_rollback_user')) {
                    $table->dropForeign('fk_phi_job_rollback_user');
                }
                $table->dropColumn('Rolled_Back_By');
            }
            if (Schema::hasColumn('Product_Hierarchy_Import_Jobs_T', 'Rolled_Back_At')) {
                $table->dropColumn('Rolled_Back_At');
            }
        });
    }

    private function foreignKeyExists(string $name): bool
    {
        if (DB::connection()->getDriverName() === 'sqlsrv') {
            return (bool) DB::selectOne(
                'select 1 as [exists] from sys.foreign_keys where [name] = ?',
                [$name],
            );
        }

        return false;
    }
};
