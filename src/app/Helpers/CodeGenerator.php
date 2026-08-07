<?php

namespace App\Helpers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CodeGenerator
{
    private const CATEGORY_SEQUENCE_SEGMENTS = [
        'DEPT' => 'MAIN',
        'SUBDEPT' => 'SUB',
        'SUBSUBDEPT' => 'SUBSUB',
    ];

    public static function createCode(string $prefix, string $table, string $column): string
    {
        $datePart = strtoupper(now()->format('Y_M')); // e.g., 2025_JAN
        $sequenceSegment = self::CATEGORY_SEQUENCE_SEGMENTS[$prefix] ?? 'A';

        $pattern = "{$prefix}_{$datePart}_{$sequenceSegment}_";

        $latest = DB::table($table)
            ->where($column, 'like', "{$pattern}%")
            ->orderBy($column, 'desc')
            ->value($column);

        if ($latest) {
            $number = (int) Str::afterLast($latest, '_') + 1;
        } else {
            $number = 1;
        }

        return sprintf('%s%06d', $pattern, $number); // pads to 000001 format
    }
}
