<?php

use App\Http\Controllers\Api\V1\Admin\DealerController as AdminDealerController;
use App\Http\Controllers\Api\V1\Admin\DealerIntegrationController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CreditApplicationController;
use App\Http\Controllers\Api\V1\DealController;
use App\Http\Controllers\Api\V1\DealDocumentController;
use App\Http\Controllers\Api\V1\DealerApiKeyController;
use App\Http\Controllers\Api\V1\DealScenarioController;
use App\Http\Controllers\Api\V1\DeskingCalculatorController;
use App\Http\Controllers\Api\V1\DeliveryAppointmentController;
use App\Http\Controllers\Api\V1\DepositController;
use App\Http\Controllers\Api\V1\DocuSignController;
use App\Http\Controllers\Api\V1\DocuSignWebhookController;
use App\Http\Controllers\Webhooks\DealerTrackWebhookController;
use App\Http\Controllers\Webhooks\RouteOneWebhookController;
use App\Http\Controllers\Api\V1\FiProductController;
use App\Http\Controllers\Api\V1\DealMessageController;
use App\Http\Controllers\Api\V1\NotificationsController;
use App\Http\Controllers\Api\V1\TradeInAppraisalController;
use App\Http\Controllers\Api\V1\LenderRatesController;
use App\Http\Controllers\Api\V1\PreQualController;
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

// ── Public inventory browsing (no auth required, no tenant required) ──────
Route::get('vehicles',           [VehicleController::class, 'index']);
Route::get('vehicles/{vehicle}', [VehicleController::class, 'show']);

// ── Public desking calculator (no auth required) ──────────────────────────
Route::get('vehicles/{vehicle}/desking-config', [DeskingCalculatorController::class, 'config']);
Route::post('vehicles/{vehicle}/pencil',        [DeskingCalculatorController::class, 'pencil']);

