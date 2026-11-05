<?php

use App\Http\Controllers\CaptivePortalApiController;
use App\Http\Controllers\RouterCertController;
use App\Http\Controllers\RouterLoginPageController;
use App\Nas\ServesHotspotPages;
use Illuminate\Support\Facades\Route;

// Stateless guest-port routes: no session, cookies or CSRF (see bootstrap/app.php).

// Router downloads for the routers' scheduler / terminal; authenticated by router token + source IP.
// {nas} is resolved by the controllers (unknown id -> 403 like a wrong token, no 404 oracle).
Route::get('/router-cert/{nas}/{file}', RouterCertController::class)
    ->whereIn('file', ['fingerprint', 'router.crt', 'router.key'])->name('router-cert');
// Hotspot pages (decision 2026-11-05, v0.29.0): login, and for drivers with ServesHotspotPages status, logout, alogin.
Route::get('/router-login/{nas}/{page}.html', RouterLoginPageController::class)
    ->whereIn('page', ServesHotspotPages::PAGES)->name('router-login');

// Captive Portal API (RFC 8908) for DHCP option 114 (RFC 8910), per site.
Route::get('/captive/{slug}', CaptivePortalApiController::class)->name('captive-api');
