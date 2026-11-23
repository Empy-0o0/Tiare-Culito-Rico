<?php

use App\Http\Controllers\Api\V1\AdminAccountController;
use App\Http\Controllers\Api\V1\AdminTenantController;
use App\Http\Controllers\Api\V1\AgentController;
use App\Http\Controllers\Api\V1\ApiTokenController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\CompanyController;
use App\Http\Controllers\Api\V1\CompraAgilController;
use App\Http\Controllers\Api\V1\ContactController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\InternalPurchaseOrderController;
use App\Http\Controllers\Api\V1\InventoryController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\LogisticsController;
use App\Http\Controllers\Api\V1\MercadoPublicoSyncController;
use App\Http\Controllers\Api\V1\MyAccountController;
use App\Http\Controllers\Api\V1\PartyPortalAccessController;
use App\Http\Controllers\Api\V1\PasswordResetController;
use App\Http\Controllers\Api\V1\PortalController;
use App\Http\Controllers\Api\V1\PublicDirectoryController;
use App\Http\Controllers\Api\V1\PublicOpportunityFileController;
use App\Http\Controllers\Api\V1\PublicProcurementCatalogController;
use App\Http\Controllers\Api\V1\PublicPurchaseOrderController;
use App\Http\Controllers\Api\V1\PurchaseRequestController;
use App\Http\Controllers\Api\V1\SavedSearchController;
use App\Http\Controllers\Api\V1\SupplierController;
use App\Http\Controllers\Api\V1\SupplierDocumentController;
use App\Http\Controllers\Api\V1\SupplierEvaluationController;
use App\Http\Controllers\Api\V1\SupplierOfferingController;
use App\Http\Controllers\Api\V1\TenderController;
use Illuminate\Support\Facades\Route;

