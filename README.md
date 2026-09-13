# CrimeWatch.live

Private source-code snapshot of the existing CrimeWatch.live website build.
This repository does not contain collected records, original reports, databases,
credentials, or server backups. It is not an iOS app.

## Layout

- `public/`: existing website HTML, CSS, JavaScript, PHP endpoints, and Apache rules.
- `services/jail/`: existing private service source, preserved without changes.
- `services/fbi/`: existing FBI archive build and verification scripts.
- `docs/website.md`: prior website implementation notes; some counts and UI descriptions are historical.

## Runtime

The website uses PHP and SQLite on cPanel. It requires PHP extensions including
PDO SQLite and cURL; incident collectors also use DOM and SimpleXML. The saved
Python jail service uses pypdf and an America/Chicago timezone database.

Existing source files retain their original paths and configuration assumptions.
Some one-time FBI scripts depend on original workspace metadata and large input
archives that are deliberately not included here. This is a source snapshot,
not a complete disaster-recovery backup or a fresh-install package.

## Deployment boundary

Creating or updating this repository does not deploy anything. There are no
GitHub Actions, webhooks, or scheduled jobs configured by this snapshot.

Preserve production databases, original files, private service directories,
existing cron entries, and cPanel-generated PHP configuration. Never deploy the
repository root as a public web directory: private service code and documentation
must stay outside the document root. Review file-specific changes before any
future deployment; do not replace production data with a local copy.

## Secrets and data

Hosting credentials remain in the machine's protected credential store and are
not included. Runtime data and report files are excluded with `.gitignore`.
No distribution license has been granted by adding this source snapshot.
