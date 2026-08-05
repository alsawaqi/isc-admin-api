<?php

namespace App\Support;

final class CommerceTestDataBackupPlan
{
    public static function isAllowedPath(string $path): bool
    {
        return preg_match(
            '~\A/var/opt/mssql/backup/isc-pre-commerce-reset-[0-9]{8}T[0-9]{6}Z\.bak\z~',
            $path,
        ) === 1;
    }
}
