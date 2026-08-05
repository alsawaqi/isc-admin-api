<?php

namespace App\Services;

use App\Support\HierarchyName;
use App\Support\ProductHierarchyCode;
use DOMDocument;
use DOMElement;
use DOMXPath;
use InvalidArgumentException;
use RuntimeException;
use XMLReader;
use ZipArchive;

class ProductHierarchyXlsxParser
{
    private const MAX_ARCHIVE_ENTRIES = 250;

    private const MAX_TOTAL_UNCOMPRESSED_BYTES = 52_428_800;

    private const MAX_ENTRY_UNCOMPRESSED_BYTES = 20_971_520;

    private const MAX_COMPRESSION_RATIO = 200;

    private const MAX_ROWS = 20_000;

    private const MAX_COLUMNS = 64;

    private const MAX_ROW_XML_BYTES = 262_144;

    private const MAX_ROW_TAG_MARKERS = 4_096;

    private const MAX_SHARED_STRINGS = 100_000;

    private const MAX_SHARED_STRING_XML_BYTES = 262_144;

    private const MAX_DOM_XML_BYTES = 1_048_576;

    private const MAX_DOM_TAG_MARKERS = 20_000;

    private const REQUIRED_HEADERS = ['main_id', 'main_group', 'sub_group', 'sub_sub_category'];

