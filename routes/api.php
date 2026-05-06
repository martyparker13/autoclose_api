<?php

use App\Http\Controllers\Api\V1\Admin\DealerController as AdminDealerController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CreditApplicationController;
use App\Http\Controllers\Api\V1\DealController;
use App\Http\Controllers\Api\V1\DealDocumentController;
use App\Http\Controllers\Api\V1\DeliveryAppointmentController;
use App\Http\Controllers\Api\V1\DepositController;
use App\Http\Controllers\Api\V1\DocuSignController;
use App\Http\Controllers\Api\V1\DocuSignWebhookController;
use App\Http\Controllers\Api\V1\FiProductController;
use App\Http\Controllers\Api\V1\TradeInAppraisalController;
use App\Http\Controllers\Api\V1\ReportingController;
use App\Http\Controllers\Api\V1\VehicleController;
use App\Http\Controllers\Api\V1\VehicleFeatureController;
use App\Http\Controllers\Api\V1\VehicleMediaController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes  —  all prefixed /api/v1  (set in bootstrap/app.php)
|--------------------------------------------------------------------------
*/

// ── Public auth routes ────────────────────────────────────────────────────
Route::prefix('auth')->group(function () {
    Route::post('register',        [AuthController::class, 'register']);
    Route::post('login',           [AuthController::class, 'login']);
    Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('reset-password',  [AuthController::class, 'resetPassword']);
});

