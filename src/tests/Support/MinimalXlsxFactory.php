<?php

namespace Tests\Support;

use RuntimeException;
use ZipArchive;

final class MinimalXlsxFactory
{
    /**
     * @param  array<int, array<string, string|array{formula: string, value?: string}>>  $rows
     * @param  array<string, string>  $extraEntries
     * @param  list<string>  $removeEntries
     */
    public static function write(
        string $path,
        array $rows,
        array $extraEntries = [],
        array $removeEntries = [],
        ?string $workbookRelationships = null,
    ): string {
        $entries = [
            '[Content_Types].xml' => self::contentTypes(),
            'xl/workbook.xml' => self::workbook(),
            'xl/_rels/workbook.xml.rels' => $workbookRelationships ?? self::workbookRelationships(),
            'xl/worksheets/sheet1.xml' => self::worksheet($rows),
        ];

        foreach ($removeEntries as $entry) {
            unset($entries[$entry]);
        }

        $entries = [...$entries, ...$extraEntries];

        $zip = new ZipArchive;
        $opened = $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($opened !== true) {
            throw new RuntimeException("Unable to create test workbook at {$path}.");
        }

        try {
            foreach ($entries as $name => $contents) {
                if (! $zip->addFromString($name, $contents)) {
                    throw new RuntimeException("Unable to add {$name} to the test workbook.");
                }
            }
        } finally {
            $zip->close();
        }

        return $path;
    }

    /** @return array<string, string> */
    public static function hierarchyHeader(): array
    {
        return [
            'C1' => 'M-Id',
            'D1' => 'Main Group',
            'F1' => 'Sub Group',
            'G1' => 'Sub Sub Categories',
        ];
    }

    private static function contentTypes(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
</Types>
XML;
    }

    private static function workbook(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"
          xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="Main Groups &amp; Sub" sheetId="1" r:id="rId1"/>
  </sheets>
</workbook>
XML;
    }

    private static function workbookRelationships(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1"
                Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"
                Target="worksheets/sheet1.xml"/>
</Relationships>
XML;
    }

    /** @param array<int, array<string, string|array{formula: string, value?: string}>> $rows */
    private static function worksheet(array $rows): string
    {
        ksort($rows);
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

        foreach ($rows as $rowNumber => $cells) {
            $xml .= '<row r="'.(int) $rowNumber.'">';
            foreach ($cells as $reference => $cell) {
                if (is_array($cell)) {
                    $formula = self::escape($cell['formula']);
                    $value = self::escape($cell['value'] ?? '');
                    $xml .= "<c r=\"{$reference}\"><f>{$formula}</f><v>{$value}</v></c>";

                    continue;
                }

                $value = self::escape($cell);
                $xml .= "<c r=\"{$reference}\" t=\"inlineStr\"><is><t xml:space=\"preserve\">{$value}</t></is></c>";
            }
            $xml .= '</row>';
        }

        return $xml.'</sheetData></worksheet>';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
