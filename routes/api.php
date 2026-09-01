<?php

use App\Http\Controllers\Api\AccessCodeController;
use App\Http\Controllers\Api\AdminFormasiImportController;
use App\Http\Controllers\Api\AdminDummyParticipantController;
use App\Http\Controllers\Api\AdminInstansiController;
use App\Http\Controllers\Api\ProofRequirementController;
use App\Http\Controllers\Api\InstansiController;
use App\Http\Controllers\Api\AdminAccessCodeController;
use App\Http\Controllers\Api\AdminOrderController;
use App\Http\Controllers\Api\AdminPackageController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PackageCatalogController;
use App\Http\Controllers\Api\PerguruanTinggiController;
use App\Http\Controllers\Api\PaymentCallbackController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\QuestionController;
use App\Http\Controllers\Api\SubtestController;
use App\Http\Controllers\Api\TryoutController;
use App\Http\Controllers\Api\TryoutSubtestController;
use App\Http\Controllers\Api\UserTryoutController;
use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Api\AdminStatsController;
use App\Http\Controllers\Api\AdminAuditLogController;
use App\Http\Controllers\Api\AdminSalesReportController;
use App\Http\Controllers\Api\AdminTicketRedeemCodeController;
use App\Http\Controllers\Api\AdminTryoutProofController;
use App\Http\Controllers\Api\BulkImportQuestionController;
use App\Http\Controllers\Api\SubtestCategoryController;
use App\Http\Controllers\Api\TicketLogController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public Routes
|--------------------------------------------------------------------------
*/

Route::post('/midtrans/callback', [PaymentCallbackController::class, 'handle']);

Route::prefix('auth')->controller(AuthController::class)->group(function () {
    Route::post('/register', 'register')->middleware('throttle:5,1');
    Route::post('/login', 'login')->middleware('throttle:5,1');
    Route::get('/google/redirect', 'redirectToGoogle');
    Route::get('/google/callback', 'handleGoogleCallback');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', 'me');
        Route::post('/logout', 'logout');
    });
});

