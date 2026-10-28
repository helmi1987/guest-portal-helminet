<?php

use App\Http\Controllers\RouterCertController;
use Illuminate\Support\Facades\Route;

// Router certificate download for the routers' scheduler (guest port). Stateless: no session,
// cookies or CSRF (see bootstrap/app.php); authenticated by router token + source IP.
Route::get('/router-cert/{nas}/{file}', RouterCertController::class)
    ->whereIn('file', ['fingerprint', 'router.crt', 'router.key'])->name('router-cert');
