<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;

/**
 * Base class for HTTP feature tests.
 *
 * Runs against the real (shared) SQL Server `isc` connection but wraps every
 * test in a transaction that is rolled back afterwards (DatabaseTransactions),
 * so no test data persists. There are no migrations to build a throwaway DB,
 * which is why we test against the live schema with rollback.
 */
abstract class FeatureTestCase extends TestCase
{
    use DatabaseTransactions;

    /**
     * Authenticate as an admin user (Sanctum). Reuses an existing admin row to
     * avoid depending on NOT NULL columns of Secx_Admin_User_Master_T.
     */
    protected function actingAsAdmin(): User
    {
        $user = User::query()->first();

        if (! $user) {
            $user = User::create([
                'User_Id'   => 'TEST_' . uniqid(),
                'User_Name' => 'Test Admin',
                'Email'     => 'test_' . uniqid() . '@example.com',
                'Password'  => 'password',
                'Status'    => 'active',
            ]);
        }

        Sanctum::actingAs($user, ['*']);

        return $user;
    }
}
