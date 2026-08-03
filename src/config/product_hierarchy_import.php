<?php

return [
    'permission' => env('PRODUCT_HIERARCHY_IMPORT_PERMISSION', 'import product categories'),
    'cache_store' => env('PRODUCT_HIERARCHY_IMPORT_CACHE_STORE', 'file'),
    'preview_ttl_minutes' => (int) env('PRODUCT_HIERARCHY_IMPORT_PREVIEW_TTL', 15),
    'max_upload_kilobytes' => (int) env('PRODUCT_HIERARCHY_IMPORT_MAX_UPLOAD_KB', 5120),
    'retained_jobs_per_user' => (int) env('PRODUCT_HIERARCHY_IMPORT_RETAINED_JOBS', 5),
];
