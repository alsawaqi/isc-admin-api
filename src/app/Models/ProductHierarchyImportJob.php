<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductHierarchyImportJob extends Model
{
    protected $table = 'Product_Hierarchy_Import_Jobs_T';

    protected $fillable = [
        'Token', 'User_Id', 'File_Name', 'File_Size', 'File_Sha256',
        'Payload_Digest', 'Canonical_Payload', 'Summary', 'Status',
        'Can_Commit', 'Expires_At', 'Committed_At', 'Rolled_Back_At',
        'Rolled_Back_By', 'Result',
    ];

    protected function casts(): array
    {
        return [
            'Can_Commit' => 'boolean',
            'Expires_At' => 'datetime',
            'Committed_At' => 'datetime',
            'Rolled_Back_At' => 'datetime',
        ];
    }

    public static function pruneForNewPreview(int $userId): void
    {
        // Expires_At limits preview validity, not the lifetime of rollback/audit records.
        static::query()
            ->whereIn('Status', ['pending', 'expired'])
            ->where('Expires_At', '<', now())
            ->delete();

        // A newer preview supersedes an older uncommitted preview for the same administrator.
        static::query()
            ->where('User_Id', $userId)
            ->where('Status', 'pending')
            ->delete();

        // Committed and rolled-back batches remain available regardless of age or count.
    }
}
