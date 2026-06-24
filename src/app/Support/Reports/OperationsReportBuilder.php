<?php

namespace App\Support\Reports;

class OperationsReportBuilder
{
    /**
     * @param array<string, array<int, array<string, mixed>>> $rowsByReport
     * @return array<string, mixed>
     */
    public static function summaryFromRows(array $rowsByReport): array
    {
        $netRows = $rowsByReport['net_sales'] ?? [];
        $payoutRows = $rowsByReport['payouts'] ?? [];

        $paidPayout = 0.0;
        $pendingPayout = 0.0;

        foreach ($payoutRows as $row) {
            $amount = self::number($row['payout_amount'] ?? 0);
            $status = strtolower((string) ($row['payout_status'] ?? 'unpaid'));

            if ($status === 'paid') {
                $paidPayout += $amount;
            } else {
                $pendingPayout += $amount;
            }
        }

        return [
            'sold_amount' => self::money(self::sum($netRows, 'sold_amount')),
            'refunded_amount' => self::money(self::sum($netRows, 'refunded_amount')),
            'returned_quantity' => (int) self::sum($netRows, 'returned_quantity'),
            'net_amount' => self::money(self::sum($netRows, 'net_amount')),
            'paid_payout_amount' => self::money($paidPayout),
            'pending_payout_amount' => self::money($pendingPayout),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public static function toCsv(array $rows): string
    {
        if (empty($rows)) {
            return '';
        }

        $headers = array_keys($rows[0]);
        $lines = [self::csvLine($headers)];

        foreach ($rows as $row) {
            $lines[] = self::csvLine(array_map(
                fn (string $header) => $row[$header] ?? '',
                $headers
            ));
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private static function sum(array $rows, string $key): float
    {
        return array_reduce(
            $rows,
            fn (float $carry, array $row) => $carry + self::number($row[$key] ?? 0),
            0.0
        );
    }

    private static function number(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private static function money(float $value): string
    {
        return number_format(round($value, 3), 3, '.', '');
    }

    /**
     * @param array<int, mixed> $values
     */
    private static function csvLine(array $values): string
    {
        return implode(',', array_map(function (mixed $value) {
            $text = (string) $value;

            if (str_contains($text, ',') || str_contains($text, '"') || str_contains($text, "\n")) {
                return '"' . str_replace('"', '""', $text) . '"';
            }

            return $text;
        }, $values));
    }
}
