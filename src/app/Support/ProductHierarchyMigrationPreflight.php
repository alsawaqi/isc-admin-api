<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;

final class ProductHierarchyMigrationPreflight
{
    public static function assertDepartmentMetadata(): void
    {
        $table = 'Products_Departments_T';
        $hasSourceId = Schema::hasColumn($table, 'Source_Main_Id');
        $hasSequence = Schema::hasColumn($table, 'Source_Main_Sequence');
        $hasPeriod = Schema::hasColumn($table, 'Hierarchy_Code_Period');
        if (! $hasSourceId && ! $hasSequence && ! $hasPeriod) {
            return;
        }

        $columns = ['id'];
        foreach (['Source_Main_Id', 'Source_Main_Sequence', 'Hierarchy_Code_Period'] as $column) {
            if (Schema::hasColumn($table, $column)) {
                $columns[] = $column;
            }
        }

        $seenSequences = [];
        DB::table($table)->select($columns)->orderBy('id')->chunk(500, function ($rows) use (
            &$seenSequences,
            $hasSourceId,
            $hasSequence,
            $hasPeriod,
        ) {
            foreach ($rows as $row) {
                $sourceSequence = null;
                if ($hasSourceId && $row->Source_Main_Id !== null && trim((string) $row->Source_Main_Id) !== '') {
                    try {
                        $sourceSequence = ProductHierarchyCode::parseMainId((string) $row->Source_Main_Id)['sequence'];
                    } catch (InvalidArgumentException $exception) {
                        throw new RuntimeException("Cannot migrate invalid Source_Main_Id on department {$row->id}: {$exception->getMessage()}");
                    }
                }

                $storedSequence = null;
                if ($hasSequence && $row->Source_Main_Sequence !== null) {
                    $storedSequence = self::sequence($row->Source_Main_Sequence, "Source_Main_Sequence on department {$row->id}");
                }
                if ($sourceSequence !== null && $storedSequence !== null && $sourceSequence !== $storedSequence) {
                    throw new RuntimeException("Cannot migrate conflicting source identity metadata on department {$row->id}.");
                }

                $identitySequence = $storedSequence ?? $sourceSequence;
                if ($identitySequence !== null) {
                    if (isset($seenSequences[$identitySequence]) && $seenSequences[$identitySequence] !== (int) $row->id) {
                        throw new RuntimeException('Cannot add main-sequence uniqueness because numeric M-Id aliases already exist.');
                    }
                    $seenSequences[$identitySequence] = (int) $row->id;
                }

                if ($hasPeriod && $row->Hierarchy_Code_Period !== null && trim((string) $row->Hierarchy_Code_Period) !== '') {
                    try {
                        ProductHierarchyCode::normalizePeriod((string) $row->Hierarchy_Code_Period);
                    } catch (InvalidArgumentException $exception) {
                        throw new RuntimeException("Cannot migrate invalid hierarchy code period on department {$row->id}.");
                    }
                }
            }
        });
    }

    public static function assertChildSequences(string $table, string $parentColumn, string $sequenceColumn): void
    {
        if (! Schema::hasColumn($table, $sequenceColumn)) {
            return;
        }

        $seen = [];
        DB::table($table)
            ->select('id', $parentColumn, $sequenceColumn)
            ->whereNotNull($sequenceColumn)
            ->orderBy('id')
            ->chunk(500, function ($rows) use (&$seen, $parentColumn, $sequenceColumn) {
                foreach ($rows as $row) {
                    $sequence = self::sequence($row->{$sequenceColumn}, "{$sequenceColumn} on record {$row->id}");
                    $key = (string) $row->{$parentColumn}."\x1f".$sequence;
                    if (isset($seen[$key])) {
                        throw new RuntimeException("Cannot add parent-scoped {$sequenceColumn} uniqueness because duplicates already exist.");
                    }
                    $seen[$key] = true;
                }
            });
    }

    private static function sequence(mixed $value, string $label): int
    {
        if (! is_int($value) && ! ctype_digit((string) $value)) {
            throw new RuntimeException("{$label} must be an integer.");
        }

        $sequence = (int) $value;
        try {
            ProductHierarchyCode::sequence($sequence);
        } catch (InvalidArgumentException $exception) {
            throw new RuntimeException("{$label} must be between 1 and 999999.");
        }

        return $sequence;
    }
}
