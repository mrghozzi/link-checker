@extends('admin::layouts.admin')

@section('title', 'فاحص الروابط المعطوبة')
@section('admin_shell_header_mode', 'hidden')

@php
    $catalog = $catalog ?? [];
    $settings = $settings ?? ['groq_api_key' => '', 'smart_scan_limit' => 10, 'smart_scan_enabled' => 1];
    $totalUrls = collect($catalog)->where('available', true)->sum('url_count');
    $lastResults = session('link_checker_results', []);
    $lastSummary = session('link_checker_summary', []);
    $hasResults = !empty($lastResults);
    $brokenCount = $lastSummary['broken'] ?? 0;
    $healthyCount = $lastSummary['healthy'] ?? 0;
    $redirectCount = $lastSummary['redirect'] ?? 0;
    $slowCount = $lastSummary['slow'] ?? 0;
@endphp

@section('content')
<div class="admin-page link-checker-page">
    {{-- ══════════════════════════════════════════════════════════════════
         HERO SECTION
    ══════════════════════════════════════════════════════════════════ --}}
    <section class="admin-hero">
        <div class="admin-hero__content">
            <ul class="admin-breadcrumb">
                <li><a href="{{ route('admin.index') }}">لوحة الإدارة</a></li>
                <li>الإضافات</li>
                <li>فاحص الروابط المعطوبة</li>
            </ul>
            <div class="admin-hero__eyebrow">Broken Link Checker</div>
            <h1 class="admin-hero__title">فاحص الروابط المعطوبة</h1>
            <p class="admin-hero__copy">
                افحص جميع الروابط الخارجية في دليل المواقع، المتجر، والإعلانات بمختلف أنواعها
                للكشف عن الروابط المعطوبة والبطيئة وإعادات التوجيه.
            </p>
        </div>
        <div class="admin-hero__actions">
            <div class="admin-summary-grid w-100" id="lc-stats-grid">
                <div class="admin-summary-card">
                    <span class="admin-summary-label">إجمالي الروابط</span>
                    <span class="admin-summary-value" id="lc-stat-total">{{ $totalUrls }}</span>
                    <span class="admin-summary-meta">في جميع النطاقات</span>
                </div>
                <div class="admin-summary-card lc-stat-healthy">
                    <span class="admin-summary-label">سليمة</span>
                    <span class="admin-summary-value" id="lc-stat-healthy">{{ $healthyCount }}</span>
                    <span class="admin-summary-meta">HTTP 2xx/3xx</span>
                </div>
                <div class="admin-summary-card lc-stat-broken">
                    <span class="admin-summary-label">معطوبة</span>
                    <span class="admin-summary-value" id="lc-stat-broken">{{ $brokenCount }}</span>
                    <span class="admin-summary-meta">خطأ أو timeout</span>
                </div>
                <div class="admin-summary-card lc-stat-slow">
                    <span class="admin-summary-label">بطيئة</span>
                    <span class="admin-summary-value" id="lc-stat-slow">{{ $slowCount }}</span>
                    <span class="admin-summary-meta">&gt; 3 ثوانٍ</span>
                </div>
            </div>
        </div>
    </section>

    @if(session('success'))
        <div class="alert alert-success shadow-sm mb-4">{{ session('success') }}</div>
    @endif

    @if(session('error'))
        <div class="alert alert-danger shadow-sm mb-4">{{ session('error') }}</div>
    @endif

    {{-- ══════════════════════════════════════════════════════════════════
         SCAN CONFIGURATION PANEL
    ══════════════════════════════════════════════════════════════════ --}}
    <div class="admin-workspace-grid">
        <section class="admin-panel">
            <div class="admin-panel__header">
                <div>
                    <span class="admin-panel__eyebrow">إعدادات الفحص</span>
                    <h2 class="admin-panel__title">اختر النطاقات المراد فحصها</h2>
                    <p class="admin-panel__copy mb-0">حدد مصادر الروابط التي تريد فحصها ثم اضغط على بدء الفحص.</p>
                </div>
            </div>
            <div class="admin-panel__body">
                <div class="lc-source-grid">
                    @foreach($catalog as $key => $source)
                        <label class="lc-source-card {{ $source['available'] ? '' : 'is-disabled' }}" for="lc-source-{{ $key }}">
                            <input type="checkbox"
                                   class="form-check-input lc-source-checkbox"
                                   id="lc-source-{{ $key }}"
                                   value="{{ $key }}"
                                   @checked($source['available'])
                                   @disabled(!$source['available'])>
                            <span class="lc-source-info">
                                <span class="lc-source-icon"><i class="{{ $source['icon'] }}"></i></span>
                                <span class="lc-source-details">
                                    <strong>{{ $source['label'] }}</strong>
                                    <small>
                                        @if($source['available'])
                                            {{ $source['url_count'] }} {{ $source['url_count'] === 1 ? 'رابط' : 'رابط' }}
                                        @else
                                            غير متاح
                                        @endif
                                    </small>
                                </span>
                            </span>
                        </label>
                    @endforeach
                </div>

                <div class="d-flex flex-wrap gap-3 mt-4 align-items-center">
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="lc-select-all">تحديد الكل</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="lc-clear-all">إلغاء التحديد</button>
                    <div class="flex-fill"></div>
                    <button type="button" class="btn btn-primary" id="lc-start-scan" disabled>
                        <i class="feather-search me-1"></i>بدء الفحص
                    </button>
                </div>
            </div>
        </section>

        {{-- SETTINGS PANEL --}}
        <section class="admin-panel mt-4">
            <div class="admin-panel__header">
                <div>
                    <span class="admin-panel__eyebrow">إعدادات متقدمة</span>
                    <h2 class="admin-panel__title">الفحص الذكي (Groq API)</h2>
                    <p class="admin-panel__copy mb-0">تحكم في فحص الروابط التلقائي المعتمد على الذكاء الاصطناعي.</p>
                </div>
            </div>
            <div class="admin-panel__body">
                <form id="lc-settings-form">
                    <div class="mb-3">
                        <label class="form-label fw-bold">تفعيل الفحص الذكي</label>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="setting_smart_scan_enabled" {{ $settings['smart_scan_enabled'] ? 'checked' : '' }}>
                            <label class="form-check-label" for="setting_smart_scan_enabled">تشغيل الفحص التلقائي في الخلفية</label>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Groq API Key</label>
                        <div class="input-group">
                            <input type="text" class="form-control" id="setting_groq_api_key" value="{{ $settings['groq_api_key'] }}" placeholder="gsk_...">
                            <button type="button" class="btn btn-outline-info" id="lc-test-groq">
                                <i class="feather-activity me-1"></i>اختبار الاتصال
                            </button>
                        </div>
                        <small class="text-muted d-block mt-1">مفتاح API الخاص بـ Groq لتحليل الروابط بذكاء.</small>
                        <div id="lc-groq-test-result" class="mt-2 text-sm fw-bold" style="display:none;"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">عدد الروابط في كل دفعة (الحد الأقصى)</label>
                        <input type="number" class="form-control" id="setting_smart_scan_limit" value="{{ $settings['smart_scan_limit'] }}" min="1" max="100">
                        <small class="text-muted">الحد الأقصى للروابط التي يتم فحصها كل ساعة (للحفاظ على أداء الخادم).</small>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <button type="submit" class="btn btn-success btn-sm" id="lc-save-settings">
                            <i class="feather-save me-1"></i>حفظ الإعدادات
                        </button>
                        <button type="button" class="btn btn-warning btn-sm" id="lc-force-scan">
                            <i class="feather-play me-1"></i>نفذ الآن (تخطي فترة الراحة)
                        </button>
                        <span id="lc-settings-msg" class="ms-2 text-success" style="display:none;">تم الحفظ بنجاح!</span>
                    </div>
                </form>
            </div>
        </section>
        </div>

        {{-- PROGRESS SIDEBAR --}}
        <aside class="admin-panel" id="lc-progress-panel">
            <div class="admin-panel__header">
                <div>
                    <span class="admin-panel__eyebrow">حالة الفحص</span>
                    <h2 class="admin-panel__title">التقدم</h2>
                </div>
            </div>
            <div class="admin-panel__body">
                <div id="lc-idle-state" class="lc-empty-state" @if($hasResults) style="display:none" @endif>
                    <i class="feather-link"></i>
                    <h3>جاهز للفحص</h3>
                    <p>حدد النطاقات ثم اضغط «بدء الفحص» لبدء اكتشاف الروابط المعطوبة.</p>
                </div>

                <div id="lc-scanning-state" style="display:none">
                    <div class="lc-progress-wrapper">
                        <div class="lc-progress-header">
                            <span id="lc-progress-label">جاري الفحص...</span>
                            <span id="lc-progress-pct">0%</span>
                        </div>
                        <div class="progress lc-progress-bar">
                            <div class="progress-bar progress-bar-striped progress-bar-animated" id="lc-progress-fill" role="progressbar" style="width:0%"></div>
                        </div>
                        <div class="lc-progress-detail mt-2">
                            <small class="text-muted" id="lc-progress-detail">فحص 0 من 0 رابط</small>
                        </div>
                    </div>

                    <div class="lc-live-counters mt-4">
                        <div class="lc-live-counter lc-counter-healthy">
                            <i class="feather-check-circle"></i>
                            <span id="lc-live-healthy">0</span>
                            <small>سليم</small>
                        </div>
                        <div class="lc-live-counter lc-counter-broken">
                            <i class="feather-x-circle"></i>
                            <span id="lc-live-broken">0</span>
                            <small>معطوب</small>
                        </div>
                        <div class="lc-live-counter lc-counter-redirect">
                            <i class="feather-corner-up-right"></i>
                            <span id="lc-live-redirect">0</span>
                            <small>إعادة توجيه</small>
                        </div>
                    </div>
                </div>

                <div id="lc-done-state" style="display:none" @if($hasResults) style="" @endif>
                    <div class="lc-done-icon">
                        <i class="feather-check-circle"></i>
                    </div>
                    <h3>اكتمل الفحص</h3>
                    <p id="lc-done-summary">تم فحص <strong id="lc-done-total">{{ count($lastResults) }}</strong> رابط.</p>
                    <button type="button" class="btn btn-outline-danger btn-sm mt-2" id="lc-clear-results">
                        <i class="feather-trash-2 me-1"></i>مسح النتائج
                    </button>
                </div>
            </div>
        </aside>
    </div>

    {{-- ══════════════════════════════════════════════════════════════════
         RESULTS TABLE
    ══════════════════════════════════════════════════════════════════ --}}
    <section class="admin-panel" id="lc-results-panel" @if(!$hasResults) style="display:none" @endif>
        <div class="admin-panel__header">
            <div>
                <span class="admin-panel__eyebrow">نتائج الفحص</span>
                <h2 class="admin-panel__title">تفاصيل الروابط</h2>
            </div>
        </div>
        <div class="admin-panel__body">
            {{-- FILTERS --}}
            <div class="lc-filters mb-4">
                <div class="lc-filter-group">
                    <label class="lc-filter-label">الحالة:</label>
                    <div class="btn-group btn-group-sm" role="group">
                        <button type="button" class="btn btn-outline-secondary active lc-filter-status" data-status="all">الكل</button>
                        <button type="button" class="btn btn-outline-success lc-filter-status" data-status="healthy">سليم</button>
                        <button type="button" class="btn btn-outline-warning lc-filter-status" data-status="redirect">إعادة توجيه</button>
                        <button type="button" class="btn btn-outline-danger lc-filter-status" data-status="broken">معطوب</button>
                    </div>
                </div>
                <div class="lc-filter-group">
                    <label class="lc-filter-label">المصدر:</label>
                    <select class="form-select form-select-sm lc-filter-source" id="lc-filter-source">
                        <option value="all">جميع المصادر</option>
                        @foreach($catalog as $key => $source)
                            @if($source['available'])
                                <option value="{{ $key }}">{{ $source['label'] }}</option>
                            @endif
                        @endforeach
                    </select>
                </div>
                <div class="lc-filter-group">
                    <label class="lc-filter-label">بحث:</label>
                    <input type="text" class="form-control form-control-sm" id="lc-filter-search" placeholder="ابحث في الروابط...">
                </div>
            </div>

            {{-- TABLE --}}
            <div class="table-responsive">
                <table class="table lc-results-table" id="lc-results-table">
                    <thead>
                        <tr>
                            <th style="width:60px">الحالة</th>
                            <th>الرابط</th>
                            <th>المصدر</th>
                            <th style="width:90px">كود HTTP</th>
                            <th style="width:100px">الاستجابة</th>
                            <th>الخطأ</th>
                        </tr>
                    </thead>
                    <tbody id="lc-results-body">
                        {{-- Populated by JS --}}
                    </tbody>
                </table>
            </div>

            <div class="lc-empty-state compact" id="lc-no-results" style="display:none">
                <i class="feather-filter"></i>
                <h3>لا توجد نتائج مطابقة</h3>
                <p>جرّب تغيير معايير التصفية.</p>
            </div>
        </div>
    </section>
