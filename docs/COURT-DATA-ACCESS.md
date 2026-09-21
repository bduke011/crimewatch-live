# Court data access findings

Checked September 20, 2026. These findings describe available public information, not a completed vendor integration or a data license.

## iDocket

No public developer API documentation or self-service data-feed signup was located on iDocket's official site. This does not establish that a private or commercial API is unavailable. Ordinary search subscriptions do not establish permission for automated collection or redistribution.

The current [iDocket news notice](https://online.idocket.com/Home/News) states that Polk District was removed on July 16, 2024, and that Polk County Court updates are not being received. The older 2016 announcement adding Polk should not be used as evidence of current coverage.

The [county directory](https://online.idocket.com/Home/Counties) provides courts, case types, historical date ranges, and latest filing dates. Assess these per court before relying on its records. [Subscription features](https://online.idocket.com/Subscriber) include searches by party, court, cause number and bondsman, plus case histories and document purchases subject to the plan.

Official contacts: sales@idocket.com, support@idocket.com, 1-800-436-2538 ext. 2. See the [contact page](https://online.idocket.com/Home/contact-us).

## Tyler and direct court sources

The [Polk Tyler Portal](https://portal-txpolk.tylertech.cloud/Portal/) is linked by the [county District Attorney](https://www.polktx.gov/318/Criminal-District-Attorney). Its public case-index search uses human verification. Additional events, orders and documents depend on the user's access. CrimeWatch currently opens this portal for manual research.

Tyler serves many jurisdictions, but a county's portal is not automatically a statewide search. [re:SearchTX](https://research.txcourts.gov/) is a separate broader Texas research option, linked by the [Polk District Clerk](https://www.polktx.gov/317/District-Clerk). Check participating court, case type, historical coverage and access level; do not claim that it supplies every criminal case or a complete criminal history.

Tyler advertises APIs through its [Enterprise Justice Integration Portal](https://www.tylertech.com/products/enterprise-justice/enterprise-justice-integration-portal/client-registration-access). This establishes an integration program, not that CrimeWatch has credentials, county authorization or commercial redistribution rights.

For records missing from portals, the relevant County or District Clerk is the direct source. Ask whether an existing public-record export or recurring data service is available, including its fields, dates, fees and permitted uses. No recurring export for Polk has yet been confirmed.

## Inquiry prepared for iDocket (not sent)

Subject: Commercial court-data API or licensed feed for CrimeWatch.live

We are developing CrimeWatch.live, an application that helps users research public bookings and helps bail bond agents review public court records. Do you offer an API, bulk export or licensed recurring feed for a third-party commercial application?

Please provide:

1. API or feed documentation, sandbox availability, authentication, and search/rate limits.
2. Current criminal-case coverage by county and court, including misdemeanor/felony coverage, earliest records, latest updates, and Polk County's current status.
3. Availability of case numbers, parties, charges, dispositions, hearings, bonds, documented failures to appear and public documents, plus identifiers suitable for human identity review.
4. Pricing and terms for displaying records to our users, caching, retention, attribution, correction/removal handling, and whether each user needs an individual iDocket subscription.
5. Update intervals, outage reporting, and how amended or removed records are communicated.

No vendor contact, purchase, account creation, or automated portal collection has been performed.

## Implementation decision

CrimeWatch searches its connected Polk booking archive and current roster automatically. Court systems appear separately as manual external sources. Build additional county connections only after verifying source coverage and access. Prefer a licensed API/feed for automated court imports; do not base the application on bypassing portal challenges.
