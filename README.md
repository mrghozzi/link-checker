# Smart Link Checker (AI-Powered) — v1.3.0

Scans all external URLs across **Directory listings**, **Store products**, **Banner Ads**, **Link Ads**, **Smart Ads**, **Community Posts**, and **Visit Exchange** entries to detect broken, dead, or slow-responding links.

Now featuring an **AI-Powered Smart Scan** utilizing the Groq API to deeply analyze link safety and detect inappropriate content (NSFW, phishing, illegal).

## Features

- **AI Smart Scan**: Automatically scans links via Groq AI for malicious or inappropriate content and reports them directly to the Admin Report Center.
- **7 source types**: Community Posts, Directory, Store, Banner, Link, Smart Ads, Visit Exchange.
- **Admin Configuration**: Complete UI to configure the Groq API Key, test the connection, set batch limits, and force execution.
- **Active Only**: Intelligently ignores suspended or inactive ads.
- **AJAX batch scanning**: Checks 10 URLs per request to avoid server timeouts.
- **Real-time progress**: Live progress bar with percentage completion.
- **Status classification**: Healthy (2xx/3xx), Redirect (301/302), Broken (4xx/5xx/timeout).
- **Filterable results**: Filter by status (all, healthy, broken) and by source type.
- **Direct admin links**: Jump to the relevant admin page for any broken entry.
- **Duralux admin theme**: Fully integrated with the MYADS admin panel.

## Installation

1. Copy the `link-checker` folder into `plugins/`
2. Go to **Admin → Settings → Plugins**
3. Activate **Broken Link Checker**
4. Access it at `/admin/link-checker`

## Requirements

- MYADS v4.5.6+
- PHP 8.2+ with cURL extension

## License

MIT