/*
|--------------------------------------------------------------------------
| Authenticated User Routes (Siswa)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {
    Route::put('/profile/update', [ProfileController::class, 'update'])->middleware('throttle:15,1');
    Route::put('/profile/kategori', [ProfileController::class, 'updateKategori'])->middleware('throttle:15,1');
    Route::post('/access-codes/redeem', [AccessCodeController::class, 'redeem']);
    Route::get('/ticket-logs', [TicketLogController::class, 'index']);

    // Dibaca halaman pendaftaran tryout gratis untuk menampilkan akun yang
    // harus di-follow sekaligus menentukan berapa bukti yang diminta.
    Route::get('/proof-requirements', [ProofRequirementController::class, 'index']);
    Route::get('/subtests', [SubtestController::class, 'index']);
    Route::get('/subtest-categories', [SubtestCategoryController::class, 'index']);

    // Reference data for the target-campus pickers. Read-only; refreshed by
    // reseeding, never through the API. /program-studi/jenjang is declared
    // before any wildcard so it can never be swallowed by one later.
    Route::get('/perguruan-tinggi', [PerguruanTinggiController::class, 'index']);
    Route::get('/perguruan-tinggi/{perguruanTinggi}', [PerguruanTinggiController::class, 'show']);
    Route::get('/perguruan-tinggi/{perguruanTinggi}/program-studi', [PerguruanTinggiController::class, 'programStudi']);
    Route::get('/program-studi/jenjang', [PerguruanTinggiController::class, 'jenjang']);
    Route::get('/program-studi', [PerguruanTinggiController::class, 'searchProgramStudi']);

    // Target pelamar CPNS umum. Dua tingkat seperti kampus/prodi, supaya picker
    // yang sama bisa dipakai untuk keduanya.
    Route::get('/instansi', [InstansiController::class, 'index']);
    Route::get('/instansi/{instansi}/formasi', [InstansiController::class, 'formasi']);
    Route::get('/formasi', [InstansiController::class, 'searchFormasi']);
    // Dipakai form profil untuk tahu apakah daftar formasi periode ini sudah
    // terbit. Kalau belum, kolomnya diganti pemberitahuan alih-alih picker kosong.
    Route::get('/formasi/status', [InstansiController::class, 'status']);

    // Package & Orders
    Route::apiResource('packages', PackageCatalogController::class)->only(['index', 'show']);
    Route::get('/my-orders', [OrderController::class, 'index']);
    Route::apiResource('orders', OrderController::class)->only(['store', 'show']);
    Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel']);
    Route::post('/orders/{order}/verify-payment', [OrderController::class, 'verifyPayment'])->middleware('throttle:10,1');

    // --- Ujian & Ujian Tryout (User) ---
    Route::controller(UserTryoutController::class)->group(function () {
        Route::get('/tryouts', 'index');
        Route::get('/my-tryouts', 'myTryouts');
        // Declared before the tryouts/{tryout} prefix group below so the
        // detail route is matched, not swallowed by one of its children.
        Route::get('/tryouts/{tryout}', 'show');

        Route::prefix('tryouts/{tryout}')->group(function () {
            Route::post('/enroll', 'enroll');
            Route::post('/start', 'start');
            Route::post('/finish', 'finish');
            Route::get('/result', 'result');
            Route::get('/leaderboard', 'leaderboard');
            Route::get('/review', 'review');
            Route::post('/unlock-discussion', 'unlockDiscussion');

            Route::prefix('subtests/{tryoutSubtest}')->group(function () {
                Route::post('/start', 'startSubtest');
                Route::post('/finish', 'finishSubtest');
                Route::get('/exam', 'showSubtestQuestions');
                Route::post('/questions/{question}/answer', 'submitAnswer');
            });
        });
    });
});

/*
|--------------------------------------------------------------------------
| Admin Routes
|--------------------------------------------------------------------------
*/
// ->name('admin.') is required, not cosmetic: prefix() changes the URI but
// not the route name, so the admin apiResources below generated the same
// names as the public ones (packages.index, orders.show). Laravel tolerates
// duplicate names until `route:cache`, which refuses to serialize them - and
// until then route('packages.index') silently resolved to whichever was
// registered last.
Route::middleware(['auth:sanctum', 'admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {

        Route::get('/stats', [AdminStatsController::class, 'index']);
        Route::get('/sales-report', [AdminSalesReportController::class, 'index']);
        Route::get('/fee-to-report', [AdminSalesReportController::class, 'feeTryout']);
        Route::get('/tryout-proof-images', [AdminTryoutProofController::class, 'index']);
        Route::get('/tryout-proof-images/statuses', [AdminTryoutProofController::class, 'statuses']);
        Route::patch('/tryout-proof-images/{access}/review', [AdminTryoutProofController::class, 'review']);
        Route::get('/audit-logs', [AdminAuditLogController::class, 'index']);
        Route::get('/audit-logs/modules', [AdminAuditLogController::class, 'modules']);
        Route::apiResource('ticket-redeem-codes', AdminTicketRedeemCodeController::class)
            ->except(['show']);
        Route::get('/users', [AdminUserController::class, 'index']);
        Route::get('/users/export', [AdminUserController::class, 'export']);
        Route::get('/users/{user}', [AdminUserController::class, 'show']);
        Route::delete('/users/{user}', [AdminUserController::class, 'destroy']);

        // --- SUBTEST & MASTER SOAL ---
        Route::get('/subtest-categories', [SubtestCategoryController::class, 'adminIndex']);
        Route::post('/subtest-categories', [SubtestCategoryController::class, 'store']);
        Route::put('/subtest-categories/{subtestCategory}', [SubtestCategoryController::class, 'update']);
        Route::patch('/subtest-categories/{subtestCategory}/toggle-active', [SubtestCategoryController::class, 'toggleActive']);
        Route::delete('/subtest-categories/{subtestCategory}', [SubtestCategoryController::class, 'destroy']);

        Route::apiResource('subtests', SubtestController::class)->except(['index']);
        Route::apiResource('subtests.questions', QuestionController::class);
        Route::post('/subtests/{subtest}/questions/bulk-import', [BulkImportQuestionController::class, 'store']);
        Route::get('/questions/bulk-import/excel-template', [BulkImportQuestionController::class, 'excelTemplate']);

        // Instansi dan formasi. Rekap formasi resmi tidak tersedia dalam bentuk
        // yang bisa diunduh, jadi tanpa endpoint ini formasi hanya bisa masuk
        // lewat seeder di server.
        Route::get('/instansi', [AdminInstansiController::class, 'index']);
        Route::post('/instansi', [AdminInstansiController::class, 'storeInstansi']);
        Route::put('/instansi/{instansi}', [AdminInstansiController::class, 'updateInstansi']);
        Route::delete('/instansi/{instansi}', [AdminInstansiController::class, 'destroyInstansi']);
        Route::get('/instansi/{instansi}/formasi', [AdminInstansiController::class, 'formasi']);
        Route::post('/instansi/{instansi}/formasi', [AdminInstansiController::class, 'storeFormasi']);
        Route::delete('/instansi/{instansi}/formasi/{formasi}', [AdminInstansiController::class, 'destroyFormasi']);

        // Impor massal. Satu periode seleksi bisa memuat ribuan formasi, jadi
        // mengisinya lewat form per baris bukan pilihan yang masuk akal.
        Route::post('/formasi/import', [AdminFormasiImportController::class, 'store']);
        Route::get('/formasi/import/template', [AdminFormasiImportController::class, 'template']);

        Route::get('/proof-requirements', [ProofRequirementController::class, 'adminIndex']);
        Route::post('/proof-requirements', [ProofRequirementController::class, 'store']);
        // Urutan ditetapkan sekaligus: menggeser satu syarat selalu mengubah
        // posisi yang lain, jadi mengirimnya satu-satu melewati keadaan di mana
        // dua baris punya urutan sama.
        Route::put('/proof-requirements/reorder', [ProofRequirementController::class, 'reorder']);
        Route::put('/proof-requirements/{proofRequirement}', [ProofRequirementController::class, 'update']);
        Route::delete('/proof-requirements/{proofRequirement}', [ProofRequirementController::class, 'destroy']);

        // --- TRYOUT & PENGATURAN TRYOUT ---
        Route::get('/tryouts/dummy-excel-template', [AdminDummyParticipantController::class, 'template']);
        Route::post('/tryouts/{tryout}/inject-dummy-random', [AdminDummyParticipantController::class, 'injectRandom']);
        Route::post('/tryouts/{tryout}/inject-dummy-excel', [AdminDummyParticipantController::class, 'injectExcel']);
        Route::delete('/tryouts/{tryout}/clear-dummy', [AdminDummyParticipantController::class, 'clear']);
        Route::get('/tryouts/{tryout}/dummy-summary', [AdminDummyParticipantController::class, 'summary']);
        Route::get('/tryouts/{tryout}/participants', [TryoutController::class, 'participants']);
        Route::apiResource('tryouts', TryoutController::class);
        Route::get('/tryouts/{tryout}/export-pdf', [TryoutController::class, 'exportPdf']);
        Route::get('/tryouts/{tryout}/users/{user}/review', [TryoutController::class, 'userReview']);
        Route::apiResource('tryouts.subtests', TryoutSubtestController::class)
            ->parameters(['subtests' => 'tryoutSubtest'])
            ->except(['show']);
        Route::apiResource('tryouts.access-codes', AdminAccessCodeController::class);

        // --- PACKAGES & ORDERS ---
        Route::apiResource('packages', AdminPackageController::class);
        Route::get('/packages/{package}/tryouts', [AdminPackageController::class, 'getTryouts']);
        Route::post('/packages/{package}/tryouts', [AdminPackageController::class, 'attachTryout']);
        Route::delete('/packages/{package}/tryouts/{tryout}', [AdminPackageController::class, 'detachTryout']);
        Route::apiResource('orders', AdminOrderController::class)->only(['index', 'show']);
        Route::controller(AdminOrderController::class)->prefix('orders/{order}')->group(function () {
            Route::post('/approve', 'approve');
            Route::post('/reject', 'reject');
        });
    });
