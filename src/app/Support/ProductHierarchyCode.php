<?php

namespace App\Support;

use InvalidArgumentException;

final class ProductHierarchyCode
{
    private const MONTHS = [
        1 => 'JAN',
        2 => 'FEB',
        3 => 'MAR',
        4 => 'APR',
        5 => 'MAY',
        6 => 'JUN',
        7 => 'JUL',
        8 => 'AUG',
        9 => 'SEP',
        10 => 'OCT',
        11 => 'NOV',
        12 => 'DEC',
    ];

    /** @return array{source_id: string, sequence: int, sequence_code: string} */
    public static function parseMainId(string $value): array
    {
        $sourceId = HierarchyName::display($value);
        if (! preg_match('/^MAIN-(\d{4,6})$/D', $sourceId, $matches)) {
            throw new InvalidArgumentException('M-Id must use MAIN- followed by four to six digits.');
        }

        $sequence = (int) $matches[1];
        self::assertSequence($sequence, 'M-Id');

        return [
            'source_id' => $sourceId,
            'sequence' => $sequence,
            'sequence_code' => self::sequence($sequence),
        ];
    }

    public static function normalizePeriod(string $value): string
    {
        $period = trim($value);
        if (! preg_match('/^(20\d{2})-(0[1-9]|1[0-2])$/D', $period)) {
            throw new InvalidArgumentException('Code period must use YYYY-MM with a year between 2000 and 2099.');
        }

        return $period;
    }

    public static function department(string $period, int $mainSequence): string
    {
        return 'DEPT_'.self::periodSegment($period).'_MAIN_'.self::sequence($mainSequence);
    }

    public static function subDepartment(string $period, int $mainSequence, int $subSequence): string
    {
        return 'SUBDEPT_'.self::periodSegment($period)
            .'_MAIN_'.self::sequence($mainSequence)
            .'_SUB_'.self::sequence($subSequence);
    }

    public static function subSubDepartment(
        string $period,
        int $mainSequence,
        int $subSequence,
        int $subSubSequence,
    ): string {
        return 'SUBSUBDEPT_'.self::periodSegment($period)
            .'_MAIN_'.self::sequence($mainSequence)
            .'_SUB_'.self::sequence($subSequence)
            .'_SUBSUB_'.self::sequence($subSubSequence);
    }

    public static function sequence(int $value): string
    {
        self::assertSequence($value, 'Hierarchy sequence');

        return str_pad((string) $value, 6, '0', STR_PAD_LEFT);
    }

    private static function periodSegment(string $period): string
    {
        $normalized = self::normalizePeriod($period);
        [$year, $month] = array_map('intval', explode('-', $normalized));

        return sprintf('%04d_%s', $year, self::MONTHS[$month]);
    }

    private static function assertSequence(int $value, string $label): void
    {
        if ($value < 1 || $value > 999999) {
            throw new InvalidArgumentException("{$label} must be between 1 and 999999.");
        }
    }
}
