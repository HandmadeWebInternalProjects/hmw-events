# HMWEvents Rebuild Plan (Clean-Slate, No Legacy Dead Code)

## Status
- Plan approved for implementation order.
- Strategy: **breaking-change clean rebuild** (remove old system artifacts, not preserve compatibility shims unless explicitly needed).

---

## Goals

1. Replace Calmbirth/educator-specific architecture with a generic event platform.
2. Keep multi-tenant support with Stripe fallback:
   - Test mode: global test keys only
   - Live mode: organizer keys first, fallback to global live keys
3. Start fresh with a new DB schema (`hmwevents_*` tables).
4. Remove dead legacy code, routes, roles, CPTs, taxonomies, and tools.
5. Implement required event lifecycle, scheduling, registration, payments, waitlist, comms, reporting, and compliance.

---

## Confirmed Product Decisions

- Multi-tenant capable: **Yes**
- Master Stripe fallback: **Yes**
- Fresh database tables: **Yes**
- Keep Event Organizer role: **Yes**
- Execution order: **Phase order from plan accepted**
- Legacy cleanup posture: **Aggressive cleanup (minimal dead code)**

---

## Cleanup Policy (Mandatory)

- No long-term dual system.
- Old code should be removed once replacement is live.
- No legacy compatibility wrappers unless a blocker is identified.
- Old database artifacts should be purged via explicit migration/purge step.

---

## High-Level Architecture (Target)

### Core Concepts
- Event (`hmw_event`) CPT
- Registrant (`hmw_registrant`) CPT
- Coupon (`hmw_coupon`) CPT
- Parent/child sessions for recurring/multi-session events
- Event Type Registry for archetype-driven behavior
- Event Templates for reusable defaults

### Core Taxonomies
- `hmw_event_type`
- `hmw_event_audience`
- `hmw_event_delivery_mode`
- `hmw_event_state`
- optional: topic/category/tag layers

### Event Statuses
- Draft
- Published
- Fully Booked
- Cancelled
- Archived
- By Invitation

---

## Database Plan (Fresh Schema)

## New Table Set (`hmwevents_*`)
- `hmwevents_event_recurrence`
- `hmwevents_booking_groups`
- `hmwevents_bookings`
- `hmwevents_booking_details`
- `hmwevents_booking_meta`
- `hmwevents_payment_transactions`
- `hmwevents_waitlist`
- `hmwevents_event_availability`
- `hmwevents_event_attendance_options` (new)
- `hmwevents_event_templates` (new)
- `hmwevents_registration_documents` (new)
- `hmwevents_private_registration_tokens` (new)
- `hmwevents_saved_report_filters` (new)
- `hmwevents_voucher_usage`
- `hmwevents_coupon_usage`
- `hmwevents_booking_history`
- `hmwevents_email_queue`
- `hmwevents_email_templates`
- `hmwevents_email_attachments`

### Key Enum/State Additions
- Booking status includes `waitlisted`
- Payment status includes `invoiced`
- Attendance status explicit (`registered`, `attended`, `no_show`)

### FK Strategy
- Keep explicit FK lifecycle management (create/drop/repair) but only for new table names.
- Add schema versioning and deterministic install/upgrade process.

---

## Implementation Phases

## Phase 1 — Foundation: DB + Install Pipeline
- Rewrite installation pipeline for only new schema.
- Add schema versioning and upgrade guards.
- Add optional explicit legacy purge routine.
- Define all new FKs and repair command.

**DoD**
- Fresh install creates only `hmwevents_*` tables.
- FK integrity checks pass.
- No old table references in install flow.

---

## Phase 2 — Domain Model: CPTs, Taxonomies, Statuses, Roles
- Introduce:
  - `hmw_event`, `hmw_registrant`, `hmw_coupon`
  - new taxonomies + statuses
  - `event_organizer` role/caps
- Remove old:
  - `educator_course`, `edu_customer`, old educator/hospital role behaviors

**DoD**
- Admin + Event Organizer can perform expected CRUD.
- Old CPT/role/taxonomy registrations removed.

---

## Phase 3 — Event Type Registry + Template Engine
- Build EventTypeRegistry (field visibility, requiredness, workflows, comm templates).
- Build Event Template CRUD and “create from template”.

**DoD**
- All 7 required archetypes represented.
- Template defaults apply only to new events.
- Template retirement works.

---

## Phase 4 — Registration + Form System
- Refactor BookingFields to support presets by event type/audience.
- Add parent/professional field variants.
- Add optional document upload fields + retention hooks.

