# CrimeWatch jail archive
Live: https://crimewatch.live/jail.html

Initial verified import September 12, 2026: 34 linked media reports, 247 booking entries, 387 charge entries. Report labels August 9–September 12; earliest booked date August 8. No complete-history claim. No photos in the initial public interface.

Host service: /home1/crimewatch/jail-service. Public files: jail.html, jail.css, jail.js, jail-api.php. Private PDFs, SQLite, source URLs/hashes and version history are outside public_html. No scheduled deletion of records or raw originals. Python 3.9 with pypdf 6.14.2 in private vendor directory.

Production cron runs /usr/bin/python3 /home1/crimewatch/jail-service/run_scheduled.py hourly at minute zero; America/Chicago slot gates collect at 00:00, 05:00 and 12:00, with retries on failure. Lock prevents overlapping imports. Parser-version mismatch also triggers refresh. Temporary setup and initial import cron entries removed. last-run.log and data/status.json record results. A failed source is omitted for that import; previously saved records remain.

Parser validates report identity, report dates, person boundaries and charge sections. Continued names/arrests are merged across pages. IDs combine published name, age, booked date and first arrest datetime; source lacks a dedicated booking ID, so occasional ambiguous matching remains possible. Release-not-reported is never interpreted as current custody. Report date reflects a snapshot, not current jail status or case disposition. Residence displayed as locality without postal suffix. Source PDFs retained for audit.

Validation: all 34 PDFs compared with independent name/age/booking-date extraction; all fields mapped, SQL search tested, live charge search and details verified. Future layout changes fail for review instead of publishing incomplete entries. Run work/test-jail-parser.py locally for validation. Never replace production incident or FBI databases with the jail database.
