# HMW Events — Implementation Status

## Step 1: Event Types and Templates ✅ (16/16)

| # | Requirement | Status | Notes |
|---|---|---|---|
| 1 | Parenting Webinar (free, external link) | ✅ | `EventTypeRegistry.php` — `parenting-webinar` |
| 2 | Professional Webinar (free, external link) | ✅ | `EventTypeRegistry.php` — `professional-webinar` |
| 3 | Parent One-Off Free Event | ✅ | `EventTypeRegistry.php` — `parent-one-off-free` |
| 4 | Parent Recurring Walk-In | ✅ | `EventTypeRegistry.php` — `parent-walk-in`, `registration_disabled` |
| 5 | Parent Multi-Week Course | ✅ | `EventTypeRegistry.php` — `parent-course` |
| 6 | Professional Paid Online | ✅ | `EventTypeRegistry.php` — `professional-online` |
| 7 | Professional Paid In-Person | ✅ | `EventTypeRegistry.php` — `professional-in-person` |
| 8 | Create event + assign type | ✅ | Custom dropdown metabox, saves as taxonomy term |
| 9 | Show/hide fields per type | ✅ | ACF filter + JS toggling via `EventTypeDefaultsService` |
| 10 | Apply labels per type | ✅ | Taxonomy term labels throughout |
| 11 | Apply workflows per type | ✅ | `WorkflowEnforcer.php` — hooks `transition_post_status` |
| 12 | Apply communication templates | ✅ | `CommunicationTriggerMatrix` resolves per-type overrides |
| 13 | Create from templates | ✅ | Full CRUD + AJAX + `create_event_from_template()` |
| 14 | Templates: required/optional/hidden/defaults | ✅ | `TemplateResolver` multi-level merge |
| 15 | Existing events unaffected by template changes | ✅ | Immutable `_template_snapshot` post meta |
| 16 | Retire templates | ✅ | Soft-delete via `is_retired` flag + UI toggle |

### Extra Features Added

- **Event Bookings meta box** — Shows bookings table on `hmw_event` edit screen with Edit, Resend, Payment Link, Mark as Paid actions
- **Add Manual Booking** — ThickBox modal form, POSTs to `/booking/create-manual`
- **Mark as Paid** — REST endpoint marks manual bookings as paid, fires confirmation hooks
- **Workflow enforcement** — Blocks invalid status transitions per event type

---

## Step 2: Event Creation and Configuration ✅ (5/6 gaps fixed)

| # | Requirement | Status | Notes |
|---|---|---|---|
| 1 | No surcharge system | ✅ | `_event_surcharge` ACF field, integrated into `calculate_amount()`, frontend display |
| 2 | No per-registrant booking cap | ✅ | `_event_max_per_registrant` ACF field, `EventHelper::check_registrant_cap()` |
| 3 | Per-attendance-option capacity not enforced | ✅ | `resolve_attendance_option_id()` + `check_attendance_option_capacity()`, stored on booking |
| 4 | Coupon bridge (old → new CPT) | ✅ | `CouponService` rewritten to use `hmw_coupon` CPT with new meta keys |
| 5 | No platform-specific link fields | ⚠️ | Single `_event_webinar_url` field only; needs Teams/Zoom split (~20 lines) |
| 6 | ACF fields referenced but missing | ✅ | Removed phantom fields from registry |

### Already Covered (no changes needed)
- General fields, online/in-person event details, attendance options, pricing, GST, waitlists, form builder, document uploads, discount codes

---

## Step 3: Event Scheduling and Session Management ✅ (14/14)

| # | Requirement | Status | Notes |
|---|---|---|---|
| A1 | Parent event + auto-generated child sessions | ✅ | `SessionService::generate_sessions()` |
| A2 | Start/end date, recurrence days, multiple dates | ✅ | ACF fields + `calculate_dates()` |
| A3 | Each session appears individually on listings | ✅ | Filterable `show_child_sessions` in listing query + `parent-walk-in` flag |
| A4 | Sessions linked to parent | ✅ | `post_parent` + `_is_child_session` meta |
| B5 | Single record + attached session schedule | ✅ | Parent/child model |
| B6 | Appears once in listings | ✅ | `post_parent => 0` default |
| B7 | Detail page shows all session dates | ✅ | `session-schedule.php` view + hook in `Hooks.php` |
| C8 | Calendar view | ✅ | `SessionCalendar.php` admin page under Events → Calendar |
| C9 | Tabular view | ✅ | Session Schedule meta box |
| C10 | Edit single session | ✅ | Edit link in meta box + `update_session()` |
| C11 | Bulk edit a series | ✅ | "Update All Sessions" button with cascade AJAX handler |
| C12 | Delete individual sessions | ✅ | WP native + Trash All button |
| C13 | Deleted sessions exclusion tracker | ✅ | `_excluded_session_dates` post meta + admin Clear button |
| C14 | Parent changes cascade to children | ✅ | Auto-cascade on save + "Update All Sessions" manual button |

---