</div>

{{-- ══════════════════════════════════════════════════════════════════════
     STYLES
══════════════════════════════════════════════════════════════════════ --}}
<style>
/* ── Layout ───────────────────────────────────────────────────────── */
.link-checker-page { gap: 1.5rem; }

/* ── Source Cards ─────────────────────────────────────────────────── */
.lc-source-grid {
    display: grid;
    gap: 1rem;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
}
.lc-source-card {
    align-items: center;
    background: var(--admin-premium-surface);
    border: 1px solid var(--admin-premium-border);
    border-radius: 18px;
    cursor: pointer;
    display: flex;
    gap: .85rem;
    padding: 1rem 1.15rem;
    transition: all .2s ease;
}
.lc-source-card:hover {
    border-color: var(--admin-premium-border-strong);
    box-shadow: var(--admin-premium-shadow-soft);
}
.lc-source-card.is-disabled {
    cursor: not-allowed;
    opacity: .45;
}
.lc-source-info {
    align-items: center;
    display: flex;
    gap: .75rem;
    flex: 1;
}
.lc-source-icon {
    align-items: center;
    background: linear-gradient(135deg, rgba(99, 102, 241, .15), rgba(139, 92, 246, .15));
    border-radius: 14px;
    color: #818cf8;
    display: flex;
    font-size: 1.15rem;
    height: 42px;
    justify-content: center;
    width: 42px;
}
.lc-source-details {
    display: flex;
    flex-direction: column;
    gap: .15rem;
}
.lc-source-details strong {
    color: var(--admin-premium-text);
    font-size: .92rem;
}
.lc-source-details small {
    color: var(--admin-premium-muted);
    font-size: .8rem;
}

