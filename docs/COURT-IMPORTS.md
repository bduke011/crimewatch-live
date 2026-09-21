# Saved court records

The September 21, 2026 upload is a collection of 36 person-search summaries. It reports 144 case matches but does not contain 144 individual case records. The canonical full JSON was imported once; the CSV and copy in the ZIP are duplicate representations. The older 10-person sample overlaps the full export and contains narrative interpretations, not structured case histories. It is not used to manufacture individual charges, dispositions or identity confirmations. The page-two name list is a queue, not completed court research.

Initial import: 36 stored summaries, 35 searchable, one held because its own notes mix two possible identities. A comparison against the current roster found 34 matching booking dates, one differing date, and one name no longer on the roster. The API flags the differing date without merging people or changing the supplied date.

## Where results appear

Person Research searches three independent collections: saved court lookups, booking archive, and current roster. Existing booking links prefill this search. Court summaries display the export date, booking referenced by the upload, reported match count, and syntactically valid case references. Names and identifiers still require review. Counts may include civil cases; zero means only that the supplied lookup reported no matches.

All results stay in CrimeWatch. There are no outbound research links and no iDocket connection. Raw notes, race/sex/physical descriptors and purported identity confirmations are not in the public API. The importer does not translate Inactive into a warrant, Closed into a conviction, or a name match into a verified identity. The newer case-index import adds filing dates, case types and source status labels. Specific charges, hearing histories and documents remain unavailable unless included in a future supported export.

## Case-index export added September 21

The owner confirmed the source and authorized the newer `court_cases_detail.json` export (generated 01:43:02). It contains 136 case rows across 36 people. The JSON inside the new ZIP is identical; CSV rows also include placeholders for people without cases. Import the JSON once, not all representations.

The importer accepts dataset `crimewatch_polk_court_cases_detail` with `generated`, `source`, and `people`. Each person includes `roster_name`, `booked`, boolean `record_complete`, and `cases`. Each case requires `case_number`, `style_defendant`, `file_date`, `case_type`, `status`, `party_name`, and boolean `subject_is_defendant`. Duplicate case numbers within one person and malformed fields reject the entire file. The original export stays private; normalized case sets are stored in `court_case_sets`. Older/equal case-set timestamps cannot replace newer case sets. A later partial snapshot is labeled partial; absence is not interpreted as dismissal or a conviction.

The display groups exact normalized named defendants with a supplied defendant flag and case type Misdemeanor, Felony Indictment, or Felony Information under criminal cases. Other criminal search hits identify the named party separately. Civil and other proceedings are a separate group regardless of the supplied defendant flag. `is_live` is deliberately not interpreted because it combines Active, Inactive and Filed. Source statuses are shown verbatim, with an explanation that Disposed does not establish a conviction or specify an outcome.

Arredondo has ten criminal entries, two civil/other matters and three search hits naming another defendant. Garcia has three misdemeanor entries and one civil matter. Lara's thirteen rows are labeled partial. The Plattenburg profile retains its existing identity hold; its eighteen rows are stored privately. That leaves 118 case rows across 35 public profiles, including thirteen profiles with zero supplied case rows. Raw descriptors and notes are not exposed. Removal matching also applies to other named parties inside case sets.

The same hourly inbox worker imports both dataset formats. Deploy `court_details.py` alongside `court_import.py`; initialize prior summary imports before case-index exports when restoring a fresh database so prior identity holds are retained. No portal automation or document download is added by this import.

## Recurring import

The private hourly job runs at minute 2:

```text
/usr/bin/python3 /home1/crimewatch/jail-service/court_worker.py
```

New full-format JSON exports go into:

```text
/home1/crimewatch/jail-service/data/court-inbox/
```

Upload each completed export under a unique filename. If uploading in chunks, use an extension other than `.json` until the upload is complete, then rename it. The worker validates each entire file before writing its rows. Invalid files remain available for correction and are listed in the private status report; other valid files can still import. Identical file hashes are skipped. Repeated name/booking/source keys update the existing summary; older source timestamps cannot replace newer ones. Absent rows do not delete earlier imports. Keep timestamps consistent across exports because the source's original timestamp string determines ordering.

Private files:

- `data/court-import-status.json`: last job time, counts, errors and queue size.
- `court-import.log`: scheduled job output.
- `data/court-pending.json`: current roster names without an exact name/booking snapshot, ambiguous entries requiring review, and snapshots older than seven days.

The job only imports local files and rebuilds the queue. It performs no portal searches, does not bypass human verification, and does not produce new court records on its own. A separate functioning collection process or approved feed must deliver additional exports. No notification or paid service has been enabled.

## Accepted export format

Use the `crimewatch_polk_court_lookups` dataset format from `court_lookups_full.json`: `generated` ISO timestamp, a Polk/Tyler `source` label, `record_count`, and `records`. Each record needs `roster_name`, `booked` (YYYY-MM-DD), integer `cases_total` and `live_cases`, string-array `live_case_numbers`, and optional `notes`. `live_cases` is retained privately as the upload's grouping; it is not displayed as verified active criminal cases. Physical descriptors are not ingested into searchable tables.

Explicit ambiguous-identity notes or positive grouped counts without usable case numbers hold the row from public search. Existing identity holds persist across subsequent imports, including detailed case exports. Resolve the conflicting identifiers through a deliberate review before clearing a hold.

## Removals and validation

Court-specific removals use `court_suppressions` keyed by lookup ID. Reimports preserve suppressions. Existing jail takedowns also suppress court summaries with the same normalized full name, conservatively across booking dates. This avoids reintroducing removed names through the new search collection.

Tests use synthetic records and cover duplicate/revised/stale files, whole-batch validation, ambiguous identities, placeholder case references, private-field exclusion, booking-date conflicts, takedowns and court suppressions, queue creation, and unavailable-source responses. Native routing tests and the iOS bundle sync also pass. Browser checks cover real imported references, zero-count context, review selections, new-search reset, internal-only links, and a 390px viewport. No signed iOS release was made.
