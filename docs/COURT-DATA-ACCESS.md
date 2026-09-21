# Court data access findings

Checked September 20, 2026. These findings describe available public information, not a completed vendor integration or a data license.

## iDocket — excluded from the product

No public developer API documentation or self-service data-feed signup was located on iDocket's official site. This does not establish that a private or commercial API is unavailable. Ordinary search subscriptions do not establish permission for automated collection or redistribution.

The current [iDocket news notice](https://online.idocket.com/Home/News) states that Polk District was removed on July 16, 2024, and that Polk County Court updates are not being received. The older 2016 announcement adding Polk should not be used as evidence of current coverage.

The [county directory](https://online.idocket.com/Home/Counties) provides courts, case types, historical date ranges, and latest filing dates. Assess these per court before relying on its records. [Subscription features](https://online.idocket.com/Subscriber) include searches by party, court, cause number and bondsman, plus case histories and document purchases subject to the plan.

Official contacts: sales@idocket.com, support@idocket.com, 1-800-436-2538 ext. 2. See the [contact page](https://online.idocket.com/Home/contact-us).

## Tyler and direct court sources

The [Polk Tyler Portal](https://portal-txpolk.tylertech.cloud/Portal/) is linked by the [county District Attorney](https://www.polktx.gov/318/Criminal-District-Attorney). Its public case-index search uses human verification. Additional events, orders and documents depend on the user's access. CrimeWatch does not link users out to this portal. An approved data connection would be needed to show these records inside the app.

Tyler serves many jurisdictions, but a county's portal is not automatically a statewide search. [re:SearchTX](https://research.txcourts.gov/) is a separate broader Texas research option, linked by the [Polk District Clerk](https://www.polktx.gov/317/District-Clerk). Check participating court, case type, historical coverage and access level; do not claim that it supplies every criminal case or a complete criminal history.

Tyler advertises APIs through its [Enterprise Justice Integration Portal](https://www.tylertech.com/products/enterprise-justice/enterprise-justice-integration-portal/client-registration-access). This establishes an integration program, not that CrimeWatch has credentials, county authorization or commercial redistribution rights.

For records missing from portals, the relevant County or District Clerk is the direct source. Ask whether an existing public-record export or recurring data service is available, including its fields, dates, fees and permitted uses. No recurring export for Polk has yet been confirmed.

## Implementation decision

The owner has excluded iDocket because of cost and wants research results displayed inside CrimeWatch. Do not add outbound court-research links or an iDocket integration. No vendor inquiry was sent and no subscription was purchased.

CrimeWatch searches its connected Polk booking archive and current roster automatically and displays results and full booking details within the site/app. Court history is explicitly marked "Not connected". References and hearing dates in booking data must not be presented as court-history search results.

For future court data, investigate direct public court exports or approved integrations that can display results inside CrimeWatch. Prefer no-cost sources and confirm any fees with the owner before making commitments. An embedded external website does not satisfy this requirement. No direct Polk court-data feed is confirmed yet.

## September 21 update

Owner-supplied court lookup summaries are now imported locally and searchable alongside bookings. The court-not-connected description above is superseded for this limited saved collection. Live court history and automated portal collection remain unconnected. See COURT-IMPORTS.md.
