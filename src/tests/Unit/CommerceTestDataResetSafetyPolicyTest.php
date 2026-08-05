<?php

namespace Tests\Unit;

use App\Support\CommerceTestDataResetSafetyPolicy;
use PHPUnit\Framework\TestCase;

final class CommerceTestDataResetSafetyPolicyTest extends TestCase
{
    public function test_disabled_constraint_is_fatal_even_when_it_is_also_untrusted(): void
    {
        $constraint = (object) [
            'IsDisabled' => 1,
            'IsNotTrusted' => 1,
        ];

        $health = CommerceTestDataResetSafetyPolicy::classifyConstraintHealth([$constraint]);

        self::assertSame([$constraint], $health['disabled']);
        self::assertSame([], $health['untrusted']);
        self::assertTrue(CommerceTestDataResetSafetyPolicy::blocksReset([], $health['disabled'], []));
    }

    public function test_enabled_but_untrusted_constraint_is_a_non_blocking_warning(): void
    {
        $constraint = [
            'IsDisabled' => 0,
            'IsNotTrusted' => '1',
        ];

        $health = CommerceTestDataResetSafetyPolicy::classifyConstraintHealth([$constraint]);

        self::assertSame([], $health['disabled']);
        self::assertSame([$constraint], $health['untrusted']);
        self::assertFalse(CommerceTestDataResetSafetyPolicy::blocksReset([], $health['disabled'], []));
    }

    public function test_trusted_enabled_constraint_has_no_health_finding(): void
    {
        $health = CommerceTestDataResetSafetyPolicy::classifyConstraintHealth([[
            'IsDisabled' => 0,
            'IsNotTrusted' => 0,
        ]]);

        self::assertSame(['disabled' => [], 'untrusted' => []], $health);
    }

    public function test_actual_dbcc_violation_is_fatal(): void
    {
        self::assertTrue(CommerceTestDataResetSafetyPolicy::blocksReset(
            [],
            [],
            [(object) ['Constraint' => 'FK_Broken']],
        ));
    }

    public function test_unexpected_foreign_key_child_is_fatal(): void
    {
        self::assertTrue(CommerceTestDataResetSafetyPolicy::blocksReset(
            [(object) ['ConstraintName' => 'FK_Unexpected']],
            [],
            [],
        ));
    }

    public function test_clean_preflight_is_not_blocked(): void
    {
        self::assertFalse(CommerceTestDataResetSafetyPolicy::blocksReset([], [], []));
    }
}
