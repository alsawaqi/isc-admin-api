<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Schedule::call(function () {
    \App\Models\ProductHierarchyImportJob::query()
        ->where('Expires_At', '<', now())
        ->delete();
})->name('prune-product-hierarchy-import-previews')->hourly();
