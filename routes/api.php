<?php

use App\Modules\Auth\AuthController;
use App\Modules\Catalog\CatalogController;
use App\Modules\Inventory\InventoryController;
use App\Modules\Invoices\InvoiceController;
use App\Modules\Lookup\LookupController;
use App\Modules\Orders\AdminOrderController;
use App\Modules\Orders\CashController;
use App\Modules\Orders\OrderController;
use App\Modules\Orders\SaleController;
use App\Modules\Payments\MercadoPagoController;
use App\Modules\Payments\PaymentController;
use App\Modules\Payments\SandboxController;
use App\Modules\Reports\ReportController;
use App\Modules\Suppliers\SupplierController;
use App\Modules\Users\AuditController;
use App\Modules\Users\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->controller(AuthController::class)->group(function () {
    Route::post('register', 'register')->middleware('throttle:10,1');
    Route::post('login', 'login')->middleware('throttle:10,1');
    Route::post('google', 'google')->middleware('throttle:10,1');
    Route::post('refresh', 'refresh')->middleware('throttle:30,1');
    Route::post('logout', 'logout')->middleware('throttle:30,1');
    Route::get('me', 'me')->middleware('jwt');
    Route::get('profile', 'profile')->middleware('jwt');
    Route::post('password', 'password')->middleware(['jwt', 'throttle:10,1']);
});
Route::prefix('catalog')->controller(CatalogController::class)->group(function () {
    Route::get('products', 'index');
    Route::get('products/{id}', 'show')->whereUuid('id');
    Route::get('categories', 'categories');
    Route::get('brands', 'brands');
    Route::post('products', 'store')->middleware(['jwt', 'permission:catalog:write']);
    Route::post('internal/products', 'store')->middleware('internal');
});
Route::get('orders/delivery-options', [OrderController::class, 'deliveryOptions']);
Route::get('payments/methods', [PaymentController::class, 'methods']);
// Notificación de la pasarela: se autentica con la firma HMAC del cuerpo, no con JWT.
Route::post('payments/webhooks/sandbox', [SandboxController::class, 'webhook'])->middleware('throttle:120,1');
// Mercado Pago: firma x-signature; el pago se consulta en su API antes de aplicarlo.
Route::post('payments/webhooks/mercadopago', [MercadoPagoController::class, 'webhook'])->middleware('throttle:120,1');
Route::get('orders/internal/{id}', [OrderController::class, 'internalShow'])->middleware('internal')->whereUuid('id');
Route::post('orders/internal/{id}/paid', [OrderController::class, 'paid'])->middleware('internal')->whereUuid('id');
Route::middleware('jwt')->group(function () {
    Route::get('lookup/ruc/{ruc}', [LookupController::class, 'ruc'])->middleware('throttle:30,1')->where('ruc', '[0-9]{11}');
    Route::get('orders', [OrderController::class, 'index']);
    Route::post('orders', [OrderController::class, 'store']);
    Route::get('orders/{id}', [OrderController::class, 'show'])->whereUuid('id');
    // Comprobantes del cliente: solo los aceptados de sus propios pedidos.
    Route::get('orders/{id}/invoices', [InvoiceController::class, 'ofOrder'])->whereUuid('id');
    Route::get('orders/{id}/invoices/{invoice}/{format}', [InvoiceController::class, 'customerFile'])->whereUuid(['id', 'invoice'])->whereIn('format', ['pdf', 'xml', 'cdr']);
    Route::patch('orders/{id}/status/{status}', [OrderController::class, 'status'])->middleware('permission:orders:manage')->whereUuid('id');
    Route::prefix('admin')->group(function () {
        Route::get('orders', [AdminOrderController::class, 'index'])->middleware('permission:orders:manage');
        Route::get('orders/{id}', [AdminOrderController::class, 'show'])->middleware('permission:orders:manage')->whereUuid('id');
        Route::get('payments', [AdminOrderController::class, 'payments'])->middleware('permission:payments:confirm');
        Route::get('reports/summary', [ReportController::class, 'summary'])->middleware('permission:reports:view');
        Route::get('reports/sales', [ReportController::class, 'sales'])->middleware('permission:reports:view');
        Route::post('sales', [SaleController::class, 'store'])->middleware('permission:sales:create');
        Route::get('customers', [SaleController::class, 'customers'])->middleware('permission:sales:create');
        Route::prefix('cash')->middleware('permission:cash:manage')->controller(CashController::class)->group(function () {
            Route::get('current', 'current');
            Route::post('open', 'open');
            Route::post('movements', 'movement');
            Route::post('close', 'close');
            Route::get('sessions', 'sessions');
        });
        Route::post('notifications/{id}/resend', [AdminOrderController::class, 'resendNotification'])->middleware('permission:orders:manage')->whereUuid('id');
        // Solo superadmin: compras al por mayor, uso interno, cuentas del personal y bitácora.
        Route::middleware('permission:inventory:manage')->controller(InventoryController::class)->group(function () {
            Route::get('purchases', 'purchases');
            Route::post('purchases', 'storePurchase');
            Route::get('internal-uses', 'internalUses');
            Route::post('internal-uses', 'storeInternalUse');
        });
        Route::prefix('users')->middleware('permission:users:manage')->controller(UserController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::post('{id}/validate', 'validateAccount')->whereUuid('id');
            Route::post('{id}/suspend', 'suspend')->whereUuid('id');
            Route::post('{id}/reactivate', 'reactivate')->whereUuid('id');
            Route::post('{id}/reset-password', 'resetPassword')->whereUuid('id');
        });
        Route::get('audit', [AuditController::class, 'index'])->middleware('permission:audit:view');
    });
    Route::post('payments', [PaymentController::class, 'store']);
    Route::get('payments/{id}', [PaymentController::class, 'show'])->whereUuid('id');
    Route::post('payments/{id}/confirm', [PaymentController::class, 'confirm'])->middleware('permission:payments:confirm')->whereUuid('id');
    Route::prefix('suppliers')->middleware('permission:suppliers:manage')->controller(SupplierController::class)->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store');
        Route::get('jobs', 'jobs');
        Route::get('jobs/{id}/history', 'history')->whereUuid('id');
        Route::post('{id}/run', 'run')->whereUuid('id');
    });
    Route::prefix('invoices')->middleware('permission:invoices:submit')->controller(InvoiceController::class)->group(function () {
        Route::post('/', 'store');
        Route::get('series', 'series');
        Route::post('series', 'createSeries');
        Route::patch('series/{id}', 'updateSeries')->whereNumber('id');
        Route::get('{id}', 'show')->whereUuid('id');
        Route::patch('{id}', 'update')->whereUuid('id');
        Route::post('{id}/submit', 'submit')->whereUuid('id');
        Route::post('{id}/reconcile', 'reconcile')->whereUuid('id');
        Route::post('{id}/void', 'void')->whereUuid('id');
        Route::post('{id}/void/check', 'checkVoid')->whereUuid('id');
        Route::get('{id}/{format}', 'file')->whereUuid('id')->whereIn('format', ['pdf', 'xml', 'cdr']);
    });
});
