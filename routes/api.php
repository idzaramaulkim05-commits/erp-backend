<?php

use App\Http\Controllers\Api\ActivityLogController;
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
use App\Http\Controllers\Api\ComprehensiveTicketController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\CustomerPackageRequestController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DataSheetController;
use App\Http\Controllers\Api\EmployeePerformanceController;
use App\Http\Controllers\Api\FinanceMutationController;
use App\Http\Controllers\Api\FinancialLedgerController;
use App\Http\Controllers\Api\InstallationMaterialRequestController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\MasterWilayahController;
use App\Http\Controllers\Api\NetworkOdpController;
use App\Http\Controllers\Api\NetworkTelemetryController;
use App\Http\Controllers\Api\OdpController;
use App\Http\Controllers\Api\OltController;
use App\Http\Controllers\Api\PaketController;
use App\Http\Controllers\Api\PopController;
use App\Http\Controllers\Api\PopWorkOrderController;
use App\Http\Controllers\Api\ProcurementController;
use App\Http\Controllers\Api\ReimbursementController;
use App\Http\Controllers\Api\RouterController;
use App\Http\Controllers\Api\ServiceRegistrationController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\SyncCheckController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\TicketController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WarehouseController;
use App\Http\Controllers\Api\WarehouseReturnRequestController;
use App\Http\Controllers\Api\WebhookController;
use App\Http\Controllers\Api\WorkOrderController;
use Illuminate\Support\Facades\Route;

// Public Webhooks
Route::match(['get', 'post'], 'webhook/whatsapp', [WebhookController::class, 'handleWhatsApp']);

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:login');
    Route::post('reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('profile', [AuthController::class, 'updateProfile']);
        Route::get('navigation', [AuthNavigationController::class, 'show']);
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('change-password', [AuthController::class, 'changePassword']);
    });
});

