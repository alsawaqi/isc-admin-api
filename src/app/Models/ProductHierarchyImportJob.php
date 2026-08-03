<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductHierarchyImportJob extends Model
{
    protected $table = 'Product_Hierarchy_Import_Jobs_T';

    protected $fillable = [
        'Token', 'User_Id', 'File_Name', 'File_Size', 'File_Sha256',
        'Payload_Digest', 'Canonical_Payload', 'Summary', 'Status',
        'Can_Commit', 'Expires_At', 'Committed_At', 'Result',
    ];

    protected function casts(): array
    {
        return [
            'Can_Commit' => 'boolean',
            'Expires_At' => 'datetime',
            'Committed_At' => 'datetime',
        ];
    }

    public static function pruneForNewPreview(int $userId): void
    {
        static::query()->where('Expires_At', '<', now())->delete();

        // A newer preview supersedes an older uncommitted preview for the same administrator.
        static::query()
            ->where('User_Id', $userId)
            ->where('Status', 'pending')
            ->delete();

        $maximumJobs = max(1, (int) config('product_hierarchy_import.retained_jobs_per_user', 5));
        $committedToKeep = max(0, $maximumJobs - 1);
        $recentCommittedIds = $committedToKeep === 0
            ? collect()
            : static::query()
                ->where('User_Id', $userId)
                ->where('Status', 'committed')
                ->latest('id')
                ->limit($committedToKeep)
                ->pluck('id');

        $olderCommitted = static::query()
            ->where('User_Id', $userId)
            ->where('Status', 'committed');

        if ($recentCommittedIds->isNotEmpty()) {
            $olderCommitted->whereNotIn('id', $recentCommittedIds);
        }

        $olderCommitted->delete();
    }
}