// ── Authenticated routes ──────────────────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {

    // Profile
    Route::get('auth/me',          [AuthController::class, 'me']);
    Route::patch('auth/me',        [AuthController::class, 'updateProfile']);
    Route::delete('auth/logout',   [AuthController::class, 'logout']);

    // Staff invite — dealer admin only, tenant context required
    Route::middleware(['tenant', 'role:dealer_admin'])->group(function () {
        Route::post('auth/invite-staff', [AuthController::class, 'inviteStaff']);
    });

    // ── Inventory (public browsing requires tenant, mutations require staff) ──
    Route::middleware('tenant')->group(function () {

        // Buyers + dealer staff can browse
        Route::get('vehicles',        [VehicleController::class, 'index']);
        Route::get('vehicles/{vehicle}', [VehicleController::class, 'show']);

        // Dealer staff / admin only — mutations
        Route::middleware('role:dealer_admin,dealer_staff')->group(function () {
            Route::post('vehicles',               [VehicleController::class, 'store']);
            Route::post('vehicles/import',        [VehicleController::class, 'import']);
            Route::put('vehicles/{vehicle}',      [VehicleController::class, 'update']);
            Route::patch('vehicles/{vehicle}',    [VehicleController::class, 'update']);
            Route::delete('vehicles/{vehicle}',   [VehicleController::class, 'destroy']);

            // Media
            Route::post('vehicles/{vehicle}/media',          [VehicleMediaController::class, 'store']);
            Route::patch('vehicles/{vehicle}/media/reorder', [VehicleMediaController::class, 'reorder']);
            Route::delete('vehicles/{vehicle}/media/{media}', [VehicleMediaController::class, 'destroy']);

            // Features
            Route::post('vehicles/{vehicle}/features',             [VehicleFeatureController::class, 'store']);
            Route::delete('vehicles/{vehicle}/features/{feature}', [VehicleFeatureController::class, 'destroy']);
        });
    });

    // ── Deals ─────────────────────────────────────────────────────────────────
    Route::middleware('tenant')->group(function () {

        // Buyers — open a deal, view own deals, update terms
        Route::middleware('role:buyer')->group(function () {
            Route::post('deals',             [DealController::class, 'store']);
            Route::get('deals',              [DealController::class, 'index']);
            Route::get('deals/{deal}',       [DealController::class, 'show']);
            Route::patch('deals/{deal}',     [DealController::class, 'update']);
        });

        // Dealer staff / admin — manage all deals in their store
        Route::middleware('role:dealer_admin,dealer_staff')->group(function () {
            Route::get('dealer/deals',                               [DealController::class, 'index']);
            Route::get('dealer/deals/{deal}',                        [DealController::class, 'show']);
            Route::patch('dealer/deals/{deal}',                      [DealController::class, 'update']);
            Route::patch('dealer/deals/{deal}/transition',           [DealController::class, 'transition']);
            Route::put('dealer/deals/{deal}/fi-products',            [DealController::class, 'syncFiProducts']);
            Route::delete('dealer/deals/{deal}',                     [DealController::class, 'destroy']);

            // Trade-in — dealer responds with offer
            Route::patch('dealer/deals/{deal}/trade-in/{appraisal}', [TradeInAppraisalController::class, 'respond']);

            // Delivery appointments — dealer assigns driver / updates status
            Route::patch('dealer/deals/{deal}/delivery/{appointment}', [DeliveryAppointmentController::class, 'update']);

            // Credit application — dealer decision
            Route::patch('dealer/deals/{deal}/credit-application/{creditApp}', [CreditApplicationController::class, 'update']);

            // Deal documents — dealer uploads
            Route::post('dealer/deals/{deal}/documents', [DealDocumentController::class, 'store']);

            // DocuSign — dealer staff sends documents for signature
            Route::post('dealer/deals/{deal}/documents/send-for-signature', [DocuSignController::class, 'sendForSignature']);

            // Reporting — dealer admin/staff
            Route::prefix('dealer/reports')->group(function () {
                Route::get('summary',      [ReportingController::class, 'summary']);
                Route::get('funnel',       [ReportingController::class, 'funnel']);
                Route::get('trend',        [ReportingController::class, 'trend']);
                Route::get('top-vehicles', [ReportingController::class, 'topVehicles']);
                Route::get('top-staff',    [ReportingController::class, 'topStaff']);
                Route::get('inventory',    [ReportingController::class, 'inventory']);
            });
        });

        // ── F&I Products ───────────────────────────────────────────────────────
        // All authenticated users can view active products (buyers selecting during deal)
        Route::get('fi-products', [FiProductController::class, 'index']);

        // Dealer admin/staff management of F&I catalogue
        Route::middleware('role:dealer_admin,dealer_staff')->group(function () {
            Route::get('dealer/fi-products',            [FiProductController::class, 'adminIndex']);
            Route::post('dealer/fi-products',           [FiProductController::class, 'store']);
            Route::get('dealer/fi-products/{product}',  [FiProductController::class, 'show']);
            Route::patch('dealer/fi-products/{product}',[FiProductController::class, 'update']);
            Route::delete('dealer/fi-products/{product}',[FiProductController::class, 'destroy']);
        });

        // ── Deal sub-resources (buyer access) ─────────────────────────────────
        Route::middleware('role:buyer')->group(function () {
            // Credit application
            Route::post('deals/{deal}/credit-application',  [CreditApplicationController::class, 'store']);
            Route::get('deals/{deal}/credit-application',   [CreditApplicationController::class, 'show']);

            // Trade-in appraisal
            Route::post('deals/{deal}/trade-in',   [TradeInAppraisalController::class, 'store']);
            Route::get('deals/{deal}/trade-in',    [TradeInAppraisalController::class, 'show']);

            // Delivery appointment
            Route::post('deals/{deal}/delivery',   [DeliveryAppointmentController::class, 'store']);
            Route::get('deals/{deal}/delivery',    [DeliveryAppointmentController::class, 'show']);

            // Documents (read-only for buyers)
            Route::get('deals/{deal}/documents',           [DealDocumentController::class, 'index']);
            Route::get('deals/{deal}/documents/{document}',[DealDocumentController::class, 'show']);

            // Deposit
            Route::post('deals/{deal}/deposit',         [DepositController::class, 'create']);
            Route::post('deals/{deal}/deposit/confirm', [DepositController::class, 'confirm']);

            // DocuSign — buyer gets embedded signing URL
            Route::get('deals/{deal}/documents/signing-url', [DocuSignController::class, 'signingUrl']);
        });
    });
});

// ── Stripe webhook (public, signature-verified) ───────────────────────────
Route::middleware('tenant')->group(function () {
    Route::post('webhooks/stripe',   [DepositController::class, 'webhook']);
    Route::post('webhooks/docusign', [DocuSignWebhookController::class, 'handle']);
});

// ── Public inventory browsing (unauthenticated buyers) ────────────────────
Route::middleware('tenant')->group(function () {
    Route::get('public/vehicles',           [VehicleController::class, 'index']);
    Route::get('public/vehicles/{vehicle}', [VehicleController::class, 'show']);
});

// ── Super-admin — global dealer management (no tenant context) ────────────
Route::middleware(['auth:sanctum', 'role:super_admin'])->prefix('admin')->group(function () {
    Route::get('dealers',                        [AdminDealerController::class, 'index']);
    Route::post('dealers',                       [AdminDealerController::class, 'store']);
    Route::get('dealers/{dealer}',               [AdminDealerController::class, 'show']);
    Route::put('dealers/{dealer}',               [AdminDealerController::class, 'update']);
    Route::patch('dealers/{dealer}',             [AdminDealerController::class, 'update']);
    Route::delete('dealers/{dealer}',            [AdminDealerController::class, 'destroy']);
    Route::post('dealers/{dealer}/restore',      [AdminDealerController::class, 'restore']);
});
