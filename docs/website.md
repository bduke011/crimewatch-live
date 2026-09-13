# CrimeWatch.live

Portable PHP/SQLite incident explorer on cPanel with area selection, map/list views, details, search, permanent collected history and scheduled imports. See ../BUILD-STATUS.md for current counts and remaining blockers.

## Runtime and deployment

PHP 8.1+ with cURL, DOM, SimpleXML and PDO_SQLite; Apache 2.4 with .htaccess. Web PHP uses ea-php82 and CLI PHP 8.4.24. No Node server is required. PHP needs write access to data/. CRIMEWATCH_DATA_DIR can place storage outside the document root when set consistently for web and CLI PHP.

Preserve the LIVE data directory on updates. crimewatch-update.zip contains code and protection rules only. The first-install package contains the original Polk seed and migrates automatically; it is not a full current archive export. Use SQLite backup or VACUUM INTO for consistent snapshots, not a standalone copy of an active WAL database. Preserve cPanel's generated PHP handler. Implementation files and data are denied HTTP access.

## Schedule and storage

Installed cron runs at minute 0 hourly using /opt/cpanel/ea-php84/root/usr/bin/php /home1/crimewatch/public_html/collector.php. The collector gates current collection to midnight, 05:00 and noon America/Chicago with daylight saving time. Each regional source runs independently; status is saved and errors logged privately. Failed regional imports retry at the next scheduled slot.

Collected records and earlier versions are retained indefinitely, with stable IDs and deduplication. Two redundant daily snapshots are retained; rotation never deletes primary archive records. Off-server replication is not configured. The 1,000 MB hosting quota will need expansion for indefinite growth. Houston's 240 MB database estimate guard reserves backup space and can defer large historical imports.

## Collectors

regions.php defines the registry, locks and archive integration; regional-collectors.php contains adapters. collector.php retains the original Polk adapter and scheduled runner. archive.php owns SQLite persistence; api.php returns normalized public results.

- Polk: public Citizen Connect incidents. Date searches retrieve missing history in batches of up to 31 days, within a one-year request. Coverage rechecks after seven days. Removed listings are marked without deleting records.
- SHSU: current Huntsville, Conroe and Woodlands HTML campus logs. Classification, report/occurrence date, location and disposition. No invented coordinates. Older PDFs are not imported automatically.
- Houston: annual public NIBRS CSV, refreshed when its fingerprint changes. Current file ends June 30, 2026. Stable keys distinguish offense classes within incidents. Includes hour-only time, approximate block location, coordinates, beat, premise and offense count. Date searches can import missing years from 2019, subject to storage reserve.
- SFA: current public HTML campus narratives and log dates. Older monthly PDFs are not imported automatically. Unverified coordinates remain list-only.
- Kaufman: public Citizen Connect incidents, adaptively splitting dates to avoid the 200-row response cap. Historical searches use seven-day batches within a one-year request.
- Forney: calls-for-service API used by its public map, following the map's published area/category filters, pagination and unmapped entries. Calls are not confirmed crimes. Coordinates retain roughly 500-meter publisher precision. Historical searches use seven-day batches within a one-year request.

Nacogdoches County Sheriff and Harris County Sheriff remain unconnected because usable feeds were unavailable during verification. University or city coverage does not imply countywide coverage. Empty results do not prove no incidents occurred.

Private references: cc.southernsoftware.com; shsu.edu/offices-departments/university-police-department/daily-crime-log/; houstontx.gov/police/cs/xls/NIBRSPublicView2026.csv; sfasu.edu/police/public-records; forneypdtx-transparency.connect.socrata.com/api/tickets/details.json and other_pins_tickets.json. This README is not deployed and is blocked by Apache.

## UI and validation

Public results contain normalized fields and per-area coverage, without provider URLs or raw response metadata. Results page in groups of 200; the map shows the current page. OpenStreetMap/Leaflet attribution must remain. No paid accounts, ads, analytics or alerts are enabled.

Validated syntax, deduplication, preserved versions, agency isolation, prepared queries, invalid agency rejection, live imports and area selection. Latest report date is distinct from collection time. Source delays and completeness vary.