// ── Authenticated routes ──────────────────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {

    // Profile
    Route::get('auth/me',          [AuthController::class, 'me']);
    Route::patch('auth/me',        [AuthController::class, 'updateProfile']);
    Route::delete('auth/logout',   [AuthController::class, 'logout']);

    // Notifications (all authenticated users)
    Route::get('notifications',             [NotificationsController::class, 'index']);
    Route::get('notifications/unread-count',[NotificationsController::class, 'unreadCount']);
    Route::post('notifications/read-all',   [NotificationsController::class, 'markAllRead']);
    Route::patch('notifications/{id}',      [NotificationsController::class, 'markRead']);

    // Staff invite — dealer admin only, tenant context required
    Route::middleware(['tenant', 'role:dealer_admin'])->group(function () {
        Route::get('auth/staff',         [AuthController::class, 'listStaff']);
        Route::post('auth/invite-staff', [AuthController::class, 'inviteStaff']);
    });

    // ── Inventory mutations (dealer staff only, requires tenant) ─────────────
    Route::middleware('tenant')->group(function () {

        // Dealer staff / admin only — mutations
        Route::middleware('role:dealer_admin,dealer_staff')->group(function () {
            Route::post('vehicles',               [VehicleController::class, 'store']);
            Route::post('vehicles/import',        [VehicleController::class, 'import']);
            Route::put('vehicles/{vehicle}',      [VehicleController::class, 'update']);
            Route::patch('vehicles/{vehicle}',    [VehicleController::class, 'update']);
            Route::delete('vehicles/{vehicle}',   [VehicleController::class, 'destroy']);
            Route::post('vehicles/{vehicle}/decode-vin', [VehicleController::class, 'decodeVin']);

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

    // Buyers — read own deals (no dealer context needed)
    Route::middleware('role:buyer')->group(function () {
        Route::get('deals',          [DealController::class, 'index']);
        Route::get('deals/{deal}',   [DealController::class, 'show']);
    });

    Route::middleware('tenant')->group(function () {

        // Buyers — open a deal or update terms (tenant resolved via vehicle_id / deal_id)
        Route::middleware('role:buyer')->group(function () {
            Route::post('deals',             [DealController::class, 'store']);
            Route::patch('deals/{deal}',     [DealController::class, 'update']);

            // Desking — buyer can view and select their own scenarios
            Route::get('deals/{deal}/scenarios',                              [DealScenarioController::class, 'index']);
            Route::post('deals/{deal}/scenarios/{scenario}/select',           [DealScenarioController::class, 'select']);

            // Messaging — buyer can read & post messages on their own deals
            Route::get('deals/{deal}/messages',  [DealMessageController::class, 'index']);
            Route::post('deals/{deal}/messages', [DealMessageController::class, 'store']);
        });

        // Dealer admin/staff — manage all deals in their store
        Route::middleware('role:dealer_admin,dealer_staff')->group(function () {
            // Branding — dealer admin can update their own dealership's branding
            Route::patch('dealer/settings/branding',    [AdminDealerController::class, 'updateBranding']);
            Route::post('dealer/settings/logo',         [AdminDealerController::class, 'uploadLogo']);
            Route::patch('dealer/settings/desking',     [AdminDealerController::class, 'updateDeskingConfig']);

            // Integrations — DealerTrack & RouteOne credential management (dealer admin only)
            Route::middleware('role:dealer_admin')->group(function () {
                Route::get('dealer/settings/integrations/{platform}',          [DealerIntegrationController::class, 'show']);
                Route::patch('dealer/settings/integrations/{platform}',         [DealerIntegrationController::class, 'update']);
                Route::delete('dealer/settings/integrations/{platform}',        [DealerIntegrationController::class, 'disconnect']);
                Route::post('dealer/settings/integrations/{platform}/sync',     [DealerIntegrationController::class, 'triggerSync']);
            });

            Route::get('dealer/deals',                               [DealController::class, 'index']);
            Route::get('dealer/deals/{deal}',                        [DealController::class, 'show']);
            Route::patch('dealer/deals/{deal}',                      [DealController::class, 'update']);
            Route::patch('dealer/deals/{deal}/transition',           [DealController::class, 'transition']);
            Route::put('dealer/deals/{deal}/fi-products',            [DealController::class, 'syncFiProducts']);
            Route::post('dealer/deals/{deal}/econtract',             [DealController::class, 'pushEContract']);
            Route::delete('dealer/deals/{deal}',                     [DealController::class, 'destroy']);

            // Desking / payment scenarios — dealer F&I manager
            Route::get('dealer/deals/{deal}/scenarios',                            [DealScenarioController::class, 'index']);
            Route::post('dealer/deals/{deal}/scenarios/generate',                  [DealScenarioController::class, 'generate']);
            Route::put('dealer/deals/{deal}/scenarios/{scenario}',                 [DealScenarioController::class, 'update']);
            Route::post('dealer/deals/{deal}/scenarios/{scenario}/select',         [DealScenarioController::class, 'select']);

            // Messaging — dealer staff can read & post messages on any deal in their store
            Route::get('dealer/deals/{deal}/messages',  [DealMessageController::class, 'index']);
            Route::post('dealer/deals/{deal}/messages', [DealMessageController::class, 'store']);

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
                Route::get('summary',        [ReportingController::class, 'summary']);
                Route::get('funnel',         [ReportingController::class, 'funnel']);
                Route::get('trend',          [ReportingController::class, 'trend']);
                Route::get('top-vehicles',   [ReportingController::class, 'topVehicles']);
                Route::get('top-staff',      [ReportingController::class, 'topStaff']);
                Route::get('inventory',      [ReportingController::class, 'inventory']);
                Route::get('time-to-close',  [ReportingController::class, 'timeToClose']);
                Route::get('fi-attach-rate', [ReportingController::class, 'fiAttachRate']);
                Route::get('credit-approval',[ReportingController::class, 'creditApproval']);
            });

            // Lender rate feed
            Route::get('dealer/lender-rates',       [LenderRatesController::class, 'index']);
            Route::get('dealer/lender-rate-bands',  [LenderRatesController::class, 'bands']);
            Route::put('dealer/lender-rate-bands',  [LenderRatesController::class, 'updateBands']);

            // Trade-in valuation (dealer staff can also trigger)
            Route::post('dealer/deals/{deal}/trade-in/valuate', [TradeInAppraisalController::class, 'valuate']);
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

            // Trade-in appraisal + automated valuation
            Route::post('deals/{deal}/trade-in',          [TradeInAppraisalController::class, 'store']);
            Route::get('deals/{deal}/trade-in',           [TradeInAppraisalController::class, 'show']);
            Route::post('deals/{deal}/trade-in/valuate',  [TradeInAppraisalController::class, 'valuate']);

            // Delivery appointment
            Route::post('deals/{deal}/delivery',   [DeliveryAppointmentController::class, 'store']);
            Route::get('deals/{deal}/delivery',    [DeliveryAppointmentController::class, 'show']);

            // Documents (read-only for buyers)
            Route::get('deals/{deal}/documents',                        [DealDocumentController::class, 'index']);
            Route::get('deals/{deal}/documents/{document}',             [DealDocumentController::class, 'show']);
            Route::get('deals/{deal}/documents/{document}/download',    [DealDocumentController::class, 'download']);

            // Deal summary (post-close jacket)
            Route::get('deals/{deal}/summary', [DealController::class, 'summary']);

            // Soft credit pre-qualification
            Route::post('deals/{deal}/pre-qualify', [PreQualController::class, 'store']);

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

// ── DealerTrack + RouteOne webhooks (public, no tenant, signature-verified) ─
Route::post('webhooks/dealertrack', [DealerTrackWebhookController::class, 'handle']);
Route::post('webhooks/routeone',    [RouteOneWebhookController::class,    'handle']);

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

// ── Dealer API key management (dealer admin, Sanctum auth) ────────────────
Route::middleware(['auth:sanctum', 'tenant', 'role:dealer_admin'])->group(function () {
    Route::get('dealer/api-keys',         [DealerApiKeyController::class, 'index']);
    Route::post('dealer/api-keys',        [DealerApiKeyController::class, 'store']);
    Route::delete('dealer/api-keys/{keyId}', [DealerApiKeyController::class, 'destroy']);
    Route::get('dealer/sync-runs', [VehicleController::class, 'syncRuns']);
    Route::get('dealer/sync-runs/{runId}', [VehicleController::class, 'syncStatus']);
});

// ── Inventory sync via API key (no Sanctum — server-to-server) ───────────
Route::middleware('auth.api_key')->group(function () {
    Route::post('vehicles/sync', [VehicleController::class, 'sync']);
    Route::get('vehicles/sync-runs/{runId}', [VehicleController::class, 'syncStatus']);
});
