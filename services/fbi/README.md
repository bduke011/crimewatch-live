# CrimeWatch FBI archive — September 12, 2026

Public pages: https://crimewatch.live/fbi.html and https://crimewatch.live/fbi-collections.html

## Collected and searchable
- Texas NIBRS annual archives 1997–2025 plus the Texas portion of the provisional 2026 master.
- 14,595,273 incident records and 15,727,636 offense records across these snapshots. Counts are submitted records, not statewide estimates or a claim of complete agency reporting.
- 126,795 Texas rows across eight additional tables: state estimates 1979–2025, employee and participation history 1960–2025, hate crime 1991–2025, officer assaults 1995–2025, and available Texas trafficking/cargo records 2014–2025.
- These pages have no incident addresses, coordinates, or narratives. Agency coordinates were not turned into incident pins.

## Retention and storage
- `raw/`: all 29 annual Texas ZIP files and Texas 2026 master segments, retained indefinitely. Raw source tables include material beyond the public incident search.
- `search/`: separate per-year search databases and metadata. Rebuildable from the originals; never merge with the local incident database.
- `additional/texas/`: complete Texas-filtered copies of the eight supplementary CSV tables, including all source columns and duplicate column positions. Original national downloads remain in `additional/`.
- `supplemental/`: 319 verified national master archives and help files, including Texas submissions, across nine collections. Some begin in 1985. These original master files are retained locally and have not all been normalized into Texas-only public tables. They must not be described as fully searchable.
- Hosting has a second copy of the annual originals at `/home1/crimewatch/fbi-raw`, annual search databases under `/home1/crimewatch/public_html/data/fbi`, and the eight Texas supplementary tables in that protected data folder. The large national supplemental master archive currently remains local.
- No archive has an age-based deletion policy. This is a retention policy, not a guarantee against hardware loss; keep independent backups of this directory.

## Collection timing
The FBI collection is a saved September 12, 2026 snapshot. The completed import cron jobs were removed. Future FBI releases need a new versioned import; there is no automatic FBI refresh yet. Preserve old raw snapshots when importing corrections.
Houston's recent mapped feed runs through the existing local collector at midnight, 05:00, and noon America/Chicago. It uses published full Open Location Codes, decoded and rounded to three decimals; points are approximate and not address-verified. Its rolling recent window does not fill all earlier publication gaps. Historical CSV imports remain separate from the current rolling collector.

## Validation
Independent local Python and hosted PHP NIBRS imports agree for all 30 years. All 29 annual raw hosted SHA-256 values match local originals. All years, Houston agency filtering, offense filtering, pagination, and all eight additional collection year filters passed live API tests. Supplemental ZIP CRC checks passed, including legacy implode archives validated with 7-Zip. A few NIBRS agency IDs lack a supplied agency reference and are retained with a name-unavailable label.

## Maintenance
Source catalog: FBI CDE Documents & Downloads, https://cde.ucr.cjis.gov/LATEST/webapp/#/pages/downloads . Manifests preserve hashes and actual coverage.
`tools/` contains the import/validation scripts used from the original workspace. Before another run, adapt paths to a new dated snapshot directory. Scripts that skip existing files preserve snapshots; they are not an update service. cPanel import scripts require explicit CLI execution and should not be placed in the public document root. No hosting credentials are bundled here.
The hosting quota is 4800 MB. Check headroom before additional large imports. Failed historical import logs were compressed, and all temporary import cron jobs were removed after verification.