    /**
     * Parse and normalize an XLSX product hierarchy without evaluating formulas.
     *
     * @return array<string, mixed>
     */
    public function parse(string $path): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP zip extension is required to read XLSX files.');
        }

        $zip = new ZipArchive;
        $result = $zip->open($path, ZipArchive::RDONLY);

        if ($result !== true) {
            throw new RuntimeException('The uploaded file is not a valid XLSX archive.');
        }

        try {
            $this->validateArchive($zip);
            $sharedStrings = $this->readSharedStrings($zip);
            [$sheetName, $sheetPath] = $this->findHierarchySheet($zip, $sharedStrings);
            $rows = $this->readSheetRows($zip, $sheetPath, $sharedStrings);

            return $this->normalizeRows($sheetName, $rows);
        } finally {
            $zip->close();
        }
    }

    private function validateArchive(ZipArchive $zip): void
    {
        if ($zip->numFiles < 1 || $zip->numFiles > self::MAX_ARCHIVE_ENTRIES) {
            throw new RuntimeException('The XLSX archive contains an unsafe number of entries.');
        }

        $totalSize = 0;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);

            if (! is_array($stat) || ! isset($stat['name'])) {
                throw new RuntimeException('The XLSX archive contains an unreadable entry.');
            }

            $name = str_replace('\\', '/', (string) $stat['name']);
            $size = max(0, (int) ($stat['size'] ?? 0));
            $compressedSize = max(0, (int) ($stat['comp_size'] ?? 0));

            if ($name === '' || str_contains($name, "\0") || str_starts_with($name, '/') || preg_match('#(^|/)\.\.(/|$)#', $name)) {
                throw new RuntimeException('The XLSX archive contains an unsafe path.');
            }

            $lowerName = mb_strtolower($name);
            if (
                str_ends_with($lowerName, 'vbaproject.bin')
                || str_contains($lowerName, '/macrosheets/')
                || str_contains($lowerName, '/externallinks/')
                || str_contains($lowerName, '/embeddings/')
                || str_contains($lowerName, '/activex/')
                || $lowerName === 'encryptedpackage'
                || $lowerName === 'encryptioninfo'
            ) {
                throw new RuntimeException('Macro-enabled, encrypted, embedded, or externally linked workbooks are not accepted.');
            }

            if ($size > self::MAX_ENTRY_UNCOMPRESSED_BYTES) {
                throw new RuntimeException('An XLSX archive entry exceeds the allowed size.');
            }

            $totalSize += $size;
            if ($totalSize > self::MAX_TOTAL_UNCOMPRESSED_BYTES) {
                throw new RuntimeException('The XLSX archive expands beyond the allowed size.');
            }

            if ($size > 1_048_576 && ($compressedSize === 0 || ($size / $compressedSize) > self::MAX_COMPRESSION_RATIO)) {
                throw new RuntimeException('The XLSX archive has an unsafe compression ratio.');
            }

            if (str_ends_with($lowerName, '.xml') || str_ends_with($lowerName, '.rels')) {
                $xml = $zip->getFromIndex($index);
                if ($xml === false || preg_match('/<!\s*(?:DOCTYPE|ENTITY)\b/i', $xml)) {
                    throw new RuntimeException('The XLSX archive contains unsafe XML.');
                }

                if (str_ends_with($lowerName, '.rels')) {
                    $this->rejectExternalRelationships($xml);
                }
            }
        }

        foreach (['[Content_Types].xml', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels'] as $requiredEntry) {
            if ($zip->locateName($requiredEntry, ZipArchive::FL_NOCASE) === false) {
                throw new RuntimeException('The archive is missing required XLSX workbook data.');
            }
        }

        $contentTypes = $this->getEntry($zip, '[Content_Types].xml');
        if (preg_match('/macroenabled|vbaproject/i', $contentTypes)) {
            throw new RuntimeException('Macro-enabled workbooks are not accepted.');
        }
    }

    private function rejectExternalRelationships(string $xml): void
    {
        $document = $this->loadXml($xml);
        $xpath = new DOMXPath($document);

        foreach ($xpath->query('//*[local-name()="Relationship"]') ?: [] as $relationship) {
            if ($relationship instanceof DOMElement && strcasecmp($relationship->getAttribute('TargetMode'), 'External') === 0) {
                throw new RuntimeException('Workbooks containing external relationships are not accepted.');
            }
        }
    }

    /** @return array<int, string> */
    private function readSharedStrings(ZipArchive $zip): array
    {
        $index = $zip->locateName('xl/sharedStrings.xml', ZipArchive::FL_NOCASE);
        if ($index === false) {
            return [];
        }

        $xml = $this->getEntryByIndex($zip, $index);
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $reader = new XMLReader;

        try {
            if (! $reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT)) {
                throw new RuntimeException('The XLSX archive contains malformed XML.');
            }

            $strings = [];
            while ($reader->read()) {
                if (
                    $reader->nodeType !== XMLReader::ELEMENT
                    || $reader->localName !== 'si'
                    || $reader->depth !== 1
                ) {
                    continue;
                }

                if (count($strings) >= self::MAX_SHARED_STRINGS) {
                    throw new RuntimeException('The XLSX shared-string table is too large.');
                }

                $itemXml = $reader->readOuterXml();
                if (
                    strlen($itemXml) > self::MAX_SHARED_STRING_XML_BYTES
                    || substr_count($itemXml, '<') > self::MAX_DOM_TAG_MARKERS
                ) {
                    throw new RuntimeException('An XLSX shared string is too complex.');
                }

                $document = $this->loadXml($itemXml);
                $xpath = new DOMXPath($document);
                $value = '';
                foreach ($xpath->query('//*[local-name()="t"]') ?: [] as $textNode) {
                    $value .= $textNode->textContent;
                }
                $strings[] = $value;
            }

            if (libxml_get_errors() !== []) {
                throw new RuntimeException('The XLSX archive contains malformed XML.');
            }

            return $strings;
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** @return array{0: string, 1: string} */
    private function findHierarchySheet(ZipArchive $zip, array $sharedStrings): array
    {
        $workbook = $this->loadXml($this->getEntry($zip, 'xl/workbook.xml'));
        $relationships = $this->loadXml($this->getEntry($zip, 'xl/_rels/workbook.xml.rels'));
        $relationshipXpath = new DOMXPath($relationships);
        $targets = [];

        foreach ($relationshipXpath->query('//*[local-name()="Relationship"]') ?: [] as $relationship) {
            if (! $relationship instanceof DOMElement) {
                continue;
            }

            $id = $relationship->getAttribute('Id');
            $target = $relationship->getAttribute('Target');
            if ($id !== '' && $target !== '') {
                $targets[$id] = $this->resolveWorkbookTarget($target);
            }
        }

        $workbookXpath = new DOMXPath($workbook);
        $sheets = [];

        foreach ($workbookXpath->query('//*[local-name()="sheet"]') ?: [] as $sheet) {
            if (! $sheet instanceof DOMElement) {
                continue;
            }

            $relationshipId = $sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
            $path = $targets[$relationshipId] ?? null;

            if ($path && str_starts_with(mb_strtolower($path), 'xl/worksheets/') && str_ends_with(mb_strtolower($path), '.xml')) {
                $sheets[] = ['name' => $sheet->getAttribute('name'), 'path' => $path];
            }
        }

        if ($sheets === []) {
            throw new RuntimeException('The workbook does not contain a readable worksheet.');
        }

        foreach ($sheets as $sheet) {
            if ($this->key($sheet['name']) === $this->key('Main Groups & Sub')) {
                return [$sheet['name'], $sheet['path']];
            }
        }

        foreach ($sheets as $sheet) {
            $rows = $this->readSheetRows($zip, $sheet['path'], $sharedStrings, 30);
            if ($this->findHeader($rows) !== null) {
                return [$sheet['name'], $sheet['path']];
            }
        }

        throw new RuntimeException('No worksheet contains the required M-Id, Main Group, Sub Group, and Sub Sub Categories headers.');
    }

    private function resolveWorkbookTarget(string $target): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $target)) {
            throw new RuntimeException('The workbook contains an unsafe worksheet target.');
        }

        $candidate = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target;
        $parts = [];

        foreach (explode('/', str_replace('\\', '/', $candidate)) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if ($parts === []) {
                    throw new RuntimeException('The workbook contains an unsafe worksheet path.');
                }
                array_pop($parts);

                continue;
            }
            $parts[] = $part;
        }

        return implode('/', $parts);
    }

    /** @return array<int, array{row: int, cells: array<int, string>, formulas: array<int, string>}> */
    private function readSheetRows(ZipArchive $zip, string $sheetPath, array $sharedStrings, ?int $limit = null): array
    {
        $xml = $this->getEntry($zip, $sheetPath);
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $reader = new XMLReader;
        $rows = [];
        $fallbackRow = 0;
        $sheetDataDepth = null;

        try {
            if (! $reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT)) {
                throw new RuntimeException('The XLSX archive contains malformed XML.');
            }

            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'sheetData') {
                    $sheetDataDepth = $reader->depth;

                    continue;
                }
                if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'sheetData') {
                    $sheetDataDepth = null;

                    continue;
                }
                if (
                    $reader->nodeType !== XMLReader::ELEMENT
                    || $reader->localName !== 'row'
                    || $sheetDataDepth === null
                    || $reader->depth !== $sheetDataDepth + 1
                ) {
                    continue;
                }

                $fallbackRow++;
                if ($fallbackRow > self::MAX_ROWS) {
                    throw new RuntimeException('The workbook exceeds the maximum supported number of rows.');
                }

                $rowNumber = (int) ($reader->getAttribute('r') ?: $fallbackRow);
                if ($rowNumber > self::MAX_ROWS) {
                    throw new RuntimeException('The workbook exceeds the maximum supported row number.');
                }

                $rowXml = $reader->readOuterXml();
                if (
                    strlen($rowXml) > self::MAX_ROW_XML_BYTES
                    || substr_count($rowXml, '<') > self::MAX_ROW_TAG_MARKERS
                ) {
                    throw new RuntimeException('A worksheet row is too large or complex.');
                }

                $rowDocument = $this->loadXml($rowXml);
                $rowNode = $rowDocument->documentElement;
                if (! $rowNode instanceof DOMElement) {
                    throw new RuntimeException('The XLSX archive contains malformed worksheet rows.');
                }

                $xpath = new DOMXPath($rowDocument);
                $cells = [];
                $formulas = [];
                foreach ($xpath->query('./*[local-name()="c"]', $rowNode) ?: [] as $cellNode) {
                    if (! $cellNode instanceof DOMElement) {
                        continue;
                    }

                    $reference = $cellNode->getAttribute('r');
                    if (! preg_match('/^([A-Z]+)\d+$/i', $reference, $matches)) {
                        continue;
                    }

                    $column = $this->columnNumber($matches[1]);
                    if ($column < 1 || $column > self::MAX_COLUMNS) {
                        continue;
                    }

                    if (($xpath->query('./*[local-name()="f"]', $cellNode)?->length ?? 0) > 0) {
                        // Formula helpers outside imported columns are ignored, never evaluated.
                        $formulas[$column] = $reference;
                        $cells[$column] = '';

                        continue;
                    }

                    $type = $cellNode->getAttribute('t');
                    if ($type === 'inlineStr') {
                        $value = '';
                        foreach ($xpath->query('.//*[local-name()="t"]', $cellNode) ?: [] as $textNode) {
                            $value .= $textNode->textContent;
                        }
                    } else {
                        $valueNode = $xpath->query('./*[local-name()="v"]', $cellNode)?->item(0);
                        $value = $valueNode?->textContent ?? '';
                        if ($type === 's' && $value !== '') {
                            $value = $sharedStrings[(int) $value] ?? '';
                        }
                    }

                    $cells[$column] = (string) $value;
                }

                $rows[] = ['row' => $rowNumber, 'cells' => $cells, 'formulas' => $formulas];
                if ($limit !== null && count($rows) >= $limit) {
                    break;
                }
            }

            if ($limit === null && libxml_get_errors() !== []) {
                throw new RuntimeException('The XLSX archive contains malformed XML.');
            }

            return $rows;
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** @param array<int, array{row: int, cells: array<int, string>, formulas: array<int, string>}> $rows */
    private function normalizeRows(string $sheetName, array $rows): array
    {
        $header = $this->findHeader($rows);
        if ($header === null) {
            throw new RuntimeException('The hierarchy worksheet is missing one or more required headers.');
        }

        $issues = [];
        $hierarchy = [];
        $seenPaths = [];
        $mainNameToSequence = [];
        $mainSequenceToIdentity = [];
        $activeMainId = null;
        $activeSubGroup = null;
        $rowsRead = 0;
        $separatorRows = 0;
        $blankLeafRows = 0;
        $validPaths = 0;
        $duplicatePaths = 0;

        foreach ($rows as $row) {
            if ($row['row'] <= $header['row']) {
                continue;
            }

            $formulaCell = null;
            foreach ($header['columns'] as $column) {
                if (isset($row['formulas'][$column])) {
                    $formulaCell = $row['formulas'][$column];
                    break;
                }
            }
            if ($formulaCell !== null) {
                $rowsRead++;
                $this->issue($issues, 'error', $row['row'], 'formula_in_hierarchy_cell', "Formula cells are not accepted as hierarchy data ({$formulaCell}).");
                $activeMainId = null;
                $activeSubGroup = null;

                continue;
            }

            $values = [];
            foreach ($header['columns'] as $field => $column) {
                $values[$field] = $this->display($row['cells'][$column] ?? '');
            }

            if (implode('', $values) === '') {
                $separatorRows++;

                continue;
            }

            $rowsRead++;
            $mainId = $values['main_id'];
            $mainGroup = $values['main_group'];
            $subGroup = $values['sub_group'];
            $leaf = $values['sub_sub_category'];
            $mainIsInherited = false;

            if ($mainId === '') {
                [$mainId, $mainIdKey, $mainIsInherited] = $this->resolveBlankMainId(
                    $mainGroup,
                    (string) ($values['main_number'] ?? ''),
                    $activeMainId,
                    $hierarchy,
                    $issues,
                    $row['row'],
                );
                if ($mainId === null) {
                    $activeMainId = null;
                    $activeSubGroup = null;

                    continue;
                }

                if ($mainIsInherited && $this->hasOverlongName($subGroup, $leaf)) {
                    $this->issue($issues, 'error', $row['row'], 'name_too_long', 'Department names must be 255 characters or fewer.');

                    continue;
                }
            }

            if (! $mainIsInherited) {
                try {
                    $mainIdentity = ProductHierarchyCode::parseMainId($mainId);
                } catch (InvalidArgumentException $exception) {
                    $this->issue($issues, 'error', $row['row'], 'invalid_main_id', $exception->getMessage());
                    $activeMainId = null;
                    $activeSubGroup = null;

                    continue;
                }

                $mainId = $mainIdentity['source_id'];
                $mainSequence = $mainIdentity['sequence'];
                $mainIdKey = (string) $mainSequence;

                if ($mainGroup === '') {
                    $this->issue($issues, 'error', $row['row'], 'missing_main_group', 'Main Group is required when M-Id is present.');
                    $activeMainId = null;
                    $activeSubGroup = null;

                    continue;
                }

                if ($this->hasOverlongName($mainGroup, $subGroup, $leaf)) {
                    $this->issue($issues, 'error', $row['row'], 'name_too_long', 'Department names must be 255 characters or fewer.');

                    continue;
                }

                $mainNameKey = $this->key($mainGroup);

                if (
                    isset($mainSequenceToIdentity[$mainIdKey])
                    && $mainSequenceToIdentity[$mainIdKey]['main_id'] !== $mainId
                ) {
                    $first = $mainSequenceToIdentity[$mainIdKey];
                    $this->issue(
                        $issues,
                        'error',
                        $row['row'],
                        'main_id_alias_conflict',
                        "M-Id {$mainId} is a numeric alias of {$first['main_id']} from row {$first['row']}; use one spelling consistently."
                    );
                    $activeMainId = null;
                    $activeSubGroup = null;

                    continue;
                }

                if (! isset($mainSequenceToIdentity[$mainIdKey])) {
                    $mainSequenceToIdentity[$mainIdKey] = [
                        'main_id' => $mainId,
                        'name' => $mainGroup,
                        'row' => $row['row'],
                    ];
                }

                if ($activeMainId !== $mainIdKey) {
                    $activeMainId = $mainIdKey;
                    $activeSubGroup = null;
                }

                if (isset($hierarchy[$mainIdKey]) && $this->key($hierarchy[$mainIdKey]['name']) !== $mainNameKey) {
                    $this->issue($issues, 'error', $row['row'], 'main_id_name_conflict', "M-Id {$mainId} is assigned to more than one Main Group name.");

                    continue;
                }

                if (isset($mainNameToSequence[$mainNameKey]) && $mainNameToSequence[$mainNameKey] !== $mainIdKey) {
                    $this->issue($issues, 'error', $row['row'], 'main_name_id_conflict', "Main Group {$mainGroup} is assigned to more than one M-Id.");

                    continue;
                }

                $mainNameToSequence[$mainNameKey] = $mainIdKey;
                if (! isset($hierarchy[$mainIdKey])) {
                    $hierarchy[$mainIdKey] = [
                        'main_id' => $mainId,
                        'main_sequence' => $mainSequence,
                        'name' => $mainGroup,
                        'first_row' => $row['row'],
                        'sub_departments' => [],
                    ];
                }
            }

            if ($subGroup === '' && $leaf === '') {
                $separatorRows++;

                continue;
            }

            if ($subGroup !== '') {
                $activeSubGroup = $subGroup;
            } elseif ($leaf !== '' && $activeSubGroup === null) {
                $this->issue($issues, 'error', $row['row'], 'missing_sub_group', 'Sub Group is blank and cannot be inherited before a valid Sub Group.');

                continue;
            }

            $effectiveSubGroup = $subGroup !== '' ? $subGroup : (string) $activeSubGroup;
            if ($effectiveSubGroup === '') {
                $this->issue($issues, 'error', $row['row'], 'missing_sub_group', 'Sub Group is required.');

                continue;
            }

            $subKey = $this->key($effectiveSubGroup);
            $hierarchy[$mainIdKey]['sub_departments'][$subKey] ??= [
                'name' => $effectiveSubGroup,
                'first_row' => $row['row'],
                'sub_sub_departments' => [],
            ];

            if ($leaf === '') {
                $blankLeafRows++;

                continue;
            }

            $leafKey = $this->key($leaf);
            $pathKey = $mainIdKey."\x1f".$subKey."\x1f".$leafKey;
            if (isset($seenPaths[$pathKey])) {
                $duplicatePaths++;
                $this->issue(
                    $issues,
                    'warning',
                    $row['row'],
                    'duplicate_path',
                    "Duplicate hierarchy path; the first occurrence is on row {$seenPaths[$pathKey]}."
                );

                continue;
            }

            $seenPaths[$pathKey] = $row['row'];
            $hierarchy[$mainIdKey]['sub_departments'][$subKey]['sub_sub_departments'][$leafKey] = [
                'name' => $leaf,
                'first_row' => $row['row'],
            ];
            $validPaths++;
        }

        if ($hierarchy === []) {
            $this->issue($issues, 'error', $header['row'], 'no_hierarchy_rows', 'The worksheet does not contain any hierarchy records.');
        }

        $subDepartmentCount = 0;
        foreach ($hierarchy as $department) {
            $subDepartmentCount += count($department['sub_departments']);
        }

        return [
            'sheet' => $sheetName,
            'header_row' => $header['row'],
            'rows_read' => $rowsRead,
            'separator_rows' => $separatorRows,
            'blank_leaf_rows' => $blankLeafRows,
            'valid_paths' => $validPaths,
            'duplicate_paths' => $duplicatePaths,
            'departments' => count($hierarchy),
            'sub_departments' => $subDepartmentCount,
            'sub_sub_departments' => $validPaths,
            'issues' => $issues,
            'hierarchy' => $hierarchy,
        ];
    }

    /** @param array<int, array{row: int, cells: array<int, string>, formulas: array<int, string>}> $rows */
    private function findHeader(array $rows): ?array
    {
        foreach (array_slice($rows, 0, 30) as $row) {
            $columns = [];
            foreach ($row['cells'] as $column => $value) {
                $canonical = $this->canonicalHeader($value);
                if ($canonical !== null && ! isset($columns[$canonical])) {
                    $columns[$canonical] = $column;
                }
            }

            if (array_diff(self::REQUIRED_HEADERS, array_keys($columns)) === []) {
                return ['row' => $row['row'], 'columns' => $columns];
            }
        }

        return null;
    }

    private function canonicalHeader(string $value): ?string
    {
        $header = preg_replace('/[^a-z0-9]+/', '', mb_strtolower($this->display($value))) ?? '';

        return match ($header) {
            'no', 'srno', 'serialno', 'serialnumber' => 'main_number',
            'mid', 'mainid' => 'main_id',
            'maingroup' => 'main_group',
            'subgroup' => 'sub_group',
            'subsubcategories', 'subsubcategory', 'subsubgroup' => 'sub_sub_category',
            default => null,
        };
    }

    private function mainIdFromNumber(string $value): ?string
    {
        $number = $this->display($value);
        if (! preg_match('/^\d+(?:\.0+)?$/D', $number)) {
            return null;
        }

        $sequence = (int) $number;
        if ($sequence < 1 || $sequence > 999999) {
            return null;
        }

        return 'MAIN-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    private function resolveBlankMainId(string $mainGroup, string $mainNumber, ?string $activeMainId, array $hierarchy, array &$issues, int $row): array
    {
        if ($mainGroup === '') {
            if ($activeMainId !== null && isset($hierarchy[$activeMainId])) {
                return ['', $activeMainId, true];
            }
            $this->issue($issues, 'error', $row, 'missing_main_id', 'M-Id and Main Group are blank and cannot be inherited before a valid main group.');

            return [null, null, false];
        }

        return $this->inferMissingMainId($mainGroup, $mainNumber, $issues, $row);
    }

    private function inferMissingMainId(string $mainGroup, string $mainNumber, array &$issues, int $row): array
    {
        $mainId = $this->mainIdFromNumber($mainNumber);
        if ($mainId === null) {
            $this->issue($issues, 'error', $row, 'missing_main_id', 'M-Id is required when Main Group is present.');

            return [null, null, false];
        }

        $this->issue($issues, 'warning', $row, 'missing_main_id_inferred', 'M-Id inferred from the No column.');

        return [$mainId, null, false];
    }

    private function columnNumber(string $letters): int
    {
        $number = 0;
        foreach (str_split(strtoupper($letters)) as $letter) {
            $number = ($number * 26) + (ord($letter) - 64);
        }

        return $number;
    }

    private function display(string $value): string
    {
        return HierarchyName::display($value);
    }

    public function key(string $value): string
    {
        return HierarchyName::key($value);
    }

    private function hasOverlongName(string ...$values): bool
    {
        foreach ($values as $value) {
            if (mb_strlen($value) > 255) {
                return true;
            }
        }

        return false;
    }

    private function issue(array &$issues, string $severity, int $row, string $code, string $message): void
    {
        $issues[] = compact('severity', 'row', 'code', 'message');
    }

    private function getEntry(ZipArchive $zip, string $name): string
    {
        $index = $zip->locateName($name, ZipArchive::FL_NOCASE);
        if ($index === false) {
            throw new RuntimeException("The XLSX entry {$name} is missing.");
        }

        return $this->getEntryByIndex($zip, $index);
    }

    private function getEntryByIndex(ZipArchive $zip, int $index): string
    {
        $contents = $zip->getFromIndex($index);
        if ($contents === false) {
            throw new RuntimeException('An XLSX entry could not be read.');
        }

        return $contents;
    }

    private function loadXml(string $xml): DOMDocument
    {
        if (
            strlen($xml) > self::MAX_DOM_XML_BYTES
            || substr_count($xml, '<') > self::MAX_DOM_TAG_MARKERS
        ) {
            throw new RuntimeException('The XLSX XML structure is too large or complex.');
        }

        if (preg_match('/<!\s*(?:DOCTYPE|ENTITY)\b/i', $xml)) {
            throw new RuntimeException('The XLSX archive contains unsafe XML.');
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            $document->preserveWhiteSpace = true;
            if (! $document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT)) {
                throw new RuntimeException('The XLSX archive contains malformed XML.');
            }

            return $document;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