/* ── Progress ─────────────────────────────────────────────────────── */
.lc-progress-wrapper {
    background: var(--admin-premium-surface-alt, var(--admin-premium-surface));
    border-radius: 18px;
    padding: 1.25rem;
}
.lc-progress-header {
    display: flex;
    justify-content: space-between;
    margin-bottom: .65rem;
}
.lc-progress-header span {
    color: var(--admin-premium-text);
    font-size: .9rem;
    font-weight: 600;
}
.lc-progress-bar {
    border-radius: 12px;
    height: 12px;
    overflow: hidden;
}
.lc-progress-bar .progress-bar {
    background: linear-gradient(90deg, #6366f1, #8b5cf6, #a78bfa);
    transition: width .3s ease;
}

/* ── Live Counters ────────────────────────────────────────────────── */
.lc-live-counters {
    display: grid;
    gap: .75rem;
    grid-template-columns: repeat(3, 1fr);
}
.lc-live-counter {
    align-items: center;
    background: var(--admin-premium-surface);
    border: 1px solid var(--admin-premium-border);
    border-radius: 16px;
    display: flex;
    flex-direction: column;
    gap: .3rem;
    padding: .85rem .5rem;
    text-align: center;
}
.lc-live-counter i { font-size: 1.3rem; }
.lc-live-counter span { font-size: 1.4rem; font-weight: 700; color: var(--admin-premium-text); }
.lc-live-counter small { color: var(--admin-premium-muted); font-size: .78rem; }
.lc-counter-healthy i { color: #22c55e; }
.lc-counter-broken i { color: #ef4444; }
.lc-counter-redirect i { color: #f59e0b; }

/* ── Stats Cards ──────────────────────────────────────────────────── */
.lc-stat-healthy .admin-summary-value { color: #22c55e; }
.lc-stat-broken .admin-summary-value { color: #ef4444; }
.lc-stat-slow .admin-summary-value { color: #f59e0b; }

/* ── Done State ───────────────────────────────────────────────────── */
.lc-done-icon {
    align-items: center;
    background: linear-gradient(135deg, rgba(34, 197, 94, .1), rgba(34, 197, 94, .05));
    border-radius: 50%;
    color: #22c55e;
    display: flex;
    font-size: 2.5rem;
    height: 80px;
    justify-content: center;
    margin: 0 auto 1rem;
    width: 80px;
}
#lc-done-state { text-align: center; }
#lc-done-state h3 { color: var(--admin-premium-text); margin-bottom: .4rem; }
#lc-done-state p { color: var(--admin-premium-muted); }

/* ── Empty State ──────────────────────────────────────────────────── */
.lc-empty-state {
    align-items: center;
    background: var(--admin-premium-surface);
    border: 1px dashed var(--admin-premium-border-strong);
    border-radius: 24px;
    display: flex;
    flex-direction: column;
    gap: .75rem;
    justify-content: center;
    min-height: 200px;
    padding: 2rem;
    text-align: center;
}
.lc-empty-state.compact { min-height: 140px; }
.lc-empty-state i { color: var(--admin-premium-muted); font-size: 2.5rem; }
.lc-empty-state h3 { color: var(--admin-premium-text); font-size: 1.05rem; margin: 0; }
.lc-empty-state p { color: var(--admin-premium-muted); font-size: .88rem; margin: 0; }

/* ── Filters ──────────────────────────────────────────────────────── */
.lc-filters {
    align-items: flex-end;
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
}
.lc-filter-group { display: flex; flex-direction: column; gap: .35rem; }
.lc-filter-label { color: var(--admin-premium-muted); font-size: .8rem; font-weight: 600; }
.lc-filter-source { min-width: 160px; }
#lc-filter-search { min-width: 200px; }

/* ── Results Table ────────────────────────────────────────────────── */
.lc-results-table { font-size: .88rem; }
.lc-results-table thead th {
    background: var(--admin-premium-surface-alt, var(--admin-premium-surface));
    border-bottom: 2px solid var(--admin-premium-border);
    color: var(--admin-premium-muted);
    font-size: .78rem;
    font-weight: 700;
    letter-spacing: .03em;
    padding: .75rem .6rem;
    text-transform: uppercase;
    white-space: nowrap;
}
.lc-results-table tbody td {
    border-bottom: 1px solid var(--admin-premium-border);
    color: var(--admin-premium-text);
    padding: .65rem .6rem;
    vertical-align: middle;
}
.lc-results-table tbody tr:hover {
    background: var(--admin-premium-surface-alt, rgba(99, 102, 241, .03));
}

/* Status badges */
.lc-badge {
    border-radius: 10px;
    display: inline-flex;
    align-items: center;
    font-size: .75rem;
    font-weight: 700;
    gap: .3rem;
    padding: .3rem .65rem;
    white-space: nowrap;
}
.lc-badge-healthy { background: rgba(34, 197, 94, .12); color: #16a34a; }
.lc-badge-broken  { background: rgba(239, 68, 68, .12); color: #dc2626; }
.lc-badge-redirect { background: rgba(245, 158, 11, .12); color: #d97706; }

/* URL cell */
.lc-url-cell {
    max-width: 320px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.lc-url-cell a {
    color: #818cf8;
    text-decoration: none;
}
.lc-url-cell a:hover {
    text-decoration: underline;
}

/* Source cell */
.lc-source-badge {
    align-items: center;
    background: var(--admin-premium-surface);
    border: 1px solid var(--admin-premium-border);
    border-radius: 10px;
    display: inline-flex;
    font-size: .78rem;
    gap: .35rem;
    padding: .25rem .6rem;
    white-space: nowrap;
}

/* HTTP code */
.lc-http-code {
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: .82rem;
    font-weight: 700;
}
.lc-http-ok { color: #22c55e; }
.lc-http-redirect { color: #f59e0b; }
.lc-http-error { color: #ef4444; }
.lc-http-zero { color: var(--admin-premium-muted); }

/* Response time */
.lc-response-time { font-size: .82rem; }
.lc-response-slow { color: #f59e0b; font-weight: 600; }
.lc-response-fast { color: var(--admin-premium-muted); }

/* Error cell */
.lc-error-cell {
    color: #ef4444;
    font-size: .82rem;
    max-width: 220px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* ── Responsive ───────────────────────────────────────────────────── */
@media (max-width: 767.98px) {
    .lc-source-grid { grid-template-columns: 1fr; }
    .lc-live-counters { grid-template-columns: repeat(3, 1fr); }
    .lc-filters { flex-direction: column; align-items: stretch; }
    .lc-filter-source, #lc-filter-search { min-width: unset; width: 100%; }

    .lc-results-table thead { display: none; }
    .lc-results-table tbody,
    .lc-results-table tr,
    .lc-results-table td { display: block; width: 100%; }
    .lc-results-table td { border: 0; padding: .4rem 0; }
    .lc-results-table td::before {
        content: attr(data-label);
        display: block;
        font-size: .75rem;
        font-weight: 700;
        margin-bottom: .25rem;
        color: var(--admin-premium-muted);
    }
    .lc-results-table tbody tr {
        background: var(--admin-premium-surface);
        border: 1px solid var(--admin-premium-border);
        border-radius: 16px;
        margin-bottom: .75rem;
        padding: .85rem;
    }
    .lc-url-cell { max-width: unset; white-space: normal; word-break: break-all; }
    .lc-error-cell { max-width: unset; white-space: normal; }
}
</style>

{{-- ══════════════════════════════════════════════════════════════════════
     JAVASCRIPT
══════════════════════════════════════════════════════════════════════ --}}
<script>
document.addEventListener('DOMContentLoaded', function () {
    // ── DOM References ───────────────────────────────────────────────
    const checkboxes     = [...document.querySelectorAll('.lc-source-checkbox')];
    const startBtn       = document.getElementById('lc-start-scan');
    const selectAllBtn   = document.getElementById('lc-select-all');
    const clearAllBtn    = document.getElementById('lc-clear-all');
    const clearResultBtn = document.getElementById('lc-clear-results');

    const idleState      = document.getElementById('lc-idle-state');
    const scanningState  = document.getElementById('lc-scanning-state');
    const doneState      = document.getElementById('lc-done-state');

    const progressFill   = document.getElementById('lc-progress-fill');
    const progressPct    = document.getElementById('lc-progress-pct');
    const progressLabel  = document.getElementById('lc-progress-label');
    const progressDetail = document.getElementById('lc-progress-detail');

    const liveHealthy    = document.getElementById('lc-live-healthy');
    const liveBroken     = document.getElementById('lc-live-broken');
    const liveRedirect   = document.getElementById('lc-live-redirect');

    const statTotal      = document.getElementById('lc-stat-total');
    const statHealthy    = document.getElementById('lc-stat-healthy');
    const statBroken     = document.getElementById('lc-stat-broken');
    const statSlow       = document.getElementById('lc-stat-slow');

    const resultsPanel   = document.getElementById('lc-results-panel');
    const resultsBody    = document.getElementById('lc-results-body');
    const noResults      = document.getElementById('lc-no-results');
    const doneTotal      = document.getElementById('lc-done-total');

    const filterBtns     = [...document.querySelectorAll('.lc-filter-status')];
    const filterSource   = document.getElementById('lc-filter-source');
    const filterSearch   = document.getElementById('lc-filter-search');

    const csrfToken      = document.querySelector('meta[name="csrf-token"]')?.content || '';

    let allResults       = @json($lastResults);
    let isScanning       = false;

    // Labels map
    const sourceLabels = @json(collect($catalog)->mapWithKeys(fn($s, $k) => [$k => $s['label']])->all());
    const sourceIcons  = @json(collect($catalog)->mapWithKeys(fn($s, $k) => [$k => $s['icon']])->all());

    // ── Init ─────────────────────────────────────────────────────────
    updateStartButton();

    if (allResults.length > 0) {
        renderResults();
        showDoneState();
    }

    // ── Checkbox Logic ───────────────────────────────────────────────
    checkboxes.forEach(cb => cb.addEventListener('change', updateStartButton));

    selectAllBtn.addEventListener('click', () => {
        checkboxes.forEach(cb => { if (!cb.disabled) cb.checked = true; });
        updateStartButton();
    });

    clearAllBtn.addEventListener('click', () => {
        checkboxes.forEach(cb => { if (!cb.disabled) cb.checked = false; });
        updateStartButton();
    });

    // ── Settings Form Logic ──────────────────────────────────────────
    const settingsForm = document.getElementById('lc-settings-form');
    const settingsMsg = document.getElementById('lc-settings-msg');
    const testGroqBtn = document.getElementById('lc-test-groq');
    const groqTestResult = document.getElementById('lc-groq-test-result');

    testGroqBtn.addEventListener('click', async function() {
        const apiKey = document.getElementById('setting_groq_api_key').value;
        if (!apiKey) {
            groqTestResult.className = 'mt-2 text-sm fw-bold text-danger';
            groqTestResult.textContent = 'الرجاء إدخال مفتاح API أولاً';
            groqTestResult.style.display = 'block';
            return;
        }

        testGroqBtn.disabled = true;
        testGroqBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>جاري الاختبار...';
        groqTestResult.style.display = 'none';

        try {
            const res = await fetch('{{ route("admin.link-checker.test-groq") }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ groq_api_key: apiKey }),
            });

            const data = await res.json();
            
            groqTestResult.textContent = data.message || (data.success ? 'نجاح' : 'خطأ');
            groqTestResult.className = 'mt-2 text-sm fw-bold ' + (data.success ? 'text-success' : 'text-danger');
            groqTestResult.style.display = 'block';
        } catch (err) {
            groqTestResult.textContent = 'حدث خطأ في الاتصال بالسيرفر المحلي';
            groqTestResult.className = 'mt-2 text-sm fw-bold text-danger';
            groqTestResult.style.display = 'block';
        }

        testGroqBtn.disabled = false;
        testGroqBtn.innerHTML = '<i class="feather-activity me-1"></i>اختبار الاتصال';
    });
    
    settingsForm.addEventListener('submit', async function(e) {
        e.preventDefault();
        const saveBtn = document.getElementById('lc-save-settings');
        saveBtn.disabled = true;
        
        try {
            const res = await fetch('{{ route("admin.link-checker.settings") }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    groq_api_key: document.getElementById('setting_groq_api_key').value,
                    smart_scan_limit: document.getElementById('setting_smart_scan_limit').value,
                    smart_scan_enabled: document.getElementById('setting_smart_scan_enabled').checked
                }),
            });
            
            if (res.ok) {
                settingsMsg.style.display = 'inline';
                setTimeout(() => settingsMsg.style.display = 'none', 3000);
            } else {
                alert('حدث خطأ أثناء حفظ الإعدادات');
            }
        } catch (err) {
            alert('حدث خطأ في الاتصال');
        }
        
        saveBtn.disabled = false;
    });

    // ── Force Scan Logic ─────────────────────────────────────────────
    const forceScanBtn = document.getElementById('lc-force-scan');
    
    forceScanBtn.addEventListener('click', async function() {
        if (!confirm('هل أنت متأكد من رغبتك بتشغيل الفحص الذكي الآن؟ قد يستغرق ذلك بضع ثوانٍ.')) return;
        
        forceScanBtn.disabled = true;
        const originalText = forceScanBtn.innerHTML;
        forceScanBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>جاري التنفيذ...';
        
        try {
            const res = await fetch('{{ route("admin.link-checker.force-smart-scan") }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                }
            });

            const data = await res.json();
            alert(data.message);
        } catch (err) {
            alert('حدث خطأ في الاتصال أثناء تنفيذ الفحص.');
        }

        forceScanBtn.innerHTML = originalText;
        forceScanBtn.disabled = false;
    });
    
    function updateStartButton() {
        const anyChecked = checkboxes.some(cb => cb.checked);
        startBtn.disabled = !anyChecked || isScanning;
    }

    function getSelectedSources() {
        return checkboxes.filter(cb => cb.checked).map(cb => cb.value);
    }

    // ── State Transitions ────────────────────────────────────────────
    function showIdleState() {
        idleState.style.display = '';
        scanningState.style.display = 'none';
        doneState.style.display = 'none';
    }

    function showScanningState() {
        idleState.style.display = 'none';
        scanningState.style.display = '';
        doneState.style.display = 'none';
    }

    function showDoneState() {
        idleState.style.display = 'none';
        scanningState.style.display = 'none';
        doneState.style.display = '';
    }

    // ── Start Scan ───────────────────────────────────────────────────
    startBtn.addEventListener('click', async function () {
        if (isScanning) return;
        isScanning = true;
        allResults = [];
        updateStartButton();
        showScanningState();
        resultsPanel.style.display = '';
        resultsBody.innerHTML = '';

        const sources = getSelectedSources();

        // Step 1: Collect URLs
        progressLabel.textContent = 'جاري جمع الروابط...';
        progressPct.textContent = '0%';
        progressFill.style.width = '0%';
        progressDetail.textContent = 'تحضير قائمة الروابط...';
        liveHealthy.textContent = '0';
        liveBroken.textContent = '0';
        liveRedirect.textContent = '0';

        let urlEntries = [];

        try {
            const collectRes = await fetch('{{ route("admin.link-checker.collect") }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ sources }),
            });

            if (!collectRes.ok) throw new Error('فشل في جمع الروابط');

            const collectData = await collectRes.json();
            urlEntries = collectData.urls || [];
        } catch (err) {
            alert('حدث خطأ أثناء جمع الروابط: ' + err.message);
            isScanning = false;
            showIdleState();
            updateStartButton();
            return;
        }

        if (urlEntries.length === 0) {
            progressLabel.textContent = 'لا توجد روابط للفحص';
            isScanning = false;
            showDoneState();
            doneTotal.textContent = '0';
            updateStartButton();
            updateStats();
            return;
        }

        statTotal.textContent = urlEntries.length;
        progressLabel.textContent = 'جاري الفحص...';
        progressDetail.textContent = `فحص 0 من ${urlEntries.length} رابط`;

        // Step 2: Send in batches
        const BATCH_SIZE = 8;
        let checked = 0;
        let healthy = 0, broken = 0, redirect = 0, slow = 0;

        for (let i = 0; i < urlEntries.length; i += BATCH_SIZE) {
            const batch = urlEntries.slice(i, i + BATCH_SIZE);

            try {
                const scanRes = await fetch('{{ route("admin.link-checker.scan") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ entries: batch }),
                });

                if (!scanRes.ok) throw new Error('فشل في فحص الدفعة');

                const scanData = await scanRes.json();
                const results = scanData.results || [];

                results.forEach(r => {
                    allResults.push(r);
                    checked++;

                    if (r.check.is_broken) broken++;
                    else if (r.check.is_redirect) redirect++;
                    else healthy++;
                    if (r.check.response_time_ms > 3000) slow++;
                });

                // Update progress
                const pct = Math.round((checked / urlEntries.length) * 100);
                progressFill.style.width = pct + '%';
                progressPct.textContent = pct + '%';
                progressDetail.textContent = `فحص ${checked} من ${urlEntries.length} رابط`;
                liveHealthy.textContent = healthy;
                liveBroken.textContent = broken;
                liveRedirect.textContent = redirect;

            } catch (err) {
                console.error('Batch error:', err);
                // Continue with next batch
                batch.forEach(() => {
                    checked++;
                    broken++;
                    allResults.push({
                        url: batch[0]?.url || '?',
                        source_type: batch[0]?.source_type || '?',
                        source_id: batch[0]?.source_id || 0,
                        source_label: batch[0]?.source_label || '?',
                        check: { status_code: 0, is_broken: true, is_redirect: false, error: 'خطأ في الاتصال بالخادم', response_time_ms: 0 }
                    });
                });
            }
        }

        // Step 3: Done
        isScanning = false;
        showDoneState();
        doneTotal.textContent = allResults.length;
        updateStartButton();
        updateStats();
        renderResults();

        // Save results to session
        try {
            await fetch('{{ route("admin.link-checker.save") }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    results: allResults,
                    summary: { healthy, broken, redirect, slow, total: allResults.length }
                }),
            });
        } catch (e) {
            // Ignore save errors
        }
    });

    // ── Clear Results ────────────────────────────────────────────────
    clearResultBtn.addEventListener('click', async function () {
        allResults = [];
        resultsBody.innerHTML = '';
        resultsPanel.style.display = 'none';
        showIdleState();
        statHealthy.textContent = '0';
        statBroken.textContent = '0';
        statSlow.textContent = '0';

        try {
            await fetch('{{ route("admin.link-checker.clear") }}', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
            });
        } catch (e) {}
    });

    // ── Update Stats ─────────────────────────────────────────────────
    function updateStats() {
        let healthy = 0, broken = 0, slow = 0;
        allResults.forEach(r => {
            if (r.check.is_broken) broken++;
            else healthy++;
            if (r.check.response_time_ms > 3000) slow++;
        });
        statHealthy.textContent = healthy;
        statBroken.textContent = broken;
        statSlow.textContent = slow;
    }

    // ── Render Results ───────────────────────────────────────────────
    function renderResults() {
        const statusFilter = document.querySelector('.lc-filter-status.active')?.dataset.status || 'all';
        const sourceFilter = filterSource.value;
        const searchFilter = (filterSearch.value || '').toLowerCase().trim();

        let filtered = allResults;

        if (statusFilter !== 'all') {
            filtered = filtered.filter(r => {
                if (statusFilter === 'broken') return r.check.is_broken;
                if (statusFilter === 'redirect') return !r.check.is_broken && r.check.is_redirect;
                if (statusFilter === 'healthy') return !r.check.is_broken && !r.check.is_redirect;
                return true;
            });
        }

        if (sourceFilter !== 'all') {
            filtered = filtered.filter(r => r.source_type === sourceFilter);
        }

        if (searchFilter) {
            filtered = filtered.filter(r =>
                (r.url || '').toLowerCase().includes(searchFilter) ||
                (r.source_label || '').toLowerCase().includes(searchFilter) ||
                (r.check.error || '').toLowerCase().includes(searchFilter)
            );
        }

        // Sort: broken first, then redirect, then healthy
        filtered.sort((a, b) => {
            const scoreA = a.check.is_broken ? 0 : (a.check.is_redirect ? 1 : 2);
            const scoreB = b.check.is_broken ? 0 : (b.check.is_redirect ? 1 : 2);
            return scoreA - scoreB;
        });

        resultsBody.innerHTML = '';

        if (filtered.length === 0) {
            noResults.style.display = '';
            return;
        }

        noResults.style.display = 'none';

        filtered.forEach(r => {
            const tr = document.createElement('tr');

            // Determine status
            let statusClass, statusLabel, statusIcon;
            if (r.check.is_broken) {
                statusClass = 'broken'; statusLabel = 'معطوب'; statusIcon = 'feather-x-circle';
            } else if (r.check.is_redirect) {
                statusClass = 'redirect'; statusLabel = 'إعادة توجيه'; statusIcon = 'feather-corner-up-right';
            } else {
                statusClass = 'healthy'; statusLabel = 'سليم'; statusIcon = 'feather-check-circle';
            }

            // HTTP code class
            let httpClass = 'lc-http-zero';
            if (r.check.status_code >= 200 && r.check.status_code < 300) httpClass = 'lc-http-ok';
            else if (r.check.status_code >= 300 && r.check.status_code < 400) httpClass = 'lc-http-redirect';
            else if (r.check.status_code >= 400) httpClass = 'lc-http-error';

            // Response time class
            const rtClass = r.check.response_time_ms > 3000 ? 'lc-response-slow' : 'lc-response-fast';
            const rtValue = r.check.response_time_ms > 0
                ? (r.check.response_time_ms >= 1000
                    ? (r.check.response_time_ms / 1000).toFixed(1) + 's'
                    : r.check.response_time_ms + 'ms')
                : '—';

            tr.innerHTML = `
                <td data-label="الحالة">
                    <span class="lc-badge lc-badge-${statusClass}">
                        <i class="${statusIcon}"></i> ${statusLabel}
                    </span>
                </td>
                <td data-label="الرابط" class="lc-url-cell">
                    <a href="${escapeHtml(r.url)}" target="_blank" rel="noopener" title="${escapeHtml(r.url)}">${escapeHtml(r.url)}</a>
                </td>
                <td data-label="المصدر">
                    <span class="lc-source-badge">
                        <i class="${sourceIcons[r.source_type] || 'feather-link'}"></i>
                        ${escapeHtml(sourceLabels[r.source_type] || r.source_type)}
                    </span>
                    <br><small class="text-muted">${escapeHtml(r.source_label)}</small>
                </td>
                <td data-label="كود HTTP">
                    <span class="lc-http-code ${httpClass}">${r.check.status_code || '—'}</span>
                </td>
                <td data-label="الاستجابة">
                    <span class="lc-response-time ${rtClass}">${rtValue}</span>
                </td>
                <td data-label="الخطأ" class="lc-error-cell" title="${escapeHtml(r.check.error || '')}">
                    ${escapeHtml(r.check.error || '—')}
                </td>
            `;

            tr.dataset.status = statusClass;
            tr.dataset.source = r.source_type;
            resultsBody.appendChild(tr);
        });
    }

    // ── Filters ──────────────────────────────────────────────────────
    filterBtns.forEach(btn => {
        btn.addEventListener('click', function () {
            filterBtns.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            renderResults();
        });
    });

    filterSource.addEventListener('change', renderResults);

    let searchTimer = null;
    filterSearch.addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(renderResults, 300);
    });

    // ── Helpers ──────────────────────────────────────────────────────
    function escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
});
</script>
@endsection
