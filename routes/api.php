<?php

declare(strict_types=1);

use App\Http\Controllers\RuntimeHealthController;
use Illuminate\Support\Facades\Route;

Route::get('/status', fn (): array => [
    'name' => config('app.name'),
    'status' => 'ok',
]);
Route::get('/health/live', [RuntimeHealthController::class, 'live']);
Route::get('/health/ready', [RuntimeHealthController::class, 'ready']);
