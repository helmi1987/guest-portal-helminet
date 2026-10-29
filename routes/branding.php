<?php

use App\Http\Controllers\BrandingController;
use Illuminate\Support\Facades\Route;

// Branding files and theme stylesheets on the guest and admin port: no session, cookies or CSRF
// (see bootstrap/app.php). Files are addressed by content hash (docs/branding.md).
Route::get('/branding/files/{file}', [BrandingController::class, 'file'])->name('branding.file')
    ->where('file', '[a-f0-9]{64}\.(png|jpg|webp|svg|ico)');
Route::get('/branding/theme/{scope}.css', [BrandingController::class, 'theme'])->name('branding.theme')
    ->where('scope', 'global|[0-9A-Za-z]{26}');