/*
| API interna TIARE /api/v1. React nunca llama a dominios de Mercado Público.
*/
Route::prefix('v1')->group(function (): void {
    Route::get('health', HealthController::class);

    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('auth/forgot-password', [PasswordResetController::class, 'forgot'])->middleware('throttle:login');
    Route::post('auth/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:login');

    Route::middleware(['auth:sanctum', 'tenant', 'password.fresh', 'throttle:api'])->group(function (): void {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        // Mi cuenta: cambio de contraseña propia (TD-026). Límite estricto contra adivinación de la actual.
        Route::put('auth/password', [MyAccountController::class, 'changePassword'])->middleware('throttle:password-change');
        Route::post('auth/tenant', [AuthController::class, 'switchTenant']);

        // --- Tokens API de la empresa (solo desde la sesión web; TD-024) ---
        Route::middleware('permission:api_tokens.manage')->group(function (): void {
            Route::get('auth/tokens', [ApiTokenController::class, 'index']);
            Route::post('auth/tokens', [ApiTokenController::class, 'store'])->middleware('throttle:login');
            Route::delete('auth/tokens/{id}', [ApiTokenController::class, 'destroy'])->where('id', '[0-9]{1,18}');
        });

        // --- Administración TIARE: empresas clientes y planes (solo rol de plataforma) ---
        Route::prefix('admin')->middleware('permission:platform.tenants.manage')->group(function (): void {
            Route::get('plans', [AdminTenantController::class, 'plans']);
            Route::get('tenants', [AdminTenantController::class, 'index']);
            Route::post('tenants', [AdminTenantController::class, 'store']);
            Route::get('tenants/{publicId}', [AdminTenantController::class, 'show'])->where('publicId', '[0-9A-Za-z]{26}');
            Route::patch('tenants/{publicId}', [AdminTenantController::class, 'update'])->where('publicId', '[0-9A-Za-z]{26}');
            Route::post('tenants/{publicId}/subscription', [AdminTenantController::class, 'subscribe'])->where('publicId', '[0-9A-Za-z]{26}');
        });

        // --- Administración TIARE: mantenedor de cuentas y roles (TD-025) ---
        Route::prefix('admin')->middleware('permission:platform.users.manage')->group(function (): void {
            Route::get('account-references', [AdminAccountController::class, 'references']);
            Route::get('users', [AdminAccountController::class, 'users']);
            Route::post('users', [AdminAccountController::class, 'storeUser']);
            Route::patch('users/{publicId}', [AdminAccountController::class, 'updateUser'])->where('publicId', '[0-9A-Za-z]{26}');
            Route::delete('users/{publicId}', [AdminAccountController::class, 'destroyUser'])->where('publicId', '[0-9A-Za-z]{26}');
            Route::put('users/{publicId}/memberships/{tenantPublicId}', [AdminAccountController::class, 'saveMembership'])->where(['publicId' => '[0-9A-Za-z]{26}', 'tenantPublicId' => '[0-9A-Za-z]{26}']);
            Route::delete('users/{publicId}/memberships/{tenantPublicId}', [AdminAccountController::class, 'removeMembership'])->where(['publicId' => '[0-9A-Za-z]{26}', 'tenantPublicId' => '[0-9A-Za-z]{26}']);
            Route::post('users/{publicId}/password-link', [AdminAccountController::class, 'passwordLink'])->where('publicId', '[0-9A-Za-z]{26}')->middleware('throttle:login');
            Route::post('users/{publicId}/initial-password', [AdminAccountController::class, 'initialPassword'])->where('publicId', '[0-9A-Za-z]{26}')->middleware('throttle:initial-password');
        });
        Route::prefix('admin')->middleware('permission:platform.roles.manage')->group(function (): void {
            Route::get('roles', [AdminAccountController::class, 'roles']);
            Route::post('roles', [AdminAccountController::class, 'storeRole']);
            Route::patch('roles/{key}', [AdminAccountController::class, 'updateRole'])->where('key', '[a-z0-9_]{3,64}');
            Route::post('roles/{key}/reset', [AdminAccountController::class, 'resetRole'])->where('key', '[a-z0-9_]{3,64}');
            Route::delete('roles/{key}', [AdminAccountController::class, 'destroyRole'])->where('key', '[a-z0-9_]{3,64}');
        });

        // Datos privados de la empresa activa: exigen una empresa (TD-032).
        Route::middleware('tenant.required')->group(function (): void {
            // --- Fase 2a: CRM, proveedores y catálogo (privado por tenant; autorización por Policies) ---
            Route::get('references', [CatalogController::class, 'references']);

            Route::get('customers', [CustomerController::class, 'index']);
            Route::post('customers', [CustomerController::class, 'store']);
            Route::get('customers/{customer}', [CustomerController::class, 'show']);
            Route::patch('customers/{customer}', [CustomerController::class, 'update']);
            Route::delete('customers/{customer}', [CustomerController::class, 'destroy']);

            Route::get('suppliers', [SupplierController::class, 'index']);
            Route::post('suppliers', [SupplierController::class, 'store']);
            Route::get('suppliers/{supplier}', [SupplierController::class, 'show']);
            Route::patch('suppliers/{supplier}', [SupplierController::class, 'update']);
            Route::delete('suppliers/{supplier}', [SupplierController::class, 'destroy']);
            Route::get('suppliers/{supplier}/evaluations', [SupplierEvaluationController::class, 'index']);
            Route::post('suppliers/{supplier}/evaluations', [SupplierEvaluationController::class, 'store']);
            // --- Portal de clientes y proveedores (TD-028) ---
            Route::get('portal/profile', [PortalController::class, 'profile']);
            foreach (['customers/{customer}', 'suppliers/{supplier}'] as $party) {
                Route::get("{$party}/portal-access", [PartyPortalAccessController::class, 'show']);
                Route::post("{$party}/portal-access", [PartyPortalAccessController::class, 'store'])->middleware('throttle:initial-password');
                Route::post("{$party}/portal-access/password", [PartyPortalAccessController::class, 'password'])->middleware('throttle:initial-password');
                Route::patch("{$party}/portal-access", [PartyPortalAccessController::class, 'update']);
                Route::delete("{$party}/portal-access", [PartyPortalAccessController::class, 'destroy']);
            }

            Route::get('suppliers/{supplier}/offerings', [SupplierOfferingController::class, 'index']);
            Route::post('suppliers/{supplier}/offerings', [SupplierOfferingController::class, 'store']);
            Route::patch('suppliers/{supplier}/offerings/{offering}', [SupplierOfferingController::class, 'update'])->where('offering', '[0-9A-Za-z]{26}');
            Route::delete('suppliers/{supplier}/offerings/{offering}', [SupplierOfferingController::class, 'destroy'])->where('offering', '[0-9A-Za-z]{26}');
            Route::get('suppliers/{supplier}/documents', [SupplierDocumentController::class, 'index']);
            Route::post('suppliers/{supplier}/documents', [SupplierDocumentController::class, 'store']);
            Route::get('suppliers/{supplier}/documents/{document}/download', [SupplierDocumentController::class, 'download'])->where('document', '[0-9A-Za-z]{26}');
            Route::patch('suppliers/{supplier}/documents/{document}', [SupplierDocumentController::class, 'archive'])->where('document', '[0-9A-Za-z]{26}');

            // --- Fase 2b: configuración de la empresa activa ---
            Route::get('company', [CompanyController::class, 'show']);
            Route::patch('company', [CompanyController::class, 'update'])->middleware('permission:company.manage');
            Route::middleware('permission:users.manage')->group(function (): void {
                Route::get('company/users', [CompanyController::class, 'users']);
                Route::get('company/roles', [CompanyController::class, 'roles']);
                Route::post('company/users', [CompanyController::class, 'invite']);
                Route::patch('company/users/{publicId}', [CompanyController::class, 'updateUser'])->where('publicId', '[0-9A-Za-z]{26}');
                Route::delete('company/users/{publicId}', [CompanyController::class, 'removeUser'])->where('publicId', '[0-9A-Za-z]{26}');
                Route::post('company/users/{publicId}/initial-password', [CompanyController::class, 'initialPassword'])->where('publicId', '[0-9A-Za-z]{26}')->middleware('throttle:initial-password');
            });

            // --- Fase 3: compras privadas ---
            Route::get('procurement/references', [PurchaseRequestController::class, 'references'])->middleware('permission:purchase_requests.read');
            Route::middleware('permission:purchase_requests.read')->group(function (): void {
                Route::get('purchase-requests', [PurchaseRequestController::class, 'index']);
                Route::get('purchase-requests/{purchaseRequest}', [PurchaseRequestController::class, 'show']);
                Route::post('purchase-requests/{purchaseRequest}/cancel', [PurchaseRequestController::class, 'cancel']); // reglas finas en el controlador
            });
            Route::post('purchase-requests', [PurchaseRequestController::class, 'store'])->middleware('permission:purchase_requests.create');
            Route::patch('purchase-requests/{purchaseRequest}', [PurchaseRequestController::class, 'update'])->middleware('permission:purchase_requests.create');
            Route::post('purchase-requests/{purchaseRequest}/submit', [PurchaseRequestController::class, 'submit'])->middleware('permission:purchase_requests.submit');
            Route::middleware('permission:quotations.create')->group(function (): void {
                Route::post('purchase-requests/{purchaseRequest}/start-quoting', [PurchaseRequestController::class, 'startQuoting']);
                Route::post('purchase-requests/{purchaseRequest}/quotation-requests', [PurchaseRequestController::class, 'requestQuote']);
                Route::post('purchase-requests/{purchaseRequest}/quotations', [PurchaseRequestController::class, 'registerQuotation']);
                Route::post('purchase-requests/{purchaseRequest}/quotations/{quotation}/discard', [PurchaseRequestController::class, 'discardQuotation'])->where('quotation', '[0-9A-Za-z]{26}');
            });
            Route::delete('purchase-requests/{purchaseRequest}/quotations/{quotation}', [PurchaseRequestController::class, 'destroyQuotation'])->where('quotation', '[0-9A-Za-z]{26}')->middleware('permission:quotations.delete');
            Route::middleware('permission:quotations.compare')->group(function (): void {
                Route::post('purchase-requests/{purchaseRequest}/compare', [PurchaseRequestController::class, 'compare']);
                Route::post('purchase-requests/{purchaseRequest}/propose', [PurchaseRequestController::class, 'propose']);
            });
            Route::post('purchase-requests/{purchaseRequest}/decision', [PurchaseRequestController::class, 'decide']); // approve/reject según decisión
            Route::post('purchase-requests/{purchaseRequest}/order', [InternalPurchaseOrderController::class, 'store'])->middleware('permission:purchase_orders.create');

            Route::middleware('permission:purchase_orders.read')->group(function (): void {
                Route::get('purchase-orders', [InternalPurchaseOrderController::class, 'index']);
                Route::get('purchase-orders/{order}', [InternalPurchaseOrderController::class, 'show']);
            });
            Route::post('purchase-orders/{order}/sent', [InternalPurchaseOrderController::class, 'markSent'])->middleware('permission:purchase_orders.create');
            Route::post('purchase-orders/{order}/cancel', [InternalPurchaseOrderController::class, 'cancel'])->middleware('permission:purchase_orders.cancel');
            Route::delete('purchase-orders/{order}', [InternalPurchaseOrderController::class, 'destroy'])->middleware('permission:purchase_orders.delete');
            Route::post('purchase-orders/{order}/receipts', [InternalPurchaseOrderController::class, 'receive'])->middleware('permission:purchase_orders.receive');

            // Fase 3, pasos 11–12: facturas, pago informado e indicadores.
            Route::get('invoices', [InvoiceController::class, 'index'])->middleware('permission:purchase_orders.read');
            Route::post('purchase-orders/{order}/invoices', [InvoiceController::class, 'store'])->middleware('permission:invoices.manage');
            Route::post('invoices/{invoice}/payment', [InvoiceController::class, 'pay'])->middleware('permission:invoices.manage');
            Route::get('procurement/indicators', [InvoiceController::class, 'indicators'])->middleware('permission:reports.read');

            // --- Fase 5: inventario ---
            Route::middleware('permission:inventory.read')->group(function (): void {
                Route::get('inventory/references', [InventoryController::class, 'references']);
                Route::get('warehouses', [InventoryController::class, 'warehouses']);
                Route::get('inventory/stock', [InventoryController::class, 'stock']);
                Route::get('inventory/movements', [InventoryController::class, 'movements']);
            });
            Route::middleware('permission:inventory.manage')->group(function (): void {
                Route::post('warehouses', [InventoryController::class, 'storeWarehouse']);
                Route::patch('warehouses/{warehouse}', [InventoryController::class, 'updateWarehouse']);
                Route::post('inventory/movements', [InventoryController::class, 'move']);
                Route::patch('inventory/stock/{warehouse}/{product}', [InventoryController::class, 'levels'])->where(['warehouse' => '[0-9A-Za-z]{26}', 'product' => '[0-9A-Za-z]{26}']);
            });

            // --- Fase 5: logística ---
            Route::middleware('permission:shipments.read')->group(function (): void {
                Route::get('logistics/references', [LogisticsController::class, 'references']);
                Route::get('carriers', [LogisticsController::class, 'carriers']);
                Route::get('shipments', [LogisticsController::class, 'index']);
                Route::get('shipments/{shipment}', [LogisticsController::class, 'show']);
                Route::get('shipments/{shipment}/evidence', [LogisticsController::class, 'evidence']);
            });
            Route::middleware('permission:shipments.update')->group(function (): void {
                Route::post('carriers', [LogisticsController::class, 'storeCarrier']);
                Route::patch('carriers/{carrier}', [LogisticsController::class, 'updateCarrier']);
                Route::post('shipments', [LogisticsController::class, 'store']);
                Route::patch('shipments/{shipment}', [LogisticsController::class, 'update']);
                Route::post('shipments/{shipment}/status', [LogisticsController::class, 'status']);
                Route::post('shipments/{shipment}/events', [LogisticsController::class, 'note']);
                Route::post('shipments/{shipment}/incidents', [LogisticsController::class, 'reportIncident']);
                Route::post('shipments/{shipment}/incidents/{incident}/resolve', [LogisticsController::class, 'resolveIncident'])->where('incident', '[0-9A-Za-z]{26}');
            });
            Route::post('shipments/{shipment}/deliver', [LogisticsController::class, 'deliver'])->middleware('permission:shipments.confirm_delivery');

            // --- Fase 6: agentes IA (solo análisis; no escriben datos de negocio) ---
            Route::middleware('permission:agents.read')->group(function (): void {
                Route::get('agents', [AgentController::class, 'index']);
                Route::get('agent-runs', [AgentController::class, 'runs']);
                Route::get('agent-runs/{run}', [AgentController::class, 'show']);
            });
            Route::post('agents/{agentId}/runs', [AgentController::class, 'run'])
                ->where('agentId', '[a-z_]{3,64}')
                ->middleware(['permission:agents.run', 'throttle:agents']);
            Route::post('agent-runs/{run}/review', [AgentController::class, 'review'])->middleware('permission:agents.review');

            Route::post('organizations/{organization}/contacts', [ContactController::class, 'store']);

            Route::get('categories', [CatalogController::class, 'categories']);
            Route::post('categories', [CatalogController::class, 'storeCategory']);
            Route::get('products', [CatalogController::class, 'products']);
            Route::post('products', [CatalogController::class, 'storeProduct']);
            Route::get('products/{product}', [CatalogController::class, 'showProduct']);
            Route::patch('products/{product}', [CatalogController::class, 'updateProduct']);
        });

        // --- Mercado Público ---
        Route::prefix('public-procurement')->group(function (): void {
            Route::middleware('permission:public_procurement.read')->group(function (): void {
                Route::get('catalogs', PublicProcurementCatalogController::class);
                Route::get('compra-agil', [CompraAgilController::class, 'index']);
                Route::get('compra-agil/{code}', [CompraAgilController::class, 'show'])
                    ->where('code', '[0-9A-Za-z\-]{3,64}')
                    ->middleware('throttle:mp-detail');
                Route::get('sync-status', [MercadoPublicoSyncController::class, 'status']);

                // Documentos del proceso: adjuntos oficiales (solo nombre) + copias subidas por la empresa (TD-025).
                Route::get('files', [PublicOpportunityFileController::class, 'index'])->middleware('tenant.required');
                Route::get('files/{file}/download', [PublicOpportunityFileController::class, 'download'])->where('file', '[0-9A-Za-z]{26}')->middleware('tenant.required');
                Route::get('files-archive', [PublicOpportunityFileController::class, 'archive']); // "Descargar todo" en ZIP (TD-030)
                Route::middleware('permission:public_procurement.documents')->group(function (): void {
                    Route::post('files', [PublicOpportunityFileController::class, 'store'])->middleware(['tenant.required', 'throttle:uploads']);
                    Route::delete('files/{file}', [PublicOpportunityFileController::class, 'destroy'])->where('file', '[0-9A-Za-z]{26}')->middleware('tenant.required');
                });

                // Búsquedas guardadas, coincidencias y alertas (privado por empresa; sin consumo de cuota).
                Route::get('saved-searches', [SavedSearchController::class, 'index'])->middleware('tenant.required');
                Route::post('saved-searches', [SavedSearchController::class, 'store'])->middleware('tenant.required');
                Route::patch('saved-searches/{savedSearch}', [SavedSearchController::class, 'update'])->middleware('tenant.required');
                Route::post('saved-searches/{savedSearch}/run', [SavedSearchController::class, 'run'])->middleware(['tenant.required', 'throttle:mp-detail']);
                Route::get('matches', [SavedSearchController::class, 'matches'])->middleware('tenant.required');
                Route::patch('matches/{match}', [SavedSearchController::class, 'updateMatch'])->middleware('tenant.required');
                Route::get('alerts', [SavedSearchController::class, 'alerts'])->middleware('tenant.required');
                Route::post('alerts/read', [SavedSearchController::class, 'markRead'])->middleware('tenant.required');

                // API legacy V1 (cada consulta no cacheada consume cuota del ticket).
                Route::middleware('throttle:mp-v1')->group(function (): void {
                    Route::get('tenders', [TenderController::class, 'index']);
                    Route::get('tenders/{code}', [TenderController::class, 'show'])->where('code', '[0-9A-Za-z\-]{3,64}');
                    Route::get('purchase-orders', [PublicPurchaseOrderController::class, 'index']);
                    Route::get('purchase-orders/{code}', [PublicPurchaseOrderController::class, 'show'])->where('code', '[0-9A-Za-z\-]{3,64}');
                    Route::get('buyers', [PublicDirectoryController::class, 'buyers']);
                    Route::get('suppliers/lookup', [PublicDirectoryController::class, 'supplierLookup']);
                });
            });

            Route::post('sync', [MercadoPublicoSyncController::class, 'trigger'])
                ->middleware(['permission:public_procurement.sync', 'throttle:mp-sync']);
        });
    });
});
