<?php

use App\Http\Controllers\Api\AdminMappingController;
use App\Http\Controllers\Api\AdminMasterDataController;
use App\Http\Controllers\Api\AdminOverviewController;
use App\Http\Controllers\Api\AdminSessionController;
use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\NetworkOdpController;
use App\Http\Controllers\Api\ProcurementController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\TicketController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WorkOrderController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:login');
    Route::post('reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('change-password', [AuthController::class, 'changePassword']);
    });
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('customers', [CustomerController::class, 'index']);
    Route::post('customers', [CustomerController::class, 'store'])->middleware('role:superadmin,helpdesk,inventory');
    Route::patch('customers/{customer}/status', [CustomerController::class, 'updateStatus'])->middleware('role:superadmin,finance');

    Route::get('tickets', [TicketController::class, 'index']);
    Route::post('tickets', [TicketController::class, 'store'])->middleware('role:superadmin,helpdesk');
    Route::post('tickets/{ticket}/remote-resolve', [TicketController::class, 'remoteResolve'])->middleware('role:superadmin,noc');
    Route::post('tickets/{ticket}/escalate', [TicketController::class, 'escalate'])->middleware('role:superadmin,noc,helpdesk');
    Route::post('tickets/{ticket}/lead-approve', [TicketController::class, 'leadApprove'])->middleware('role:superadmin,lead_tech');
    Route::post('tickets/{ticket}/noc-close', [TicketController::class, 'nocClose'])->middleware('role:superadmin,noc');

    Route::get('work-orders', [WorkOrderController::class, 'index']);
    Route::post('work-orders/{workOrder}/assign-tech', [WorkOrderController::class, 'assignTech'])->middleware('role:superadmin,lead_tech');
    Route::post('work-orders/{workOrder}/submit-report', [WorkOrderController::class, 'submitReport'])->middleware('role:superadmin,field_tech,lead_tech');

    Route::get('inventory', [InventoryController::class, 'index']);

    Route::get('procurements', [ProcurementController::class, 'index']);
    Route::post('procurements', [ProcurementController::class, 'store'])->middleware('role:superadmin,inventory');
    Route::post('procurements/{procurement}/finance-approve', [ProcurementController::class, 'financeApprove'])->middleware('role:superadmin,finance');
    Route::post('procurements/{procurement}/management-approve', [ProcurementController::class, 'managementApprove'])->middleware('role:superadmin,management');
    Route::post('procurements/{procurement}/receive', [ProcurementController::class, 'receive'])->middleware('role:superadmin,inventory');

    Route::get('tasks', [TaskController::class, 'index']);
    Route::post('tasks', [TaskController::class, 'store']);
    Route::patch('tasks/{task}/status', [TaskController::class, 'updateStatus']);

    Route::get('network-odps', [NetworkOdpController::class, 'index']);
    Route::get('dashboard', [DashboardController::class, 'index']);
    Route::get('users', [UserController::class, 'index']);
    Route::get('audit-logs', [AuditLogController::class, 'index']);

    Route::prefix('admin')->middleware('role:superadmin')->group(function () {
        Route::get('overview', [AdminOverviewController::class, 'index']);
        Route::get('users', [AdminUserController::class, 'index']);
        Route::post('users', [AdminUserController::class, 'store']);
        Route::put('users/{user}', [AdminUserController::class, 'update']);
        Route::post('users/{user}/reset-password', [AdminUserController::class, 'resetPassword']);
        Route::patch('users/{user}/status', [AdminUserController::class, 'updateStatus']);
        Route::get('master-data', [AdminMasterDataController::class, 'index']);
        Route::put('master-data/{group}', [AdminMasterDataController::class, 'update']);
        Route::get('mappings', [AdminMappingController::class, 'index']);
        Route::get('sessions', [AdminSessionController::class, 'index']);
    });
});