**DoD**
- Configurable form behavior by event type.
- Presets can be saved and reused.
- Upload path secured and linked to retention policy.

---

## Phase 5 — Payments (Stripe Fallback + GST + Net Terms)
- Keep Stripe hierarchy (organizer keys -> global fallback in live mode).
- Add GST calculations (ex-GST input, GST breakdown output).
- Add Net Terms/Pay Later flow and `invoiced` status.
- Add finance manual override controls.

**DoD**
- Payment flow works for:
  - organizer key present
  - organizer key absent (global fallback)
  - zero-dollar discounts
  - invoiced/pay-later
- Invoice/receipt generation correct with GST lines.

---

## Phase 6 — Waitlist + By Invitation
- Implement operational waitlist (join/promote/convert/expire).
- Build private token flow for invitation-only registration.
- Ensure public “By Invitation” appears fully booked.

**DoD**
- Promotion sends private registration URL.
- Token is single-use + expiry-enforced.
- Waitlist logic blocks uncontrolled reopening.

---

## Phase 7 — Scheduling & Sessions
- Replace clone-style recurrence with parent/child sessions.
- Build list view first, then calendar + bulk editing.
- Support cascade to children with per-session override behavior.

**DoD**
- Recurring and multi-week models both supported per spec.
- Session edit/delete/regenerate behavior deterministic and auditable.

---

## Phase 8 — Communications
- Refactor email templates/triggers to event terminology.
- Add waitlist invitation + invoice templates.
- Keep queue/retry/dead-letter engine.

**DoD**
- Trigger matrix works by event type/payment outcome/status.
- Admin can edit templates and disable notifications.

---

## Phase 9 — Public Listings & Filtering
- Refactor front-end listing/search for:
  - parent vs professional views
  - online/in-person
  - free/paid
  - webinar/course/workshop/topic/audience

**DoD**
- Card/detail UI includes required badges/indicators.
- Filtering behavior matches requirements.

---

## Phase 10 — Reporting, Exports, Compliance
- Expand filters, saved views, and CSV exports.
- Implement 90-day retention for PII + document deletion.
- Preserve financial and historical reporting integrity.
- Add audit history for attendee removals/status overrides.

**DoD**
- Exports cover registrations/attendees/payments/invoices.
- Retention job verified with test data.
- Archived events are non-indexable.

---

## Legacy Removal Checklist (Hard Cleanup)

- Remove old routes (`hmwevents/v1`) after cutover.
- Remove old services/helpers tied to educator/hospital model.
- Remove legacy CLI commands not relevant to new architecture.
- Remove migration scripts for old Calmbirth payloads.
- Remove old options/meta keys (or map through a one-time cleanup command).
- Remove old Breakdance elements once replacements are live.
- Remove dead tests and add new suite coverage.

---

## Risk Controls

1. **State transition complexity**  
   Mitigation: explicit state machine + tests per transition.

2. **Recurring/cascade edge cases**  
   Mitigation: deterministic override rules + regeneration policy.

3. **Token security (invitation/self-cancel)**  
   Mitigation: signed, expiring, single-use tokens with audit logs.

4. **Data retention correctness**  
   Mitigation: dry-run mode + irreversible-action logs.

5. **Legacy residue**  
   Mitigation: final grep/static sweep + dead code PR gate.

---

## Acceptance Gates (Release Blockers)

- No references to old domain terms (`educator_`, old CPT/taxonomy slugs) in active runtime code.
- End-to-end flows pass:
  - create event
  - publish/list/filter
  - register/pay/invoice
  - waitlist/invite
  - send comms
  - export reports
- Compliance job verified (PII/document expiry).
- Stripe fallback verified in live mode with and without organizer keys.

---

## Suggested Working Branch Strategy

- `phase/01-db-foundation`
- `phase/02-domain-model`
- `phase/03-event-types-templates`
- `phase/04-registration-forms`
- `phase/05-payments-gst-invoicing`
- `phase/06-waitlist-invitations`
- `phase/07-scheduling-sessions`
- `phase/08-comms`
- `phase/09-listings`
- `phase/10-reporting-compliance`
- `cleanup/remove-legacy-system`

---

## Immediate Next Step

Start with **Phase 1 implementation ticket breakdown**:
1. New schema SQL definitions
2. Install/upgrade entrypoints
3. FK definitions + repair command
4. Optional legacy purge command
5. Schema smoke tests