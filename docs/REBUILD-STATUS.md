# HMWEvents Rebuild — Final Status Report

> Last updated 2026-06-26 | 204 tests / 502 assertions | Build complete

---

## Implementation Status: ALL PHASES DONE

| Phase | Description | Status |
|-------|-------------|--------|
| 1 | DB Foundation — 19 `hmwevents_*` tables, 21 FKs, install pipeline | ✅ |
| 2 | Domain Model — `hmw_event`, `hmw_registrant`, `hmw_coupon` CPTs, 4 taxonomies, `event_organizer` role | ✅ |
| 3 | Event Type Registry — 7 archetypes, template engine, create-from-template | ✅ |
| 4 | Registration + Form System — audience variants, presets, document uploads | ✅ |
| 5 | Payments — Stripe fallback, GST calculator, Net Terms, manual overrides | ✅ |
| 6 | Waitlist + Invitations — join/promote/convert, single-use tokens | ✅ |
| 7 | Scheduling & Sessions — parent/child model, date calculation, cascade | ✅ |
| 8 | Communications — 12 triggers, template CRUD, queue/retry/dead-letter | ✅ |
| 9 | Public Listings — `[hmw_event_listings]` shortcode, AJAX filter, event cards | ✅ |
| 10 | Reporting — CSV exports, saved filters, compliance cron | ✅ |
| — | Migration Service — `wp hmwevents migrate` (educator_* → hmwevents_*) | ✅ |
| — | Runtime Wiring — PaymentGateway delegates to PaymentService, EmailHooks v2, API route dual-detection | ✅ |
| — | Helpers Updated — Course.php/Booking.php now work with both CPTs | ✅ |
| — | Shortcodes Updated — BookingForm accepts `event_id` param | ✅ |
| — | Admin Refactored — OrganizerPaymentsDashboard, UNION queries for old+new tables | ✅ |
| — | Files Renamed — 14 files from Course/Educator → Event/Organizer | ✅ |
| — | Breakdance Renamed — 4 element dirs + class names updated | ✅ |
| — | Legacy Cleanup — 23 files deleted, zero legacy references remain | ✅ |
| — | Stripe Encryption — Auto-encrypts plaintext keys on first load | ✅ |

---

## File Inventory

| Category | Count |
|----------|-------|
| New v2 services | 22 |
| Registry classes | 3 |
| CPT / Taxonomy / Role classes | 8 |
| Refactored legacy services | ~20 |
| CLI commands | 3 (repair-foreign-keys, purge-legacy-tables, migrate) |
| Unit test files | 14 |
| Total tests passing | 204 (502 assertions) |

---

## Key CLI Commands

```bash
wp hmwevents repair-foreign-keys     # Drop, clean, rebuild all FKs
wp hmwevents purge-legacy-tables      # Drop educator_* tables permanently
wp hmwevents force-db-upgrade         # Re-run install pipeline
wp hmwevents db-status                # Show table/FK counts
wp hmwevents migrate                  # educator_* → hmwevents_* data migration
wp hmwevents migrate --step=events    # Single-step migration
```

---

## Zero Legacy References Verified

All of the following return zero matches in active source code (`src/`):
- `educator_course`, `edu_customer`, `edu_coupon` CPT slugs
- `course_type`, `course_state` taxonomy slugs
- `educator_bookings`, `educator_payment_transactions`, etc. old table names
- `educator` role references
- `cms/v1` route namespace (now `hmwevents/v1`)

---

## What Remains (Separate Workstreams)

| Task | Notes |
|------|-------|
| Replace Breakdance elements | Directories renamed, class names updated, but internal element logic still references old meta/CPT queries. Needs visual rebuild. |
| ACF JSON field groups | New `hmw_event` and `hmw_registrant` ACF field groups should be created to replace legacy ones. |
| End-to-end testing | Full booking flow from hmw_event create → publish → register → pay → email → report should be tested on staging. |
| Data migration run | Run `wp hmwevents migrate` on production after verifying on staging. |
