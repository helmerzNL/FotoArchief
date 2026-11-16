<?php

declare(strict_types=1);

use App\Http\Controllers\RuntimeHealthController;
use Illuminate\Support\Facades\Route;

Route::get('/status', fn (): array => [
    'name' => config('app.name'),
    'status' => 'ok',
    'version' => trim((string) @file_get_contents(base_path('VERSION'))),
    'channel' => config('app.channel'),
]);
Route::get('/health/live', [RuntimeHealthController::class, 'live']);
Route::get('/health/ready', [RuntimeHealthController::class, 'ready']);
