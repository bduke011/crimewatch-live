# CrimeWatch.live

Private source-code snapshot of the existing CrimeWatch.live website build, with
a Capacitor iPhone app on the `ios-app` branch.
This repository does not contain collected records, original reports, databases,
credentials, or server backups.

## iPhone app

The app bundles HTML, styles, JavaScript, and map libraries locally. Its PHP data
endpoints remain at `https://crimewatch.live`. Native HTTP handles these requests;
no production CORS changes are required. Saved incident copies (up to 100) and the
preferred starting area use Capacitor Preferences. Native sharing uses the iOS
share sheet; external links use the system browser view.

- Bundle identifier: `live.crimewatch.app`
- Source: `mobile/`; generated web bundle: `dist-mobile/`
- Xcode project: `ios/App/App.xcodeproj`
- Install: `npm ci`
- Test: `npm test`
- Bundle and sync: `npm run ios:sync`
- Local browser preview with live-data proxy: `node mobile/preview.mjs`
- Cloud build: Codemagic `ios-archive`, `ios-simulator`, or `ios-testflight`

The archive workflow builds and launches the app in an iPhone simulator, captures
a screenshot, then produces an App Store-signed IPA. It does not upload to Apple.
The TestFlight workflow uploads to App Store Connect; tester assignment occurs
after Apple finishes processing. No workflow submits a public App Store release.

The Codemagic integration named `Scan2Profit` supplies the existing Apple account
authentication and distribution certificate. CrimeWatch uses its own provisioning
profile `crimewatch_push_appstore_profile`; its app identity is separate from Scan2Profit.
App creation in the App Store Connect website is required before the first upload.

Optional push alerts are controlled in Settings. Nearby reports use a manually selected center, radius, agency, and categories. Daily summaries use the device time zone and chosen hour. Saved-report alerts watch published content changes. Alerts start off; each type and the master switch can be disabled. No accounts, advertising, or device location permission are used.

`services/alerts/` contains the private PHP/SQLite notification service and its tests. Deploy this directory outside the document root as `/home1/crimewatch/alerts-service`; only `public/alerts-api.php` is public. The existing incident archive is read, never modified. A 5-minute cron scans changes and delivers through APNs. A production, topic-specific APNs key for `live.crimewatch.app` and a private `config.json` are required. Never commit either. Server tests: `php services/alerts/tests.php`.
Saved reports work offline; live searching and map tiles require connectivity.

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