Route::middleware('auth:sanctum')->group(function () {
    // ==========================================
    // MODULE 1: MIKROTIK & BACKBONE TELEMETRY
    // ==========================================
    Route::apiResource('routers', RouterController::class);
    Route::post('routers/{id}/set-default', [RouterController::class, 'setDefault']);
    Route::get('network/telemetry', [NetworkTelemetryController::class, 'telemetry']);
    Route::get('network/traffic', [NetworkTelemetryController::class, 'traffic']);
    Route::get('network/interfaces', [NetworkTelemetryController::class, 'routerInterfaces']);
    Route::post('network/ping', [NetworkTelemetryController::class, 'ping']);
    Route::post('network/ping-terminal', [NetworkTelemetryController::class, 'pingTerminal']);
    Route::get('network/backbone', [NetworkTelemetryController::class, 'backbone']);
    Route::get('network/pppoe/secrets', [NetworkTelemetryController::class, 'pelangganList']);
    Route::post('network/pppoe/toggle-status', [NetworkTelemetryController::class, 'togglePelangganStatus']);
    Route::post('network/pppoe/delete', [NetworkTelemetryController::class, 'deletePelanggan']);

    // ==========================================
    // MODULE 2: OLT GPON & EPON MONITORING
    // ==========================================
    Route::apiResource('olts', OltController::class);
    Route::get('olts-telemetry', [OltController::class, 'telemetry']);
    Route::post('olts/{id}/ping', [OltController::class, 'ping']);
    Route::post('olts/{id}/sync', [OltController::class, 'sync']);
    Route::post('olts/sync-all', [OltController::class, 'syncAll']);
    Route::get('olts/{id}/onus', [OltController::class, 'onus']);
    Route::post('olts/{id}/restart-onu', [OltController::class, 'restartOnu']);
    Route::post('olts/{id}/delete-onu', [OltController::class, 'deleteOnu']);

    // ==========================================
    // MODULE 3: ODP DISTRIBUTION & GIS MAPPING
    // ==========================================
    Route::apiResource('odps', OdpController::class);
    Route::post('odps/{id}/toggle-status', [OdpController::class, 'toggleStatus']);
    Route::post('odps/import-kmz', [OdpController::class, 'importKmz']);

    // ==========================================
    // MODULE 4: PAKET LAYANAN INTERNET
    // ==========================================
    Route::apiResource('pakets', PaketController::class);
    Route::post('pakets/{id}/toggle-status', [PaketController::class, 'toggleStatus']);
    Route::get('pakets-mikrotik-profiles', [PaketController::class, 'getProfilesApi']);

    // ==========================================
    // MODULE 5: MASTER WILAYAH & GENERATOR ID
    // ==========================================
    Route::get('wilayah/all', [MasterWilayahController::class, 'all']);
    Route::post('wilayah/generate-id', [MasterWilayahController::class, 'generateId']);

    // ==========================================
    // MODULE 6: DATASHEET 360 & GOOGLE SHEETS
    // ==========================================
    Route::get('datasheet/search', [DataSheetController::class, 'search']);
    Route::get('datasheet/detail', [DataSheetController::class, 'detail']);
    Route::get('datasheet/invoices', [DataSheetController::class, 'customerInvoices']);
    Route::get('datasheet/lookup', [DataSheetController::class, 'lookup']);
    Route::get('datasheet/suggestions', [DataSheetController::class, 'suggestions']);
    Route::post('datasheet/sync', [DataSheetController::class, 'sync']);
    Route::post('datasheet/upload-csv', [DataSheetController::class, 'uploadCsv']);
    Route::post('datasheet/save', [DataSheetController::class, 'storeOrUpdate']);
    Route::delete('datasheet/{id}', [DataSheetController::class, 'destroy']);
    Route::get('sync-check/data', [SyncCheckController::class, 'index']);
    Route::get('sync-check/export', [SyncCheckController::class, 'export']);

    // ==========================================
    // MODULE 7: BILLING & INVOICING BULANAN
    // ==========================================
    Route::get('invoices', [InvoiceController::class, 'invoiceIndex']);
    Route::post('invoices', [InvoiceController::class, 'invoiceStore']);
    Route::post('invoices/generate-monthly', [InvoiceController::class, 'invoiceGenerateMonthly']);
    Route::post('invoices/{id}/pay', [InvoiceController::class, 'invoicePay']);
    Route::post('invoices/bulk-pay', [InvoiceController::class, 'invoiceBulkPay']);
    Route::post('invoices/{id}/toggle-isolir', [InvoiceController::class, 'invoiceToggleIsolir']);
    Route::post('invoices/bulk-toggle-isolir', [InvoiceController::class, 'invoiceBulkToggleIsolir']);
    Route::post('invoices/{id}/notes', [InvoiceController::class, 'invoiceUpdateNote']);
    Route::delete('invoices/{id}', [InvoiceController::class, 'invoiceDestroy']);
    Route::get('invoices/{id}/wa-template', [InvoiceController::class, 'invoiceWaTemplate']);
    Route::post('invoices/{id}/send-wa', [InvoiceController::class, 'invoiceSendWa']);
    Route::post('invoices/bulk-send-wa', [InvoiceController::class, 'invoiceBulkSendWa']);
    Route::get('invoices/{id}/print', [InvoiceController::class, 'invoicePrint']);
    Route::get('invoices-export-csv', [InvoiceController::class, 'invoiceExportCsv']);

    // Customer Package Requests
    Route::get('package-requests', [CustomerPackageRequestController::class, 'index']);
    Route::post('package-requests', [CustomerPackageRequestController::class, 'store']);
    Route::post('package-requests/{id}/approve', [CustomerPackageRequestController::class, 'approve']);
    Route::post('package-requests/{id}/reject', [CustomerPackageRequestController::class, 'reject']);

    // ==========================================
    // MODULE 8: WAREHOUSE & INVENTARIS
    // ==========================================
    Route::get('warehouse/items', [WarehouseController::class, 'index']);
    Route::post('warehouse/items', [WarehouseController::class, 'storeItem']);
    Route::put('warehouse/items/{id}', [WarehouseController::class, 'updateItem']);
    Route::delete('warehouse/items/{id}', [WarehouseController::class, 'deleteItem']);
    Route::post('warehouse/items/{id}/adjust', [WarehouseController::class, 'adjustStock']);
    Route::post('warehouse/requests', [WarehouseController::class, 'storeRequest']);
    Route::post('warehouse/requests/{id}/approve-finance', [WarehouseController::class, 'approveFinance']);
    Route::post('warehouse/requests/{id}/confirm-restock', [WarehouseController::class, 'confirmRestock']);
    Route::post('warehouse/requests/{id}/approve-divisi', [WarehouseController::class, 'approveDivisiRequest']);
    Route::post('warehouse/requests/{id}/reject-divisi', [WarehouseController::class, 'rejectDivisiRequest']);
    Route::post('warehouse/requests/{id}/action-noc', [WarehouseController::class, 'actionNocFollowup']);
    Route::delete('warehouse/requests/{id}', [WarehouseController::class, 'deleteRequest']);
    Route::post('warehouse/returns/{id}/receive', [WarehouseController::class, 'receiveReturn']);
    Route::post('warehouse/returns/{id}/reject', [WarehouseController::class, 'rejectReturn']);
    Route::delete('warehouse/returns/{id}', [WarehouseController::class, 'deleteReturn']);
    Route::delete('warehouse/mutations/{id}', [WarehouseController::class, 'deleteMutation']);
    Route::get('warehouse/export', [WarehouseController::class, 'export']);

    // ==========================================
    // MODULE 9: TIKET GANGGUAN & PSB TERPADU
    // ==========================================
    Route::get('tickets/live-check', [ComprehensiveTicketController::class, 'liveTicketCheck']);
    Route::post('tickets/scan-onu-ocr', [ComprehensiveTicketController::class, 'scanOnuPhoto']);
    Route::get('comprehensive-tickets', [ComprehensiveTicketController::class, 'index']);
    Route::post('comprehensive-tickets', [ComprehensiveTicketController::class, 'store']);
    Route::get('comprehensive-tickets/{id}', [ComprehensiveTicketController::class, 'show']);
    Route::post('comprehensive-tickets/{id}/validate-noc', [ComprehensiveTicketController::class, 'validateNoc']);
    Route::post('comprehensive-tickets/{id}/dispatch-tl', [ComprehensiveTicketController::class, 'dispatchTl']);
    Route::post('comprehensive-tickets/{id}/progress', [ComprehensiveTicketController::class, 'updateProgress']);
    Route::post('comprehensive-tickets/{id}/resolve', [ComprehensiveTicketController::class, 'resolve']);
    Route::post('comprehensive-tickets/{id}/assign-vlan', [ComprehensiveTicketController::class, 'assignVlanNoc']);
    Route::post('comprehensive-tickets/{id}/psb-action', [ComprehensiveTicketController::class, 'psbAction']);
    Route::post('comprehensive-tickets/{id}/close', [ComprehensiveTicketController::class, 'close']);
    Route::delete('comprehensive-tickets/{id}', [ComprehensiveTicketController::class, 'destroy']);
    Route::get('comprehensive-tickets-export', [ComprehensiveTicketController::class, 'export']);

    // ==========================================
    // MODULE 10: SETTINGS ISP & NOTIFIKASI
    // ==========================================
    Route::get('settings/isp', [SettingController::class, 'index']);
    Route::post('settings/isp', [SettingController::class, 'updateIsp']);
    Route::post('settings/test-router', [SettingController::class, 'testRouter']);
    Route::post('settings/test-wa', [SettingController::class, 'testWa']);
    Route::post('settings/test-telegram', [SettingController::class, 'testTelegram']);
    Route::get('settings/wa-groups', [SettingController::class, 'getWaGroups']);

    // ==========================================
    // MODULE 11: ACTIVITY & SYSTEM LOGS
    // ==========================================
    Route::get('activity-logs/list', [ActivityLogController::class, 'index']);
    Route::get('activity-logs/pppoe-stream', [ActivityLogController::class, 'pppoeLogs']);
    Route::get('activity-logs/system-stream', [ActivityLogController::class, 'systemLogs']);

    // ==========================================
    // CORE SYSTEM & MANAGEMENT ROUTES
    // ==========================================
    Route::get('customers', [CustomerController::class, 'index']);
    Route::post('customers/import/preview', [CustomerController::class, 'previewImport'])->middleware('role:superadmin');
    Route::post('customers/import/confirm', [CustomerController::class, 'confirmImport'])->middleware('role:superadmin');
    Route::get('customers/import/template', [CustomerController::class, 'downloadTemplate'])->middleware('role:superadmin');
    Route::post('customers', [CustomerController::class, 'store'])->middleware('role:superadmin,helpdesk,inventory');
    Route::patch('customers/{customer}/status', [CustomerController::class, 'updateStatus'])->middleware('role:superadmin,finance');
    Route::post('customers/{customer}/record-payment', [CustomerController::class, 'recordPayment'])->middleware('role:superadmin,finance');

    Route::get('service-registrations', [ServiceRegistrationController::class, 'index']);
    Route::post('service-registrations', [ServiceRegistrationController::class, 'store'])->middleware('role:superadmin,sales,helpdesk,finance,lead_tech');
    Route::get('service-registrations/{serviceRegistration}', [ServiceRegistrationController::class, 'show']);
    Route::match(['put', 'patch', 'post'], 'service-registrations/{serviceRegistration}/update', [ServiceRegistrationController::class, 'update'])->middleware('role:superadmin,sales,helpdesk,finance,lead_tech');
    Route::post('service-registrations/{serviceRegistration}/submit', [ServiceRegistrationController::class, 'submit'])->middleware('role:superadmin,sales,helpdesk,finance,lead_tech');
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
    Route::post('procurements', [ProcurementController::class, 'store'])->middleware('role:superadmin,inventory,lead_tech,management,finance');
    Route::put('procurements/{procurement}', [ProcurementController::class, 'update'])->middleware('role:superadmin,inventory,lead_tech,management,finance');
    Route::post('procurements/{procurement}/finance-approve', [ProcurementController::class, 'financeApprove'])->middleware('role:superadmin,finance');
    Route::post('procurements/{procurement}/finance-reject', [ProcurementController::class, 'financeReject'])->middleware('role:superadmin,finance');
    Route::post('procurements/{procurement}/management-approve', [ProcurementController::class, 'managementApprove'])->middleware('role:superadmin,management');
    Route::post('procurements/{procurement}/management-reject', [ProcurementController::class, 'managementReject'])->middleware('role:superadmin,management');
    Route::post('procurements/{procurement}/confirm-payment', [ProcurementController::class, 'confirmPayment'])->middleware('role:superadmin,finance');
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

    Route::get('pops', [PopController::class, 'index']);
    Route::post('pops', [PopController::class, 'store'])->middleware('role:superadmin');
    Route::get('pops/{pop}', [PopController::class, 'show']);
    Route::put('pops/{pop}', [PopController::class, 'update'])->middleware('role:superadmin');
    Route::delete('pops/{pop}', [PopController::class, 'destroy'])->middleware('role:superadmin');
    Route::post('pops/{pop}/devices', [PopController::class, 'storeDevice'])->middleware('role:superadmin,noc,lead_tech,inventory');
    Route::put('pops/{pop}/devices/{device}', [PopController::class, 'updateDevice'])->middleware('role:superadmin,noc,lead_tech,inventory');
    Route::delete('pops/{pop}/devices/{device}', [PopController::class, 'destroyDevice'])->middleware('role:superadmin,noc');

    Route::get('pop-work-orders', [PopWorkOrderController::class, 'index']);
    Route::post('pop-work-orders', [PopWorkOrderController::class, 'store'])->middleware('role:superadmin,noc');
    Route::get('pop-work-orders/{popWorkOrder}', [PopWorkOrderController::class, 'show']);
    Route::post('pop-work-orders/{popWorkOrder}/assign-tech', [PopWorkOrderController::class, 'assignTech'])->middleware('role:superadmin,lead_tech');
    Route::post('pop-work-orders/{popWorkOrder}/start', [PopWorkOrderController::class, 'start'])->middleware('role:superadmin,field_tech,lead_tech');
    Route::post('pop-work-orders/{popWorkOrder}/submit-field-report', [PopWorkOrderController::class, 'submitFieldReport'])->middleware('role:superadmin,field_tech,lead_tech');
    Route::post('pop-work-orders/{popWorkOrder}/noc-qc-approve', [PopWorkOrderController::class, 'nocQcApprove'])->middleware('role:superadmin,noc');
    Route::post('pop-work-orders/{popWorkOrder}/noc-qc-reject', [PopWorkOrderController::class, 'nocQcReject'])->middleware('role:superadmin,noc');

    Route::get('network-odps', [NetworkOdpController::class, 'index']);
    Route::get('dashboard', [DashboardController::class, 'index']);
    Route::get('employee-performance', [EmployeePerformanceController::class, 'index'])->middleware('role:superadmin,management');
    Route::get('employee-performance/{user}', [EmployeePerformanceController::class, 'show'])->middleware('role:superadmin,management');
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