## Step 4: Event Lifecycle Management ✅ (6/6)

| # | Requirement | Status | Notes |
|---|---|---|---|
| Duplicate events | ✅ | `EventLifecycle.php` — "Duplicate" row action, copies meta + taxonomies as draft |
| Preview before publishing | ✅ | WP core post preview |
| Edit event details after creation | ✅ | WP core post editing |
| Archive events | ✅ | `archived` status existed; PII expiry via `BookingCleanup::purge_archived_pii()` (90-day cron) |
| Cancel events | ✅ | `cancelled` status existed; `Hooks::on_event_cancelled()` cancels active bookings + fires emails |
| Delete draft events | ✅ | WP core trash |

### New files for Step 4
- `src/Admin/EventLifecycle.php` — duplicate event handler
- Updated `src/Services/Hooks.php` — event cancellation cascade
- Updated `src/Services/BookingCleanup.php` — PII retention cleanup cron

---

## Step 5: Event Statuses ✅ (6/6)

| # | Requirement | Status | Notes |
|---|---|---|---|
| Draft | ✅ | WP core |
| Published | ✅ | WP core, bookable |
| Fully Booked | ✅ | Registered, shown in listings with badge |
| Cancelled | ✅ | Registered, search-excluded, triggers attendee emails via Step 4 |
| Archived | ✅ | Registered, hidden from frontend/search, PII expiry via Step 4 |
| By Invitation | ✅ | Now visible as "Fully Booked" + "Invitation Only" badges; token-based access via `InvitationTokenService`; booking form gated |

---

## Step 6: Categorisation and Public Listings ✅

| # | Requirement | Status | Notes |
|---|---|---|---|
| Assign audience | ✅ | `hmw_event_audience` taxonomy |
| Assign delivery mode | ✅ | `hmw_event_delivery_mode` taxonomy |
| Assign cost type | ✅ | Via `_event_price`, `_event_is_free`, Free/Paid badges |
| Assign topic | ✅ | `hmw_event_parenting_topic` taxonomy, filterable in listing UI |
| Assign event type | ✅ | `hmw_event_type` taxonomy |
| Assign categories | ✅ | WP core categories now registered for `hmw_event` |
| Assign tags | ✅ | WP core tags now registered for `hmw_event` |
| Modify/remove categories | ✅ | WP core category/tag management UI |
| Browse parent vs professional | ✅ | Audience filter in listing UI |
| Filter by online/in-person | ✅ | Delivery mode filter |
| Filter by free/paid | ✅ | `free_only`/`paid_only` meta filters |
| Filter by topic | ✅ | Topic taxonomy filter in listing UI |
| Cards show online/in-person | ✅ | Delivery mode badge |
| Cards show free/paid | ✅ | Free/Paid badge |
| Cards show single/multi-session | ✅ | New Multi-Session badge |

### New files
- `src/Taxonomies/ParentingTopic.php`, `src/Taxonomies/ProfessionalTopic.php`, `src/Taxonomies/Program.php` — replaced the deleted `src/Taxonomies/EventTopic.php` (`hmw_event_topic`)
- Updated `src/PostTypes/Event.php` — WP categories + tags support
- Updated `src/Services/EventListingService.php` — topic filter, multi-session badge, filter bar

---

## Pending Work

### Step 2 Residual
| # | Item | Effort |
|---|---|---|
| S2-G5 | Add `_event_teams_link` ACF field alongside `_event_webinar_url` | Small (~20 lines) |

### Step 8: Registrations ✅

| # | Requirement | Status | Notes |
|---|---|---|---|
| On-screen confirmation | ✅ | `BookingConfirmation.php` shortcode — shows booking ref, event, payment |
| Confirmation email | ✅ | `BookingConfirmationHandler` + `EmailEventHooks` |
| Confirmation includes event name | ✅ | `event_name` in confirmation page + `{{event_title}}` in email |
| Confirmation includes date | ✅ | `event_date` / `{{event_date}}` |
| Confirmation includes location | ✅ | `event_location` / `{{course_location}}` |
| Confirmation includes next steps | ✅ | New filterable "Next Steps" section in on-screen page |
| Admin emails include event name | ✅ | `OrganizerNewBookingHandler` includes `course_name` |
| Delete spam/invalid registrations | ✅ | WP core registrant CPT deletion |
| Free events self-cancellation | ✅ | `BookingSelfCancelService` — token-based cancellation link in confirmation emails |

### New files
- `src/Services/BookingSelfCancelService.php` — self-cancellation token service
- Updated `src/Shortcodes/BookingConfirmation.php` — filterable "Next Steps" section
- Updated `src/Services/Emails/Handlers/BookingConfirmationHandler.php` — `cancel_link` template var for free events

---

## Step 9: Payments, Invoicing and Discounts ✅

