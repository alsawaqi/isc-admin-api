<?php

namespace App\Support\Reviews;

use InvalidArgumentException;

final class ProductReviewModeration
{
    public const STATUSES = ['pending', 'approved', 'rejected', 'reported'];
    public const REPLY_TYPES = ['admin', 'vendor'];

    /**
     * @return array{Status: string, Moderator_Note: string|null, Moderated_By: int|null, Moderated_At: \Illuminate\Support\Carbon|null}
     */
    public static function snapshot(string $status, ?string $note, ?int $actorId): array
    {
        $status = strtolower(trim($status));

        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('Unsupported moderation status.');
        }

        $isPending = $status === 'pending';

        return [
            'Status' => $status,
            'Moderator_Note' => $note !== null ? trim($note) : null,
            'Moderated_By' => $isPending ? null : $actorId,
            'Moderated_At' => $isPending ? null : now(),
        ];
    }

    /**
     * @return array{Reply_Type: string, User_Id: int|null, Body: string, Status: string}
     */
    public static function replySnapshot(string $type, ?int $actorId, string $body): array
    {
        $type = strtolower(trim($type));

        if (!in_array($type, self::REPLY_TYPES, true)) {
            throw new InvalidArgumentException('Unsupported reply type.');
        }

        return [
            'Reply_Type' => $type,
            'User_Id' => $type === 'admin' ? $actorId : null,
            'Body' => trim($body),
            'Status' => 'approved',
        ];
    }
}
