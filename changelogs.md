# Changelogs

## v1.3.0 — 2026-09-11

### Changed / Fixed
- **Groq Model Upgrade:** Replaced decommissioned `llama3-8b-8192` with `llama-3.1-8b-instant` as the default AI scanner model.
- **Configurable AI Model:** Added dynamic model selection in admin settings (`lc_groq_model`), allowing administrators to switch between `llama-3.1-8b-instant`, `llama-3.3-70b-versatile`, and `mixtral-8x7b-32768`.
- **Platform Compatibility:** Bumped `min_myads` requirement to `4.5.6`.

## v1.2.0 — 2026-07-18

### Added
- **AI Smart Scan**: Integrated Groq AI to deeply analyze links for inappropriate, malicious, or deceptive content (NSFW, phishing, illegal).
- **Admin Configuration**: Control Groq API Key, scan limits, and toggle Smart Scan directly from the dashboard.
- **Connection Testing**: Added a "Test Connection" button to verify Groq API Key validity instantly.
- **Force Scan**: Added an "Execute Now" button to bypass cron intervals and force immediate AI scanning.
- **Smart Reporting**: The AI now automatically reports bad links directly to the MyAds Admin Report Center (`/admin/reports`).
- **Post Integration**: AI intelligently reports the actual community post (Status) containing the bad link for accurate moderation.
- **Active Filter**: AI and manual scanners now strictly ignore inactive/suspended ads.


## v1.1.0 — 2026-05-17

### Added
- Parallel asynchronous URL checking using `cURL Multi` for 5x faster batch scans.
- Host filtering to prevent self-scanning the site's own local domain.

### Fixed
- Critical compatibility fix for MyAds v4.3.3 routing: corrected admin URLs for Banners, Smart Ads, Directory listings, Store products, Links, and Visits to avoid 404 pages and route directly to their edit/management screens.
- Updated minimum compatible version requirement to v4.3.3.

## v1.0.0 — 2026-04-19

### Added
- Initial release
- AJAX-based batch URL scanning across 6 source types
- Real-time progress bar with live updates
- Status classification: Healthy, Redirect, Broken
- Filterable results table with source type and status filters
- Direct admin links to edit broken entries
- Duralux admin theme integration
- Responsive design for mobile devices