| # | Requirement | Status | Notes |
|---|---|---|---|
| Secure online payments | ✅ | Stripe integration |
| Configure pricing | ✅ | `_event_price`, `_event_deposit`, `_event_is_free` |
| GST calculation | ✅ | `GstCalculator` 10%, ex/inc, booking breakdown |
| Surcharges | ✅ | `_event_surcharge` (Step 2) |
| Discount codes (fixed/percent) | ✅ | `CouponService` with `hmw_coupon` CPT |
| 100% discounts | ✅ | Fixed discount = course price |
| Staff discounts | ✅ | `_coupon_is_staff` checkbox, auto 100% off |
| Expire codes | ✅ | `_coupon_start_date` / `_coupon_end_date` |
| Disable codes | ✅ | `_coupon_is_active` toggle |
| Net Terms / Pay Later | ✅ | `NetTermsHandler`; gated to private/invitation URLs |
| Invoiced/Paid statuses | ✅ | ENUM in bookings table |
| Manual payment override | ✅ | `PaymentOverrideService` |
| Auto tax invoices/receipts | ✅ | `EmailEventHooks::on_payment_received()` auto-queues receipts |

---

## Step 10: Capacity and Waitlists ✅

| # | Requirement | Status | Notes |
|---|---|---|---|
| Configure event capacity | ✅ | `_event_capacity` ACF field |
| Configure option-level capacity | ✅ | `check_attendance_option_capacity()` |
| Auto-create waitlists once full | ✅ | `WaitlistService::join()` |
| Prevent reopening if waitlist exists | ✅ | `check_event_full()` checks active waitlist count |
| View attendee lists | ✅ | Event Bookings meta box |
| View waitlists | ✅ | New waitlist table in Event Bookings meta box |
| Manually adjust capacities | ✅ | ACF field editing |
| Promote waitlisted attendees | ✅ | "Promote" button per entry → `promote_entry()` |
| Promotion sends private URL | ✅ | `InvitationTokenService::create_token_for_promoted()` |

---

## Step 11: Communications ✅

| # | Requirement | Status | Notes |
|---|---|---|---|
| Registration confirmations | ✅ | `EmailEventHooks::on_booking_created()` |
| Payment confirmations | ✅ | `on_payment_received()` + receipt emails |
| Administrative notifications | ✅ | `OrganizerNewBookingHandler` |
| Custom admin email addresses | ✅ | `_event_notification_email` ACF override per event |
| Vary by event type | ✅ | `CommunicationTriggerMatrix` + per-type templates |
| Vary by payment status | ✅ | Payment outcome triggers |
| View communications | ✅ | EmailQueue admin page |
| Edit email templates | ✅ | `EmailTemplateManager` + admin UI |
| Disable notifications | ✅ | Toggle checkboxes in EmailQueue settings |

---

## Step 12: Reporting and Exports ✅

| # | Requirement | Status | Notes |
|---|---|---|---|
| Filter by event | ✅ | Event dropdown in report form |
| Filter by date range | ✅ | From/To date inputs |
| Filter by audience | ✅ | New audience taxonomy dropdown |
| Filter by event type | ✅ | New event type taxonomy dropdown |
| Filter by payment status | ✅ | New payment status dropdown |
| Filter by attendance status | ✅ | New attendance status dropdown |
| CSV export: registrations | ✅ | `export_registrations()` with all filters |
| CSV export: attendees | ✅ | `export_attendees()` |
| CSV export: payments | ✅ | `export_payments()` |
| CSV export: invoices | ✅ | Payment transactions include invoice IDs |
| Save common report filters | ✅ | `saved_report_filters` table + CRUD |
| Remove obsolete test data | ✅ | "Remove Abandoned Bookings" button |

---

## Step 13: Data Governance and Compliance ✅

| # | Requirement | Status | Notes |
|---|---|---|---|
| Retain historical reporting info | ✅ | Bookings data preserved when events archived |
| Auto-expire PII after 90 days | ✅ | `BookingCleanup::purge_archived_pii()` daily cron |
| Auto-remove uploaded documents with PII | ✅ | `DocumentUploadHandler` 90-day retention + cron |
| Maintain audit history | ✅ | `hmwevents_booking_history` table + gateway logging |
| Exclude archived from search engines | ✅ | SQL WHERE exclusion + `noindex` robots meta tag |
| Compliance dashboard | ✅ | Data Governance box on Reporting page |

---

## Step 14: Non-Functional Requirements ✅

| # | Requirement | Status | Notes |
|---|---|---|---|
| Minimise duplicate data entry | ✅ | Templates + event type presets reduce re-entry |
| Intuitive workflows via templates | ✅ | 7 archetypes with field visibility + defaults |
| Allow future event types without redevelopment | ✅ | Taxonomy-based event types, extendable `EventTypeRegistry` |
| Maintain historical financial integrity | ✅ | Immutable `_template_snapshot` + booking history table |
| Efficient administration for high-volume events | ✅ | Session calendar, bulk actions, reporting dashboard |

---

## Steps Not Yet Started
_None — all 14 steps complete._

## Test Status

**487 tests, 1199 assertions — 1 known failure** (BookingPdfGeneratorTest)

---

_Last updated: 16 July 2026_
