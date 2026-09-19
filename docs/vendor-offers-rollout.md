# Shared products and seller offers

Vendor submissions still require administrator approval. The review screen searches the existing sub-subcategory and lets the administrator select the exact master product or confirm a genuinely new identity. A matching name is only a suggestion; conflicting brand, manufacturer, type or specification values prevent linking. Repeated same-vendor/product links and obvious duplicate master creation are rejected. Review each new-product submission individually; bulk approval cannot safely confirm identities.

`Products_Vendor_Offers_T` owns each seller's price, cost, minimum price, inventory, availability and commission. Its unique key is `(Products_Id, Vendor_Id)`. `Products_Vendor_Offer_Bulk_Prices_T` owns that seller's quantity tiers. Masters retain shared names, descriptions, categories, specifications and media. Legacy master `Vendor_Id` remains a compatibility marker; it must not be reassigned to change a seller.

Search/category results represent offers separately. The product page lists available sellers and carries `vendor_offer_id` into the cart. Cart uniqueness, guest-cart merge, checkout identity, order lines, stock deduction, cancellation and returns preserve that selection. Orders retain their existing vendor headers and financial snapshots. Shared master content and customer reviews remain common across sellers.

Vendor edits request changes to their own selling fields; vendor stock adjustments affect only their own offer. Admin Vendor Products edits pass the selected offer ID, and concurrent stock changes require reloading before saving. Commission changes affect future orders only. Deactivation/removal acts on the selected offer. Removing a shared master still hides every offer; restore the master before restoring a seller offer.

## Coordinated deployment

This is one release across isc-admin-api, isc-admin-ui, isc-multivendor-api, laravel-api and nuxt-ts-app. Do not deploy the migration with old cart/order/stock writers still running.

1. Review and build all five release revisions. Confirm the existing commission, temporary bulk-price, cart soft-delete and vendor-request migrations are present.
2. Rehearse against a restored SQL Server backup. Review orphan vendor IDs, duplicate live cart rows, old pending approved-update requests containing shared-content edits, and any unpublished catalogue edits. Older shared-content update requests must be reviewed separately and resubmitted as selling-field requests.
3. Enter maintenance on all three APIs and stop queues/scheduled jobs that write catalogue, cart, stock or orders. Take a verified full SQL Server backup, preserve current release revisions and uploaded-file references, and test the restoration procedure.
4. Deploy the three APIs and the two built frontends together. Run the admin migration `2026_09_19_100000_create_product_vendor_offers.php` once. It transactionally creates offers, copies existing vendor inventory/tiers, backfills references and replaces active-cart uniqueness with seller-aware filtered indexes. It does not merge/delete existing masters or rewrite historical order amounts.
5. Verify one backfilled offer per existing vendor-owned master, matching prices/stock/commission and tier counts, and cart/order references. Verify master counts and historical financial totals are unchanged. Existing duplicate masters are intentionally left for a separate reviewed consolidation.
6. Smoke-test two sellers of one master: approval, search prices, separate cart quantities, vendor order headers, commissions, stock changes, unpaid-payment cancellation, and return restocking. Check admin offer editing and vendor ownership restrictions. Restart queues only with the new release, clear application caches, then reopen traffic.

## Recovery

The migration refuses an automatic `down()`: once independent seller stock or orders exist, dropping the pivot would lose ownership information. While all writers remain paused, restore the verified coordinated database backup and all five previous application revisions. After reopening sales, reconcile new orders/payments before any restore; prefer a forward fix. Never roll back only one API or point old checkout code at the new data model.

## Validation

Run the admin `VendorOffersDatabaseTest`, customer `VendorOfferCheckoutTest`, vendor `VendorOfferIsolationTest`, their existing unit suites, storefront Node tests and all three frontend builds. The database tests use disposable fixtures; no shared feature-test database is required. The admin test can additionally target an isolated SQL Server container with `VENDOR_OFFERS_TEST_SQLSRV=1`; its database is fixed to `vendor_offer_isolation` on loopback and must be disposable. Browser checks should use fixture APIs and cover approval confirmation, seller selection, separate cart lines, offer-only vendor editing and mobile layouts.
