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

        // Load settings
        $settings = [
            'groq_api_key' => \App\Models\Option::where('name', 'lc_groq_api_key')->value('o_valuer') ?: '',
            'groq_model' => \App\Models\Option::where('name', 'lc_groq_model')->value('o_valuer') ?: 'llama-3.1-8b-instant',
            'smart_scan_limit' => \App\Models\Option::where('name', 'lc_smart_scan_limit')->value('o_valuer') ?: 10,
            'smart_scan_enabled' => \App\Models\Option::where('name', 'lc_smart_scan_enabled')->value('o_valuer') ?: 1,
        ];

        return view('link_checker::index', [
            'catalog' => $service->catalog(),
            'settings' => $settings,
        ]);
    })->name('admin.link-checker.index');

    // AJAX: Save settings
    Route::post('/admin/link-checker/settings', function (Request $request): JsonResponse {
        $data = $request->validate([
            'groq_api_key' => 'nullable|string',
            'groq_model' => 'nullable|string',
            'smart_scan_limit' => 'required|integer|min:1|max:100',
            'smart_scan_enabled' => 'required|boolean',
        ]);

        \App\Models\Option::updateOrCreate(['name' => 'lc_groq_api_key'], ['o_valuer' => $data['groq_api_key'] ?? '', 'o_type' => 'link_checker']);
        \App\Models\Option::updateOrCreate(['name' => 'lc_groq_model'], ['o_valuer' => $data['groq_model'] ?? 'llama-3.1-8b-instant', 'o_type' => 'link_checker']);
        \App\Models\Option::updateOrCreate(['name' => 'lc_smart_scan_limit'], ['o_valuer' => $data['smart_scan_limit'], 'o_type' => 'link_checker']);
        \App\Models\Option::updateOrCreate(['name' => 'lc_smart_scan_enabled'], ['o_valuer' => $data['smart_scan_enabled'] ? 1 : 0, 'o_type' => 'link_checker']);

        return response()->json(['success' => true]);
    })->name('admin.link-checker.settings');

    // AJAX: Test Groq API Key
    Route::post('/admin/link-checker/test-groq', function (Request $request): JsonResponse {
        $apiKey = $request->input('groq_api_key');
        if (empty($apiKey)) {
            return response()->json(['success' => false, 'message' => 'الرجاء إدخال مفتاح API أولاً']);
        }

        try {
            $response = \Illuminate\Support\Facades\Http::withToken($apiKey)
                ->timeout(10)
                ->get('https://api.groq.com/openai/v1/models'); // simple models endpoint to verify auth

            if ($response->successful()) {
                return response()->json(['success' => true, 'message' => 'الاتصال ناجح، الذكاء الاصطناعي يعمل!']);
            }

            return response()->json([
                'success' => false, 
                'message' => 'فشل الاتصال: مفتاح غير صالح أو الخدمة غير متوفرة (Code: ' . $response->status() . ')'
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'خطأ في الاتصال: ' . $e->getMessage()]);
        }
    })->name('admin.link-checker.test-groq');

    // AJAX: Force Execute Smart Scan
    Route::post('/admin/link-checker/force-smart-scan', function (): JsonResponse {
        try {
            \Illuminate\Support\Facades\Artisan::call('link-checker:smart-scan');
            $output = \Illuminate\Support\Facades\Artisan::output();
            return response()->json([
                'success' => true, 
                'message' => 'تم تنفيذ الفحص بنجاح. راجع صفحة التقارير للنتائج.',
                'output' => $output
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'حدث خطأ أثناء التنفيذ: ' . $e->getMessage()]);
        }
    })->name('admin.link-checker.force-smart-scan');

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

// ─── Commands & Scheduling ───────────────────────────────────────────
// Register the command so it can be called via terminal or web route
\Illuminate\Console\Application::starting(function ($artisan) {
    $artisan->resolveCommands([
        \MyAds\Plugins\LinkChecker\Commands\SmartScanCommand::class,
    ]);
});

if (app()->runningInConsole()) {

    // Schedule the command to run hourly
    app()->booted(function () {
        $schedule = app(\Illuminate\Console\Scheduling\Schedule::class);
        $schedule->command('link-checker:smart-scan')->hourly();
    });
}
