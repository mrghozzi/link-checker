<?php

declare(strict_types=1);

namespace MyAds\Plugins\LinkChecker\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use App\Models\Report;
use MyAds\Plugins\LinkChecker\LinkCheckerService;
use MyAds\Plugins\LinkChecker\GroqScannerService;

class SmartScanCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'link-checker:smart-scan {--limit=10}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Intelligently scan a chunk of links using Groq API and report bad ones.';

    /**
     * Execute the console command.
     */
    public function handle(LinkCheckerService $checkerService, GroqScannerService $groqScanner): int
    {
        $isEnabled = \App\Models\Option::where('name', 'lc_smart_scan_enabled')->value('o_valuer');
        if ($isEnabled !== null && $isEnabled == 0) {
            $this->info('Smart scan is disabled in settings.');
            return Command::SUCCESS;
        }

        // Try getting limit from option, if not present fallback to DB, else 10
        $cmdLimit = $this->option('limit');
        if ($cmdLimit && $cmdLimit !== '10') {
            $limit = (int) $cmdLimit;
        } else {
            $dbLimit = \App\Models\Option::where('name', 'lc_smart_scan_limit')->value('o_valuer');
            $limit = $dbLimit ? (int) $dbLimit : 10;
        }
        
        $sources = ['status', 'banner', 'link', 'smart_ad', 'directory', 'visit', 'store'];
        
        // Collect URLs from all sources
        $this->info('Collecting URLs...');
        $allUrls = $checkerService->collectUrls($sources);
        
        if (empty($allUrls)) {
            $this->info('No URLs found to scan.');
            return Command::SUCCESS;
        }

        // Get the last scanned index to chunk the scanning process
        $lastScannedIndex = Cache::get('link_checker_smart_scan_last_index', 0);
        
        if ($lastScannedIndex >= count($allUrls)) {
            // Reset if we reached the end
            $lastScannedIndex = 0;
            $this->info('Reached the end of URLs, resetting to start.');
        }

        // Slice a chunk of URLs based on limit
        $urlsToScan = array_slice($allUrls, $lastScannedIndex, $limit);
        
        $this->info(sprintf('Scanning %d URLs starting from index %d.', count($urlsToScan), $lastScannedIndex));

        foreach ($urlsToScan as $entry) {
            $url = $entry['url'];
            $sourceType = $entry['source_type'];
            $sourceId = $entry['source_id'];

            $this->line("Checking: {$url} [Source: {$sourceType} ID: {$sourceId}]");

            // Use Groq Scanner to analyze URL
            $result = $groqScanner->analyzeUrl($url);

            if ($result['is_bad']) {
                $this->error("Bad Link Detected: {$url} -> {$result['reason']}");
                
                // Determine s_type for reporting
                $sType = null;
                $tpId = $sourceId;

                if ($sourceType === 'status') {
                    $statusRecord = \App\Models\Status::find($sourceId);
                    if ($statusRecord) {
                        $sType = $statusRecord->s_type;
                        $tpId = $statusRecord->tp_id;
                    }
                } else {
                    $sType = $this->getReportSType($sourceType);
                }

                if ($sType !== null) {
                    // Create a report
                    // Avoid duplicating reports for the same item & URL
                    $existingReport = Report::where('s_type', $sType)
                        ->where('tp_id', $tpId)
                        ->where('statu', 1)
                        ->first();

                    if (!$existingReport) {
                        Report::create([
                            'uid'    => 1, // System admin
                            'txt'    => "نظام الفحص الذكي (Groq): اكتشف رابطاً معطوباً أو مخالفاً.\nالرابط: {$url}\nالسبب: {$result['reason']}",
                            's_type' => $sType,
                            'tp_id'  => $tpId,
                            'statu'  => 1, // 1 = New/Pending for admin notifications
                        ]);
                        $this->info("Created report for {$sourceType} ID {$sourceId} (tp_id: {$tpId}, s_type: {$sType}).");
                    } else {
                        $this->line("Report already exists for {$sourceType} ID {$sourceId}.");
                    }
                }
            } else {
                $this->info("Link is GOOD.");
            }
        }

        // Update the last scanned index in cache
        $nextIndex = $lastScannedIndex + count($urlsToScan);
        Cache::put('link_checker_smart_scan_last_index', $nextIndex);

        $this->info('Smart scan chunk completed successfully.');

        return Command::SUCCESS;
    }

    /**
     * Map source type to Report s_type.
     */
    private function getReportSType(string $sourceType): ?int
    {
        return match ($sourceType) {
            'link'      => 201, // Link ads
            'banner'    => 202, // Banner ads
            'smart_ad'  => 204, // Smart ads
            'directory' => 1,   // Directory
            'store'     => 7867, // Store products
            default     => null,
        };
    }
}
