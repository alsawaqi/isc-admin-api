<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require '/var/www/html/vendor/autoload.php';

$application = require '/var/www/html/bootstrap/app.php';
$application->make(Kernel::class)->bootstrap();

$migrationName = '2026_08_07_000000_recode_product_hierarchy_database_codes';
if (Schema::hasTable('migrations') && DB::table('migrations')->where('migration', $migrationName)->exists()) {
    fwrite(STDOUT, "Hierarchy recode migration is already applied; preflight skipped.\n");
    exit(0);
}

$failures = [];
$required = [
    'Products_Departments_T' => [
        'id', 'Source_Main_Id', 'Source_Main_Sequence', 'Hierarchy_Code_Period',
    ],
    'Products_Sub_Department_T' => [
        'id', 'Products_Departments_Id', 'Source_Sub_Sequence',
    ],
    'Products_Sub_Sub_Department_T' => [
        'id', 'Product_Sub_Department_Id', 'Source_Sub_Sub_Sequence',
    ],
];

foreach ($required as $table => $columns) {
    if (! Schema::hasTable($table)) {
        $failures[] = "Missing required table {$table}.";

        continue;
    }

    foreach ($columns as $column) {
        if (! Schema::hasColumn($table, $column)) {
            $failures[] = "Missing required column {$table}.{$column}.";
        }
    }
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "Hierarchy recode preflight: {$failure}\n");
    }
    exit(1);
}

$validSequence = static function (mixed $value): ?int {
    if (! is_int($value) && ! ctype_digit((string) $value)) {
        return null;
    }

    $sequence = (int) $value;

    return $sequence >= 1 && $sequence <= 999999 ? $sequence : null;
};

$departments = [];
$departmentSequenceOwners = [];
foreach (DB::table('Products_Departments_T')
    ->orderBy('id')
    ->get(['id', 'Source_Main_Id', 'Source_Main_Sequence', 'Hierarchy_Code_Period']) as $row) {
    $id = (int) $row->id;
    $sequence = $validSequence($row->Source_Main_Sequence);
    $sourceMainId = trim((string) $row->Source_Main_Id);

    if ($id < 1 || $sequence === null) {
        $failures[] = "Department {$id} has an invalid Source_Main_Sequence.";

        continue;
    }
    if (! preg_match('/^MAIN-(\d{4,6})$/D', $sourceMainId, $matches) || (int) $matches[1] !== $sequence) {
        $failures[] = "Department {$id} has inconsistent Source_Main_Id metadata.";
    }
    if ((string) $row->Hierarchy_Code_Period !== '2026-08') {
        $failures[] = "Department {$id} is not assigned to hierarchy period 2026-08.";
    }
    if (isset($departmentSequenceOwners[$sequence])) {
        $failures[] = "Departments {$departmentSequenceOwners[$sequence]} and {$id} share main sequence {$sequence}.";
    }

    $departmentSequenceOwners[$sequence] = $id;
    $departments[$id] = true;
}

$subDepartments = [];
$subSequenceOwners = [];
foreach (DB::table('Products_Sub_Department_T')
    ->orderBy('id')
    ->get(['id', 'Products_Departments_Id', 'Source_Sub_Sequence']) as $row) {
    $id = (int) $row->id;
    $parentId = (int) $row->Products_Departments_Id;
    $sequence = $validSequence($row->Source_Sub_Sequence);

    if ($id < 1 || ! isset($departments[$parentId])) {
        $failures[] = "Sub-department {$id} has a missing department parent {$parentId}.";

        continue;
    }
    if ($sequence === null) {
        $failures[] = "Sub-department {$id} has an invalid Source_Sub_Sequence.";

        continue;
    }

    $ownerKey = $parentId.':'.$sequence;
    if (isset($subSequenceOwners[$ownerKey])) {
        $failures[] = "Sub-departments {$subSequenceOwners[$ownerKey]} and {$id} share local sequence {$sequence}.";
    }

    $subSequenceOwners[$ownerKey] = $id;
    $subDepartments[$id] = true;
}

$leafSequenceOwners = [];
$leafCount = 0;
foreach (DB::table('Products_Sub_Sub_Department_T')
    ->orderBy('id')
    ->get(['id', 'Product_Sub_Department_Id', 'Source_Sub_Sub_Sequence']) as $row) {
    $leafCount++;
    $id = (int) $row->id;
    $parentId = (int) $row->Product_Sub_Department_Id;
    $sequence = $validSequence($row->Source_Sub_Sub_Sequence);

    if ($id < 1 || ! isset($subDepartments[$parentId])) {
        $failures[] = "Sub-sub-department {$id} has a missing sub-department parent {$parentId}.";

        continue;
    }
    if ($sequence === null) {
        $failures[] = "Sub-sub-department {$id} has an invalid Source_Sub_Sub_Sequence.";

        continue;
    }

    $ownerKey = $parentId.':'.$sequence;
    if (isset($leafSequenceOwners[$ownerKey])) {
        $failures[] = "Sub-sub-departments {$leafSequenceOwners[$ownerKey]} and {$id} share local sequence {$sequence}.";
    }

    $leafSequenceOwners[$ownerKey] = $id;
}

if (count($subDepartments) > 999999) {
    $failures[] = 'There are too many sub-departments for six-digit database codes.';
}
if ($leafCount > 999999) {
    $failures[] = 'There are too many sub-sub-departments for six-digit database codes.';
}

if ($failures !== []) {
    foreach (array_slice($failures, 0, 50) as $failure) {
        fwrite(STDERR, "Hierarchy recode preflight: {$failure}\n");
    }
    if (count($failures) > 50) {
        fwrite(STDERR, 'Hierarchy recode preflight: '.(count($failures) - 50)." additional problem(s) omitted.\n");
    }
    exit(1);
}

fwrite(
    STDOUT,
    'Hierarchy recode preflight passed: '.count($departments).' departments, '
    .count($subDepartments).' sub-departments, '.$leafCount." sub-sub-departments.\n",
);
