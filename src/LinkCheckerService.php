<?php

declare(strict_types=1);

namespace MyAds\Plugins\LinkChecker;

use App\Models\Banner;
use App\Models\Directory;
use App\Models\Link;
use App\Models\Option;
use App\Models\SmartAd;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LinkCheckerService
{
    /**
     * All supported source definitions.
     *
     * Each entry maps a source key to:
     *  - label:      Human-readable Arabic label
     *  - table:      Database table name
     *  - url_column: Column that holds the URL
     *  - id_column:  Primary key column
     *  - name_column: Column for a human-readable label (nullable)
     *  - admin_route: Closure-friendly route pattern for admin links
     *  - icon:       Feather icon class
     *  - extra_where: Optional extra query constraints (callable)
     */
    private array $sourceDefinitions;

    public function __construct()
    {
        $this->sourceDefinitions = [
            'directory' => [
                'label'       => 'دليل المواقع',
                'table'       => 'directory',
                'url_column'  => 'url',
                'id_column'   => 'id',
                'name_column' => 'name',
                'icon'        => 'feather-globe',
            ],
            'banner' => [
                'label'       => 'إعلانات البانر',
                'table'       => 'banner',
                'url_column'  => 'url',
                'id_column'   => 'id',
                'name_column' => 'name',
                'icon'        => 'feather-image',
            ],
            'link' => [
                'label'       => 'إعلانات الروابط',
                'table'       => 'link',
                'url_column'  => 'url',
                'id_column'   => 'id',
                'name_column' => 'name',
                'icon'        => 'feather-external-link',
            ],
            'smart_ad' => [
                'label'       => 'الإعلانات الذكية',
                'table'       => 'smart_ads',
                'url_column'  => 'landing_url',
                'id_column'   => 'id',
                'name_column' => 'headline_override',
                'icon'        => 'feather-zap',
            ],
            'visit' => [
                'label'       => 'تبادل الزيارات',
                'table'       => 'visits',
                'url_column'  => 'url',
                'id_column'   => 'id',
                'name_column' => 'name',
                'icon'        => 'feather-repeat',
            ],
            'store' => [
                'label'       => 'المتجر',
                'table'       => 'options',
                'url_column'  => 'o_valuer',
                'id_column'   => 'id',
                'name_column' => 'name',
                'icon'        => 'feather-shopping-bag',
                'extra_where' => ['o_type', 'store'],
            ],
        ];
    }

    /**
     * Return the catalog of all sources with their availability and URL counts.
     */
    public function catalog(): array
    {
        $catalog = [];

        foreach ($this->sourceDefinitions as $key => $def) {
            $available = Schema::hasTable($def['table']);
            $count = 0;

            if ($available) {
                $query = DB::table($def['table'])
                    ->whereNotNull($def['url_column'])
                    ->where($def['url_column'], '!=', '');

                if (!empty($def['extra_where'])) {
                    $query->where($def['extra_where'][0], $def['extra_where'][1]);
                }

                $count = $query->count();
            }

            $catalog[$key] = [
                'key'       => $key,
                'label'     => $def['label'],
                'icon'      => $def['icon'],
                'available' => $available,
                'url_count' => $count,
            ];
        }

        return $catalog;
    }

    /**
     * Collect all URLs from the specified sources.
     *
     * @param  string[]  $sources  Array of source keys (e.g. ['directory', 'banner'])
     * @return array<int, array{url: string, source_type: string, source_id: int, source_label: string, owner_id: int|null}>
     */
    public function collectUrls(array $sources): array
    {
        $urls = [];

        foreach ($sources as $sourceKey) {
            if (!isset($this->sourceDefinitions[$sourceKey])) {
                continue;
            }

            $def = $this->sourceDefinitions[$sourceKey];

            if (!Schema::hasTable($def['table'])) {
                continue;
            }

            $query = DB::table($def['table'])
                ->select([
                    $def['id_column'] . ' as id',
                    $def['url_column'] . ' as url',
                    $def['name_column'] ? $def['name_column'] . ' as label' : DB::raw("'' as label"),
                ])
                ->whereNotNull($def['url_column'])
                ->where($def['url_column'], '!=', '');

            // Add owner_id column — most tables use 'uid', store uses 'o_parent'
            $ownerColumn = $sourceKey === 'store' ? 'o_parent' : 'uid';
            if (Schema::hasColumn($def['table'], $ownerColumn)) {
                $query->addSelect($ownerColumn . ' as owner_id');
            } else {
                $query->addSelect(DB::raw('NULL as owner_id'));
            }

            if (!empty($def['extra_where'])) {
                $query->where($def['extra_where'][0], $def['extra_where'][1]);
            }

            $rows = $query->get();

            foreach ($rows as $row) {
                $rawUrl = trim((string) $row->url);

                // For store products, the o_valuer field contains description text,
                // so we extract URLs from it instead of using it directly.
                if ($sourceKey === 'store') {
                    $extractedUrls = $this->extractUrlsFromText($rawUrl);
                    foreach ($extractedUrls as $extractedUrl) {
                        $urls[] = [
                            'url'          => $extractedUrl,
                            'source_type'  => $sourceKey,
                            'source_id'    => (int) $row->id,
                            'source_label' => trim((string) ($row->label ?: "#{$row->id}")),
                            'owner_id'     => $row->owner_id ? (int) $row->owner_id : null,
                        ];
                    }
                    continue;
                }

                // Skip non-URL values
                if (!$this->isValidUrl($rawUrl)) {
                    continue;
                }

                $urls[] = [
                    'url'          => $rawUrl,
                    'source_type'  => $sourceKey,
                    'source_id'    => (int) $row->id,
                    'source_label' => trim((string) ($row->label ?: "#{$row->id}")),
                    'owner_id'     => $row->owner_id ? (int) $row->owner_id : null,
                ];
            }
        }

        // Deduplicate by URL+source_type+source_id
        $seen = [];
        $unique = [];
        foreach ($urls as $entry) {
            $fingerprint = $entry['url'] . '|' . $entry['source_type'] . '|' . $entry['source_id'];
            if (!isset($seen[$fingerprint])) {
                $seen[$fingerprint] = true;
                $unique[] = $entry;
            }
        }

        return $unique;
    }

    /**
     * Check a single URL and return its status.
     *
     * @return array{status_code: int, is_broken: bool, is_redirect: bool, error: ?string, response_time_ms: int, final_url: ?string}
     */
    public function checkUrl(string $url): array
    {
        $startTime = microtime(true);

        $result = [
            'status_code'      => 0,
            'is_broken'        => true,
            'is_redirect'      => false,
            'error'            => null,
            'response_time_ms' => 0,
            'final_url'        => null,
        ];

        if (!$this->isValidUrl($url)) {
            $result['error'] = 'عنوان URL غير صالح';
            $result['response_time_ms'] = (int) round((microtime(true) - $startTime) * 1000);
            return $result;
        }

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_NOBODY         => true,               // HEAD request first
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT      => 'MYADS-LinkChecker/1.0 (+https://myads.dev)',
            CURLOPT_HTTPHEADER     => [
                'Accept: */*',
                'Accept-Language: en-US,en;q=0.9,ar;q=0.8',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);

        // Some servers block HEAD requests — retry with GET if we get 405 or 0
        if ($httpCode === 0 || $httpCode === 405 || $httpCode === 403) {
            curl_setopt($ch, CURLOPT_NOBODY, false);
            curl_setopt($ch, CURLOPT_HTTPGET, true);
            // Limit download to 100KB to avoid large downloads
            curl_setopt($ch, CURLOPT_RANGE, '0-102400');

            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            $curlError = curl_error($ch);
            $curlErrno = curl_errno($ch);
        }

        curl_close($ch);

        $elapsed = (int) round((microtime(true) - $startTime) * 1000);

        $result['status_code'] = $httpCode;
        $result['response_time_ms'] = $elapsed;
        $result['final_url'] = ($finalUrl && $finalUrl !== $url) ? $finalUrl : null;

        if ($curlErrno !== 0) {
            $result['is_broken'] = true;
            $result['error'] = $this->translateCurlError($curlErrno, $curlError);
        } elseif ($httpCode >= 400) {
            $result['is_broken'] = true;
            $result['error'] = $this->describeHttpStatus($httpCode);
        } else {
            $result['is_broken'] = false;
        }

        // Detect redirects
        if (in_array($httpCode, [301, 302, 303, 307, 308], true) || ($result['final_url'] && !$result['is_broken'])) {
            $result['is_redirect'] = true;
        }

        return $result;
    }

    /**
     * Check a batch of URL entries.
     *
     * @param  array  $entries  Array of URL entries from collectUrls()
     * @return array  Same entries with 'check' key added
     */
    public function checkBatch(array $entries): array
    {
        $results = [];

        foreach ($entries as $entry) {
            $check = $this->checkUrl($entry['url']);
            $entry['check'] = $check;
            $results[] = $entry;
        }

        return $results;
    }

    /**
     * Get the admin URL for a given source entry.
     */
    public function adminUrl(string $sourceType, int $sourceId): ?string
    {
        return match ($sourceType) {
            'directory' => url("/admin/directory/{$sourceId}"),
            'banner'    => url("/admin/banners/{$sourceId}"),
            'link'      => url("/admin/links/{$sourceId}"),
            'smart_ad'  => url("/admin/smart-ads/{$sourceId}"),
            'visit'     => url("/admin/visits/{$sourceId}"),
            'store'     => url("/admin/store/{$sourceId}"),
            default     => null,
        };
    }

    /**
     * Extract URLs from a text string.
     */
    private function extractUrlsFromText(string $text): array
    {
        $urls = [];
        if (preg_match_all('#https?://[^\s<>"\'\)]+#i', $text, $matches)) {
            foreach ($matches[0] as $url) {
                $url = rtrim($url, '.,;:!?)');
                if ($this->isValidUrl($url)) {
                    $urls[] = $url;
                }
            }
        }
        return array_unique($urls);
    }

    /**
     * Check if a string is a valid URL.
     */
    private function isValidUrl(string $url): bool
    {
        if (empty($url)) {
            return false;
        }

        // Must start with http:// or https://
        if (!preg_match('#^https?://#i', $url)) {
            return false;
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Translate cURL error codes to Arabic descriptions.
     */
    private function translateCurlError(int $errno, string $error): string
    {
        return match ($errno) {
            28, // CURLE_OPERATION_TIMEDOUT
            CURLE_OPERATION_TIMEDOUT   => 'انتهت مهلة الاتصال (Timeout)',
            CURLE_COULDNT_RESOLVE_HOST => 'تعذر العثور على خادم النطاق (DNS)',
            CURLE_COULDNT_CONNECT      => 'تعذر الاتصال بالخادم',
            CURLE_SSL_CONNECT_ERROR,
            CURLE_SSL_CERTPROBLEM,
            CURLE_SSL_CIPHER           => 'خطأ في شهادة SSL',
            CURLE_TOO_MANY_REDIRECTS   => 'عدد كبير من عمليات إعادة التوجيه',
            default                    => "خطأ cURL: {$error} ({$errno})",
        };
    }

    /**
     * Describe an HTTP status code in Arabic.
     */
    private function describeHttpStatus(int $code): string
    {
        return match ($code) {
            400 => 'طلب غير صالح (400)',
            401 => 'غير مصرح (401)',
            403 => 'محظور الوصول (403)',
            404 => 'الصفحة غير موجودة (404)',
            405 => 'الطريقة غير مسموحة (405)',
            408 => 'انتهت مهلة الطلب (408)',
            410 => 'الصفحة محذوفة نهائياً (410)',
            429 => 'طلبات كثيرة جداً (429)',
            500 => 'خطأ داخلي في الخادم (500)',
            502 => 'بوابة خاطئة (502)',
            503 => 'الخدمة غير متاحة (503)',
            504 => 'انتهت مهلة البوابة (504)',
            default => "خطأ HTTP ({$code})",
        };
    }
}
