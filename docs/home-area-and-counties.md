# Home area and county coverage

The home county is confirmed by the reader and saved through the authenticated area-api.php, shared by web and native sessions. GPS is optional. Texas boundaries are processed on the device and coordinates are not sent to a reverse-geocoding provider. Existing profiles are preserved; account deletion removes the saved home record.

All 254 Texas counties are listed in the owner county controls. Only features with a connected source can be enabled. Initial sources: Polk map/arrests; Liberty and Angelina arrests. San Jacinto arrests remain coming soon based on the owner's conversation with the sheriff. County availability describes configured coverage, not a guarantee of completeness or freshness.

Private analytics now include self-reported app/website channel counts; older clients are unspecified, and historical totals are not backfilled. Reader search is admin-only. Aggregate sales reports exclude reader contact details.

Boundary source: U.S. Census Bureau TIGERweb Generalized ACS2024 State_County, Counties 5M layer 12, STATE=48; coordinates rounded to three decimal places and generalized to 0.003 degrees. Boundaries can be approximate near edges; users confirm the suggested county.
https://tigerweb.geo.census.gov/arcgis/rest/services/Generalized_ACS2024/State_County/MapServer/12

Courthouse images are unchanged photographs embedded in SVG wrappers for text-safe deployment; CSS crops the displayed banner. Attribution is shown in each banner. Full credits are in courthouse-photo-credits.json.

Server implementation lives in the LocalCrimeWatch workspace under local-crimewatch/public. It is deployed to /home1/crimewatch/public_html/local. The native bridge permits the home area API and existing Liberty/Angelina report and photo endpoints.
