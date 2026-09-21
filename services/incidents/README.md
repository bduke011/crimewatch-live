# Incident collection regression checks

Run `php services/incidents/tests.php` with PDO SQLite enabled. Tests use an isolated temporary database and do not contact the public source.

The September 21, 2026 incident-map outage came from an empty Polk source response being accepted as a successful collection. All saved reports in that date window were marked `not_listed`, hiding them from recent searches. The records were retained in the archive.

`nw_validate_snapshot()` now rejects an empty response when the archive already has Polk reports in that same date window, before updating source status, collection coverage, or the cache. This applies to current and historical collections. An existing successful cache remains available with `stale: true` if a new collection fails. Empty first collections and ranges without saved reports remain valid. Nonempty updates still mark individually missing reports as no longer listed.

The cache reader also rejects old false-empty snapshots, allowing a new collection to recover them. If no usable cache exists and collection fails, the API reports unavailable rather than claiming a successful zero-result collection. Archive records remain retained.

Deployment was verified against the live default 30-day Polk search: 111 reports, 110 mapped, one without coordinates. The browser displayed the restored markers. No records were fabricated or assigned coordinates to fill the gap.
