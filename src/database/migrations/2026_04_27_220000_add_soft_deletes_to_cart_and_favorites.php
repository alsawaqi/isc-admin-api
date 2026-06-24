<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('Customers_Carts_T')) {
            Schema::table('Customers_Carts_T', function (Blueprint $table) {
                if (!Schema::hasColumn('Customers_Carts_T', 'deleted_at')) {
                    $table->dateTime('deleted_at')->nullable()->after('updated_at');
                }
            });

            $this->dropIndexOrConstraint('Customers_Carts_T', 'ux_customers_carts_customer_product');

            $this->createIndexIfMissing(
                'Customers_Carts_T',
                'idx_customers_carts_customer_deleted',
                '[Customers_Id], [deleted_at]'
            );
            $this->createIndexIfMissing(
                'Customers_Carts_T',
                'idx_customers_carts_product_deleted',
                '[Products_Id], [deleted_at]'
            );
            $this->createIndexIfMissing(
                'Customers_Carts_T',
                'idx_customers_carts_created_deleted',
                '[created_at], [deleted_at]'
            );
            $this->createIndexIfMissing(
                'Customers_Carts_T',
                'ux_customers_carts_active_customer_product',
                '[Customers_Id], [Products_Id]',
                true,
                'WHERE [deleted_at] IS NULL'
            );
        }

        if (Schema::hasTable('Favorites_Master_T')) {
            Schema::table('Favorites_Master_T', function (Blueprint $table) {
                if (!Schema::hasColumn('Favorites_Master_T', 'deleted_at')) {
                    $table->dateTime('deleted_at')->nullable()->after('updated_at');
                }
            });

            $this->dropIndexOrConstraint('Favorites_Master_T', 'fav_unique_customer_product');

            $this->createIndexIfMissing(
                'Favorites_Master_T',
                'idx_favorites_customer_deleted',
                '[Customers_Id], [deleted_at]'
            );
            $this->createIndexIfMissing(
                'Favorites_Master_T',
                'idx_favorites_product_deleted',
                '[Products_Id], [deleted_at]'
            );
            $this->createIndexIfMissing(
                'Favorites_Master_T',
                'idx_favorites_created_deleted',
                '[created_at], [deleted_at]'
            );
            $this->createIndexIfMissing(
                'Favorites_Master_T',
                'ux_favorites_active_customer_product',
                '[Customers_Id], [Products_Id]',
                true,
                'WHERE [deleted_at] IS NULL'
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('Customers_Carts_T')) {
            $this->dropIndexOrConstraint('Customers_Carts_T', 'ux_customers_carts_active_customer_product');
            $this->dropIndexOrConstraint('Customers_Carts_T', 'idx_customers_carts_customer_deleted');
            $this->dropIndexOrConstraint('Customers_Carts_T', 'idx_customers_carts_product_deleted');
            $this->dropIndexOrConstraint('Customers_Carts_T', 'idx_customers_carts_created_deleted');

            if (Schema::hasColumn('Customers_Carts_T', 'deleted_at')) {
                Schema::table('Customers_Carts_T', function (Blueprint $table) {
                    $table->dropColumn('deleted_at');
                });
            }

            $this->createOriginalUniqueIfSafe(
                'Customers_Carts_T',
                'ux_customers_carts_customer_product'
            );
        }

        if (Schema::hasTable('Favorites_Master_T')) {
            $this->dropIndexOrConstraint('Favorites_Master_T', 'ux_favorites_active_customer_product');
            $this->dropIndexOrConstraint('Favorites_Master_T', 'idx_favorites_customer_deleted');
            $this->dropIndexOrConstraint('Favorites_Master_T', 'idx_favorites_product_deleted');
            $this->dropIndexOrConstraint('Favorites_Master_T', 'idx_favorites_created_deleted');

            if (Schema::hasColumn('Favorites_Master_T', 'deleted_at')) {
                Schema::table('Favorites_Master_T', function (Blueprint $table) {
                    $table->dropColumn('deleted_at');
                });
            }

            $this->createOriginalUniqueIfSafe(
                'Favorites_Master_T',
                'fav_unique_customer_product'
            );
        }
    }

    private function dropIndexOrConstraint(string $table, string $name): void
    {
        $qualifiedTable = $this->qualifiedTable($table);

        DB::statement("
            IF EXISTS (
                SELECT 1
                FROM sys.key_constraints
                WHERE [name] = N'{$name}'
                    AND [parent_object_id] = OBJECT_ID(N'{$qualifiedTable}')
            )
            BEGIN
                ALTER TABLE [dbo].[{$table}] DROP CONSTRAINT [{$name}];
            END

            IF EXISTS (
                SELECT 1
                FROM sys.indexes
                WHERE [name] = N'{$name}'
                    AND [object_id] = OBJECT_ID(N'{$qualifiedTable}')
            )
            BEGIN
                DROP INDEX [{$name}] ON [dbo].[{$table}];
            END
        ");
    }

    private function createIndexIfMissing(
        string $table,
        string $name,
        string $columns,
        bool $unique = false,
        string $where = ''
    ): void {
        $qualifiedTable = $this->qualifiedTable($table);
        $uniqueSql = $unique ? 'UNIQUE ' : '';
        $whereSql = $where !== '' ? " {$where}" : '';

        DB::statement("
            IF NOT EXISTS (
                SELECT 1
                FROM sys.indexes
                WHERE [name] = N'{$name}'
                    AND [object_id] = OBJECT_ID(N'{$qualifiedTable}')
            )
            BEGIN
                CREATE {$uniqueSql}INDEX [{$name}]
                    ON [dbo].[{$table}] ({$columns}){$whereSql};
            END
        ");
    }

    private function createOriginalUniqueIfSafe(string $table, string $name): void
    {
        $qualifiedTable = $this->qualifiedTable($table);

        DB::statement("
            IF NOT EXISTS (
                SELECT 1
                FROM sys.indexes
                WHERE [name] = N'{$name}'
                    AND [object_id] = OBJECT_ID(N'{$qualifiedTable}')
            )
            AND NOT EXISTS (
                SELECT [Customers_Id], [Products_Id]
                FROM [dbo].[{$table}]
                GROUP BY [Customers_Id], [Products_Id]
                HAVING COUNT(*) > 1
            )
            BEGIN
                CREATE UNIQUE INDEX [{$name}]
                    ON [dbo].[{$table}] ([Customers_Id], [Products_Id]);
            END
        ");
    }

    private function qualifiedTable(string $table): string
    {
        return "dbo.{$table}";
    }
};
