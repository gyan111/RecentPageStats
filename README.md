# RecentPageStats

A MediaWiki extension providing two special pages for understanding recent editing activity on your wiki.

## Two Special Pages

### Special:RecentPageStats — Per-page edit table
A filterable, sortable table showing which pages have been edited recently, with per-page edit counts, last editor, page size, and timestamps.

### Special:RecentChangesStats — Dashboard & summary
An overall statistics dashboard showing aggregate numbers, namespace breakdown, top editors, and daily activity trends.

Both pages link to each other for easy navigation.

## Features

**Special:RecentPageStats:**
- Sortable table of recently edited pages
- Columns: page title, last editor, last edit time, edit count, page size
- Filters: days, namespace, sort order, minor edits
- Pagination (50 per page, configurable)
- Link batch loading for performance

**Special:RecentChangesStats:**
- Summary cards: total edits, pages edited, unique editors, new pages, minor edits, avg edits/page
- Namespace breakdown table (edits, pages, editors per namespace)
- Top 10 editors leaderboard
- Daily activity table with visual bar chart
- Filters: days, minor edits

**Shared:**
- WANObjectCache caching (5-minute TTL, configurable)
- Refresh button to bypass cache
- Full i18n support
- Modern PSR-4 namespaced code (`MediaWiki\Extension\RecentPageStats`)

## Requirements

- MediaWiki 1.39 – 1.45+
- PHP 8.1+

## Installation

```bash
cd extensions/
git clone <repository-url> RecentPageStats
```

Add to `LocalSettings.php`:

```php
wfLoadExtension( 'RecentPageStats' );
```

Navigate to:
- `Special:RecentPageStats` — per-page table
- `Special:RecentChangesStats` — dashboard

## Configuration

```php
// Days to look back (default: 30)
$wgRecentPageStatsDefaultDays = 30;

// Results per page on Special:RecentPageStats (default: 50)
$wgRecentPageStatsPerPage = 50;

// Cache duration in seconds (default: 300 = 5 minutes)
$wgRecentPageStatsCacheDuration = 300;
```

## Permissions

By default both pages are publicly viewable. To restrict:

```php
$wgGroupPermissions['*']['recentpagestats-view'] = false;
$wgGroupPermissions['sysop']['recentpagestats-view'] = true;
```

## File Structure

```
RecentPageStats/
├── extension.json                  # Extension manifest (v2)
├── RecentPageStats.alias.php       # Special page aliases
├── README.md
├── TESTING.md                      # Full testing guide
├── QUICKSTART.md                   # 5-minute setup
├── PRESENTATION.md                 # Meetup presentation guide
├── src/
│   ├── Special/
│   │   ├── SpecialRecentPageStats.php      # Per-page table
│   │   └── SpecialRecentChangesStats.php   # Dashboard page
│   └── Pager/
│       └── RecentPageStatsPager.php        # TablePager for page table
├── i18n/
│   ├── en.json                     # English messages
│   └── qqq.json                    # Message documentation
├── resources/
│   └── RecentPageStats.css         # Styles for both pages
├── maintenance/
│   └── generateTestData.php        # Test data generator
└── tests/
    └── phpunit/
        └── integration/            # PHPUnit integration tests
```

## Performance

### Caching

Both pages use **WANObjectCache**:
- Cache key includes: days, namespace, minor edits filter
- Default TTL: 5 minutes
- Refresh button bypasses cache on demand

### Production Recommendations

| Wiki Size | Cache Duration | Default Days | Per Page |
|-----------|---------------|-------------|----------|
| Small (<10K edits/mo) | 300s (default) | 30 | 50 |
| Medium (10K-100K) | 600s | 14 | 50 |
| Large (>100K) | 1800s | 7 | 25 |

### Database Queries

**Special:RecentPageStats:**
- `GROUP BY page_id` with `COUNT(*)`, `MAX()`, `GROUP_CONCAT()`
- Joins `recentchanges` + `page`

**Special:RecentChangesStats:**
- Aggregate `COUNT(*)`, `COUNT(DISTINCT)`, `SUM(CASE...)` on `recentchanges`
- Separate queries for namespace breakdown, top editors, daily activity
- All results cached together in one cache key

## Development

### Architecture

- **PSR-4 autoloading** via `AutoloadNamespaces` in `extension.json`
- Namespace: `MediaWiki\Extension\RecentPageStats\`
- Modern MediaWiki patterns: `WANObjectCache`, `LinkBatchFactory`, `UserFactory`
- Compatible with MediaWiki 1.39 through 1.45

### Testing

```bash
# Run integration tests
php tests/phpunit/phpunit.php extensions/RecentPageStats/tests/

# Generate test data
php extensions/RecentPageStats/maintenance/generateTestData.php --pages=50 --edits=10
```

See [TESTING.md](TESTING.md) for full guide and [QUICKSTART.md](QUICKSTART.md) for 5-minute setup.

## Internationalization

All strings are in `i18n/en.json` with documentation in `i18n/qqq.json`. To translate:

1. Create `i18n/<lang>.json` (e.g., `i18n/de.json`)
2. Copy structure from `en.json` and translate values

## Troubleshooting

**No results:** Check that `recentchanges` has data (`SELECT COUNT(*) FROM recentchanges;`)

**Extension not loading:** Ensure `LocalSettings.php` has `wfLoadExtension( 'RecentPageStats' );` — do NOT use `require_once`.

**Performance issues:** Reduce days, increase cache duration, filter by namespace.

## License

MIT License — Copyright (c) 2026 Jnanaranjan Sahu

## Author

**Jnanaranjan Sahu**  
Wikimedia Meta: https://meta.wikimedia.org/wiki/User:Jnanaranjan_sahu

## Changelog

### Version 2.0.0
- **Two special pages:** Split into `Special:RecentPageStats` (per-page table) and `Special:RecentChangesStats` (dashboard)
- **Modern architecture:** PSR-4 namespaces (`MediaWiki\Extension\RecentPageStats`), `AutoloadNamespaces`, `src/` directory
- **New dashboard features:**
  - Summary cards (total edits, pages, editors, new pages, minor edits, avg edits/page)
  - Namespace breakdown table
  - Top 10 editors leaderboard
  - Daily activity table with visual bars
- **Cross-navigation** between both special pages
- **Compatible with MediaWiki 1.39 – 1.45+**

### Version 1.1.0
- WANObjectCache caching with refresh button
- PHPUnit test infrastructure
- Performance documentation

### Version 1.0.0
- Initial release with per-page statistics, filters, pagination, page size, minor edits filter
