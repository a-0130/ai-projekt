<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\AchievementController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RideHistoryController;
use App\Http\Controllers\RoutePlannerController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/nearest-stop', [RoutePlannerController::class, 'nearestStop']);
Route::get('/plan-route', [RoutePlannerController::class, 'planRoute']);
Route::get('/routes/list', [RoutePlannerController::class, 'listRoutes']);
Route::get('/trip-details/{trip_id}', [RoutePlannerController::class, 'tripDetails'])->whereNumber('trip_id');

Route::get('/stops', [ScheduleController::class, 'stops']);
Route::get('/schedules/routes/pdf', [ScheduleController::class, 'routesPdf']);
Route::get('/schedules/routes/{route_id}/stops/{stop_id}/departures', [ScheduleController::class, 'routeStopDepartures'])
    ->whereNumber('route_id')
    ->whereNumber('stop_id');
Route::get('/schedules/routes/{route_id}/pattern', [ScheduleController::class, 'routePattern'])->whereNumber('route_id');
Route::get('/schedules/stops/{stop_id}/departures', [ScheduleController::class, 'stopDepartures'])->whereNumber('stop_id');

Route::middleware('throttle:auth')->group(function (): void {
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);
});

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::get('/auth/me', [ProfileController::class, 'me']);
    Route::get('/auth/export-data', [ProfileController::class, 'exportData']);
    Route::patch('/auth/profile', [ProfileController::class, 'update']);
    Route::patch('/auth/password', [ProfileController::class, 'updatePassword']);

    Route::middleware('admin.api')->group(function (): void {
        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
    });

    Route::get('/ticket-types', [TicketController::class, 'types']);
    Route::get('/tickets', [TicketController::class, 'index']);
    Route::post('/tickets/purchase', [TicketController::class, 'purchase']);
    Route::post('/tickets/{ticket}/activate', [TicketController::class, 'activate'])
        ->whereNumber('ticket');

    Route::get('/ride-history', [RideHistoryController::class, 'index']);
    Route::post('/ride-history/add', [RideHistoryController::class, 'store']);

    Route::get('/reports/user', [ReportController::class, 'userIndex']);
    Route::post('/reports', [ReportController::class, 'store']);

    Route::get('/achievements', [AchievementController::class, 'index']);
    Route::post('/discount-codes/validate', [AchievementController::class, 'validateDiscountCode']);
});
