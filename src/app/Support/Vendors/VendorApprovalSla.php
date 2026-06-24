<?php

namespace App\Support\Vendors;

use Carbon\CarbonInterface;
use Carbon\CarbonImmutable;

class VendorApprovalSla
{
    /**
     * @param array<string, mixed>|object $product
     * @return array<string, mixed>
     */
    public static function forProduct(array|object $product, ?CarbonInterface $now = null, int $slaHours = 48): array
    {
        $submittedRaw = self::value($product, 'Submitted_At') ?? self::value($product, 'created_at');
        $reviewedRaw = self::value($product, 'Reviewed_At');
        $status = strtolower((string) (self::value($product, 'Submission_Status') ?? 'pending'));

        if (!$submittedRaw) {
            return [
                'sla_status' => 'not_submitted',
                'sla_due_at' => null,
                'hours_remaining' => null,
                'hours_to_review' => null,
            ];
        }

        $submittedAt = CarbonImmutable::parse($submittedRaw);
        $dueAt = $submittedAt->addHours($slaHours);
        $clock = $now ? CarbonImmutable::instance($now) : CarbonImmutable::now();

        if ($reviewedRaw || in_array($status, ['approved', 'rejected', 'changes_requested', 'needs_changes'], true)) {
            $reviewedAt = $reviewedRaw ? CarbonImmutable::parse($reviewedRaw) : $clock;

            return [
                'sla_status' => 'completed',
                'sla_due_at' => $dueAt->format('Y-m-d H:i:s'),
                'hours_remaining' => self::hoursBetween($clock, $dueAt),
                'hours_to_review' => self::hoursBetween($submittedAt, $reviewedAt),
            ];
        }

        $hoursRemaining = self::hoursBetween($clock, $dueAt);

        return [
            'sla_status' => $hoursRemaining < 0 ? 'overdue' : ($hoursRemaining <= 12 ? 'due_soon' : 'on_track'),
            'sla_due_at' => $dueAt->format('Y-m-d H:i:s'),
            'hours_remaining' => $hoursRemaining,
            'hours_to_review' => null,
        ];
    }

    private static function hoursBetween(CarbonInterface $from, CarbonInterface $to): int
    {
        return (int) floor(($to->getTimestamp() - $from->getTimestamp()) / 3600);
    }

    /**
     * @param array<string, mixed>|object $row
     */
    private static function value(array|object $row, string $key): mixed
    {
        if (is_array($row)) {
            return $row[$key] ?? null;
        }

        return $row->{$key} ?? null;
    }
}
