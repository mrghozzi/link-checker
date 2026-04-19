<?php

declare(strict_types=1);

use App\Helpers\Hooks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use MyAds\Plugins\LinkChecker\LinkCheckerService;

// ─── Autoloader ──────────────────────────────────────────────────────
$linkCheckerBasePath = __DIR__;
$linkCheckerNamespace = 'MyAds\\Plugins\\LinkChecker\\';

if (!function_exists('link_checker_autoload_registered')) {
    function link_checker_autoload_registered(string $namespace, string $basePath): bool
    {
        static $registered = false;

        if ($registered) {
            return true;
        }

        spl_autoload_register(static function (string $class) use (&$registered, $namespace, $basePath): void {
            if (!str_starts_with($class, $namespace)) {
                return;
            }

            $relativePath = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($namespace)));
            $filePath = $basePath . '/src/' . $relativePath . '.php';

            if (is_file($filePath)) {
                require_once $filePath;
            }
        });

        $registered = true;

        return true;
    }
}

link_checker_autoload_registered($linkCheckerNamespace, $linkCheckerBasePath);

// ─── Service Singleton ───────────────────────────────────────────────
if (!function_exists('link_checker_service')) {
    function link_checker_service(): LinkCheckerService
    {
        static $instance = null;

        if (!$instance instanceof LinkCheckerService) {
            $instance = new LinkCheckerService();
        }

        return $instance;
    }
}

// ─── View Namespace ──────────────────────────────────────────────────
View::addNamespace('link_checker', __DIR__ . '/views');

// ─── Routes ──────────────────────────────────────────────────────────
Route::middleware(['web', 'auth', 'admin'])->group(function () {

    // Main page
    Route::get('/admin/link-checker', function () {
        $service = link_checker_service();

        return view('link_checker::index', [
            'catalog' => $service->catalog(),
        ]);
    })->name('admin.link-checker.index');

    // AJAX: Collect URLs from selected sources
    Route::post('/admin/link-checker/collect', function (Request $request): JsonResponse {
        $service = link_checker_service();

        $sources = $request->input('sources', []);

        if (!is_array($sources) || empty($sources)) {
            return response()->json(['error' => 'لم يتم تحديد أي نطاق'], 422);
        }

        $urls = $service->collectUrls($sources);

        return response()->json([
            'urls'  => $urls,
            'total' => count($urls),
        ]);
    })->name('admin.link-checker.collect');

    // AJAX: Scan a batch of URLs
    Route::post('/admin/link-checker/scan', function (Request $request): JsonResponse {
        $service = link_checker_service();

        $entries = $request->input('entries', []);

        if (!is_array($entries) || empty($entries)) {
            return response()->json(['error' => 'لا توجد روابط للفحص'], 422);
        }

        // Safety: limit batch size to 20
        $entries = array_slice($entries, 0, 20);

        $results = $service->checkBatch($entries);

        return response()->json([
            'results' => $results,
        ]);
    })->name('admin.link-checker.scan');

    // AJAX: Save results to session
    Route::post('/admin/link-checker/save', function (Request $request): JsonResponse {
        $results = $request->input('results', []);
        $summary = $request->input('summary', []);

        session([
            'link_checker_results' => is_array($results) ? $results : [],
            'link_checker_summary' => is_array($summary) ? $summary : [],
        ]);

        return response()->json(['ok' => true]);
    })->name('admin.link-checker.save');

    // AJAX: Clear saved results
    Route::post('/admin/link-checker/clear', function (): JsonResponse {
        session()->forget(['link_checker_results', 'link_checker_summary']);

        return response()->json(['ok' => true]);
    })->name('admin.link-checker.clear');
});

// ─── Admin Sidebar Hook ─────────────────────────────────────────────
Hooks::add_action('admin_sidebar_menu', function (): void {
    $url = route('admin.link-checker.index');
    $isActive = request()->routeIs('admin.link-checker.*');
    $linkClass = $isActive ? 'nxl-link active' : 'nxl-link';

    echo '<li class="nxl-item">'
        . '<a href="' . e($url) . '" class="' . e($linkClass) . '">'
        . '<span class="nxl-micon"><i class="feather-link"></i></span>'
        . '<span class="nxl-mtext">فاحص الروابط</span>'
        . '</a>'
        . '</li>';
});
