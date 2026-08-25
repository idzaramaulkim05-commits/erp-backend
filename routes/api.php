<?php

use App\Http\Controllers\Api\AdminMappingController;
use App\Http\Controllers\Api\AdminMasterDataController;
use App\Http\Controllers\Api\AdminModuleController;
use App\Http\Controllers\Api\AdminNavigationConfigController;
use App\Http\Controllers\Api\AdminOverviewController;
use App\Http\Controllers\Api\AdminRoleMetaController;
use App\Http\Controllers\Api\AdminRoleModuleMappingController;
use App\Http\Controllers\Api\AdminSessionController;
use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AuthNavigationController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\FinanceMutationController;
use App\Http\Controllers\Api\FinancialLedgerController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\InstallationMaterialRequestController;
use App\Http\Controllers\Api\NetworkOdpController;
use App\Http\Controllers\Api\ProcurementController;
use App\Http\Controllers\Api\ReimbursementController;
use App\Http\Controllers\Api\ServiceRegistrationController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\TicketController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WarehouseReturnRequestController;
use App\Http\Controllers\Api\WorkOrderController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:login');
    Route::post('reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::get('navigation', [AuthNavigationController::class, 'show']);
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('change-password', [AuthController::class, 'changePassword']);
    });
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('customers', [CustomerController::class, 'index']);
    Route::post('customers', [CustomerController::class, 'store'])->middleware('role:superadmin,helpdesk,inventory');
    Route::patch('customers/{customer}/status', [CustomerController::class, 'updateStatus'])->middleware('role:superadmin,finance');
    Route::post('customers/{customer}/record-payment', [CustomerController::class, 'recordPayment'])->middleware('role:superadmin,finance');

    Route::get('service-registrations', [ServiceRegistrationController::class, 'index']);
    Route::post('service-registrations', [ServiceRegistrationController::class, 'store'])->middleware('role:superadmin,sales,helpdesk,finance');
    Route::get('service-registrations/{serviceRegistration}', [ServiceRegistrationController::class, 'show']);
    Route::post('service-registrations/{serviceRegistration}/submit', [ServiceRegistrationController::class, 'submit'])->middleware('role:superadmin,sales,helpdesk,finance');
    Route::post('service-registrations/{serviceRegistration}/validate', [ServiceRegistrationController::class, 'validateRegistration'])->middleware('role:superadmin,helpdesk,finance,lead_tech');
    Route::post('service-registrations/{serviceRegistration}/survey', [ServiceRegistrationController::class, 'survey'])->middleware('role:superadmin,lead_tech');
    Route::post('service-registrations/{serviceRegistration}/finance-approve', [ServiceRegistrationController::class, 'financeApprove'])->middleware('role:superadmin,finance');
    Route::post('service-registrations/{serviceRegistration}/finance-reject', [ServiceRegistrationController::class, 'financeReject'])->middleware('role:superadmin,finance');
    Route::post('service-registrations/{serviceRegistration}/generate-pppoe', [ServiceRegistrationController::class, 'generatePppoe'])->middleware('role:superadmin,noc');
    Route::post('service-registrations/{serviceRegistration}/noc-approve', [ServiceRegistrationController::class, 'nocApprove'])->middleware('role:superadmin,noc');
    Route::post('service-registrations/{serviceRegistration}/noc-reject', [ServiceRegistrationController::class, 'nocReject'])->middleware('role:superadmin,noc');
    Route::post('service-registrations/{serviceRegistration}/create-work-order', [ServiceRegistrationController::class, 'createWorkOrder'])->middleware('role:superadmin,noc');

    Route::get('tickets', [TicketController::class, 'index']);
    Route::post('tickets', [TicketController::class, 'store'])->middleware('role:superadmin,helpdesk');
    Route::post('tickets/{ticket}/remote-resolve', [TicketController::class, 'remoteResolve'])->middleware('role:superadmin,noc');
    Route::post('tickets/{ticket}/escalate', [TicketController::class, 'escalate'])->middleware('role:superadmin,noc,helpdesk');
    Route::post('tickets/{ticket}/lead-approve', [TicketController::class, 'leadApprove'])->middleware('role:superadmin,lead_tech');
    Route::post('tickets/{ticket}/noc-close', [TicketController::class, 'nocClose'])->middleware('role:superadmin,noc');
    Route::post('tickets/{ticket}/helpdesk-close', [TicketController::class, 'helpdeskClose'])->middleware('role:superadmin,helpdesk');

    Route::get('work-orders', [WorkOrderController::class, 'index']);
    Route::post('work-orders/{workOrder}/assign-tech', [WorkOrderController::class, 'assignTech'])->middleware('role:superadmin,lead_tech');
    Route::post('work-orders/{workOrder}/lead-assign', [WorkOrderController::class, 'leadAssign'])->middleware('role:superadmin,lead_tech');
    Route::post('work-orders/{workOrder}/confirm-field-assignment', [WorkOrderController::class, 'confirmFieldAssignment'])->middleware('role:superadmin,field_tech');
    Route::post('work-orders/{workOrder}/start-installation', [WorkOrderController::class, 'startInstallation'])->middleware('role:superadmin,field_tech,lead_tech');
    Route::post('work-orders/{workOrder}/submit-report', [WorkOrderController::class, 'submitReport'])->middleware('role:superadmin,field_tech,lead_tech');
    Route::post('work-orders/{workOrder}/submit-installation-report', [WorkOrderController::class, 'submitInstallationReport'])->middleware('role:superadmin,field_tech,lead_tech');
    Route::post('work-orders/{workOrder}/request-pppoe', [WorkOrderController::class, 'requestPppoe'])->middleware('role:superadmin,field_tech');
    Route::post('work-orders/{workOrder}/approve-pppoe', [WorkOrderController::class, 'approvePppoeRequest'])->middleware('role:superadmin,noc');
    Route::post('work-orders/{workOrder}/reject-pppoe', [WorkOrderController::class, 'rejectPppoeRequest'])->middleware('role:superadmin,noc');
    Route::post('work-orders/{workOrder}/confirm-installation-cash', [WorkOrderController::class, 'confirmInstallationCash'])->middleware('role:superadmin,finance');
    Route::post('work-orders/{workOrder}/confirm-installation-transfer', [WorkOrderController::class, 'confirmInstallationTransfer'])->middleware('role:superadmin,finance');
    Route::post('work-orders/{workOrder}/return-to-tech', [WorkOrderController::class, 'returnToTech'])->middleware('role:superadmin,noc');
    Route::post('work-orders/{workOrder}/noc-final-verify', [WorkOrderController::class, 'nocFinalVerify'])->middleware('role:superadmin,noc');

    Route::get('inventory', [InventoryController::class, 'index']);
    Route::get('installation-material-requests', [InstallationMaterialRequestController::class, 'index']);
    Route::patch('installation-material-requests/{installationMaterialRequest}/status', [InstallationMaterialRequestController::class, 'updateStatus'])->middleware('role:superadmin,inventory');
    Route::get('warehouse-return-requests', [WarehouseReturnRequestController::class, 'index'])->middleware('role:superadmin,inventory,field_tech,lead_tech');
    Route::patch('warehouse-return-requests/{warehouseReturnRequest}/qc', [WarehouseReturnRequestController::class, 'qc'])->middleware('role:superadmin,inventory');

    Route::get('procurements', [ProcurementController::class, 'index']);
    Route::post('procurements', [ProcurementController::class, 'store'])->middleware('role:superadmin,inventory');
    Route::put('procurements/{procurement}', [ProcurementController::class, 'update'])->middleware('role:superadmin,inventory');
    Route::post('procurements/{procurement}/finance-approve', [ProcurementController::class, 'financeApprove'])->middleware('role:superadmin,finance');
    Route::post('procurements/{procurement}/finance-reject', [ProcurementController::class, 'financeReject'])->middleware('role:superadmin,finance');
    Route::post('procurements/{procurement}/management-approve', [ProcurementController::class, 'managementApprove'])->middleware('role:superadmin,management');
    Route::post('procurements/{procurement}/management-reject', [ProcurementController::class, 'managementReject'])->middleware('role:superadmin,management');
    Route::post('procurements/{procurement}/mark-ordered', [ProcurementController::class, 'markOrdered'])->middleware('role:superadmin,inventory');
    Route::post('procurements/{procurement}/receive', [ProcurementController::class, 'receive'])->middleware('role:superadmin,inventory');

    Route::get('reimbursements', [ReimbursementController::class, 'index']);
    Route::get('reimbursements/{reimbursement}', [ReimbursementController::class, 'show']);
    Route::post('reimbursements', [ReimbursementController::class, 'store']);
    Route::post('reimbursements/{reimbursement}', [ReimbursementController::class, 'update']);
    Route::post('reimbursements/{reimbursement}/submit', [ReimbursementController::class, 'submit']);
    Route::post('reimbursements/{reimbursement}/finance-approve', [ReimbursementController::class, 'financeApprove'])->middleware('role:superadmin,finance');
    Route::post('reimbursements/{reimbursement}/finance-reject', [ReimbursementController::class, 'financeReject'])->middleware('role:superadmin,finance');
    Route::post('reimbursements/{reimbursement}/forward-to-management', [ReimbursementController::class, 'forwardToManagement'])->middleware('role:superadmin,finance');
    Route::post('reimbursements/{reimbursement}/management-approve', [ReimbursementController::class, 'managementApprove'])->middleware('role:superadmin,management');
    Route::post('reimbursements/{reimbursement}/management-reject', [ReimbursementController::class, 'managementReject'])->middleware('role:superadmin,management');
    Route::post('reimbursements/{reimbursement}/mark-paid', [ReimbursementController::class, 'markPaid'])->middleware('role:superadmin,finance');

    Route::get('finance-mutations', [FinanceMutationController::class, 'index'])->middleware('role:superadmin,finance,management');
    Route::post('finance-mutations', [FinanceMutationController::class, 'store'])->middleware('role:superadmin,finance');
    Route::put('finance-mutations/{financeMutation}', [FinanceMutationController::class, 'update'])->middleware('role:superadmin,finance');
    Route::delete('finance-mutations/{financeMutation}', [FinanceMutationController::class, 'destroy'])->middleware('role:superadmin,finance');
    Route::get('financial-ledger', [FinancialLedgerController::class, 'index'])->middleware('role:superadmin,finance,management');

    Route::get('tasks', [TaskController::class, 'index']);
    Route::post('tasks', [TaskController::class, 'store']);
    Route::patch('tasks/{task}/status', [TaskController::class, 'updateStatus']);

    Route::get('network-odps', [NetworkOdpController::class, 'index']);
    Route::get('dashboard', [DashboardController::class, 'index']);
    Route::get('users', [UserController::class, 'index']);
    Route::get('audit-logs', [AuditLogController::class, 'index']);
    Route::get('admin/master-data', [AdminMasterDataController::class, 'index']);

    Route::prefix('admin')->middleware('role:superadmin')->group(function () {
        Route::get('overview', [AdminOverviewController::class, 'index']);
        Route::get('users', [AdminUserController::class, 'index']);
        Route::post('users', [AdminUserController::class, 'store']);
        Route::put('users/{user}', [AdminUserController::class, 'update']);
        Route::post('users/{user}/reset-password', [AdminUserController::class, 'resetPassword']);
        Route::patch('users/{user}/status', [AdminUserController::class, 'updateStatus']);
        Route::get('roles', [AdminRoleMetaController::class, 'index']);
        Route::post('roles', [AdminRoleMetaController::class, 'store']);
        Route::put('roles/{role}', [AdminRoleMetaController::class, 'update']);
        Route::patch('roles/{role}/status', [AdminRoleMetaController::class, 'updateStatus']);
        Route::put('master-data/{group}', [AdminMasterDataController::class, 'update']);
        Route::get('modules', [AdminModuleController::class, 'index']);
        Route::post('modules', [AdminModuleController::class, 'store']);
        Route::put('modules/{moduleKey}', [AdminModuleController::class, 'update']);
        Route::delete('modules/{moduleKey}', [AdminModuleController::class, 'destroy']);
        Route::get('module-role-mappings', [AdminRoleModuleMappingController::class, 'index']);
        Route::put('module-role-mappings/{role}', [AdminRoleModuleMappingController::class, 'update']);
        Route::get('navigation-config', [AdminNavigationConfigController::class, 'index']);
        Route::put('navigation-config', [AdminNavigationConfigController::class, 'update']);
        Route::get('mappings', [AdminMappingController::class, 'index']);
        Route::get('sessions', [AdminSessionController::class, 'index']);
    });
});
