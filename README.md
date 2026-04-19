# Broken Link Checker — MYADS Plugin

Scans all external URLs across **Directory listings**, **Store products**, **Banner Ads**, **Link Ads**, **Smart Ads**, and **Visit Exchange** entries to detect broken, dead, or slow-responding links.

## Features

- **6 source types**: Directory, Store, Banner, Link, Smart Ads, Visit Exchange
- **AJAX batch scanning**: Checks 10 URLs per request to avoid server timeouts
- **Real-time progress**: Live progress bar with percentage completion
- **Status classification**: Healthy (2xx/3xx), Redirect (301/302), Broken (4xx/5xx/timeout)
- **Filterable results**: Filter by status (all, healthy, broken) and by source type
- **Direct admin links**: Jump to the relevant admin page for any broken entry
- **Duralux admin theme**: Fully integrated with the MYADS admin panel

## Installation

1. Copy the `link-checker` folder into `plugins/`
2. Go to **Admin → Settings → Plugins**
3. Activate **Broken Link Checker**
4. Access it at `/admin/link-checker`

## Requirements

- MYADS v4.3.0+
- PHP 8.2+ with cURL extension

## License

MIT
