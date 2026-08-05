<?php

namespace App\Support;

final class CommerceTestDataResetSafetyPolicy
{
    /**
     * Disabled constraints are fatal. An enabled but untrusted constraint is
     * a warning because DBCC CHECKCONSTRAINTS independently validates its data.
     *
     * @param  list<object|array<string, mixed>>  $constraints
     * @return array{disabled: list<object|array<string, mixed>>, untrusted: list<object|array<string, mixed>>}
     */
    public static function classifyConstraintHealth(array $constraints): array
    {
        $disabled = [];
        $untrusted = [];

        foreach ($constraints as $constraint) {
            if (self::flag($constraint, 'IsDisabled')) {
                $disabled[] = $constraint;

                continue;
            }

            if (self::flag($constraint, 'IsNotTrusted')) {
                $untrusted[] = $constraint;
            }
        }

        return [
            'disabled' => $disabled,
            'untrusted' => $untrusted,
        ];
    }

    /**
     * @param  list<mixed>  $unexpectedForeignKeys
     * @param  list<mixed>  $disabledConstraints
     * @param  list<mixed>  $constraintViolations
     */
    public static function blocksReset(
        array $unexpectedForeignKeys,
        array $disabledConstraints,
        array $constraintViolations,
    ): bool {
        return $unexpectedForeignKeys !== []
            || $disabledConstraints !== []
            || $constraintViolations !== [];
    }

    /** @param object|array<string, mixed> $constraint */
    private static function flag(object|array $constraint, string $field): bool
    {
        $value = is_array($constraint)
            ? ($constraint[$field] ?? null)
            : ($constraint->{$field} ?? null);

        return $value === true || (is_numeric($value) && (int) $value === 1);
    }
}
