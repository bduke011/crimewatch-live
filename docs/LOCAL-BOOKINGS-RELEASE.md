# CrimeWatch local bookings update — September 20, 2026

Latest recovered website and iPhone source are on branch `local-bookings`.
The live site at https://crimewatch.live/ was updated from this branch's public interface.

Implemented:
- Yesterday's published bookings as the homepage, with full calendar-day context, date selection, today, and latest available day.
- Prominent current-roster search and links from booking details to court research.
- Remembered Community/Bondsman view on the device. Polk County, Texas is the only connected booking county.
- Tyler Polk portal and re:SearchTX links with selected name ready to copy. Court cases are not automatically imported or identity matched.
- Native app navigation for Home, Roster, Research, Saved and Settings; incident map and FBI data remain linked from Home.
- Native roster API routing and hosted photo URLs.
- Fixed booking importer schema regression using an explicit insert-column list. Retried seven failed source reports, adding 43 records (247 to 290) with zero failures.

Verified: eight Node tests, Python schema regression test against original and extended databases, mobile bundle build and Capacitor sync, browser booking-to-research flow, responsive homepage, and live homepage showing September 19 data.

Not included: additional county ingestion, automated court imports, bondsman accounts, private notes, subscription billing, or new booking/court alerts. Existing saved incident reports and alert implementation are retained. The iPhone project is synchronized, but no signed Xcode/TestFlight/App Store release was performed in this update.

Deployment used existing HostGator/cPanel hosting and preserved live public files in the task's `work/live-backup` directory before changes. No credentials, booking databases, or PDFs are included in this repository or source ZIP. The temporary recovery job was removed after successful collection; existing collection schedules remain in place.

## Photo repair

Recovered the deployed roster collector into this repository. Fixed the released-list sort callback to encode each DevExpress argument separately. Retry missing photos on recent released records after the detail refresh interval, while respecting takedowns. Photo links now attach immediately after PDF import. New links require a unique name, suffix, age and booking-date match. Cards explicitly show “Photo unavailable” when no image is returned. Regression tests cover sort and pagination encoding, missing-photo retry eligibility, takedowns and ambiguous identity matches.
