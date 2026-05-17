# Changelogs

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
