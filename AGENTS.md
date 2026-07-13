# HMW Events — Agent Guide

A WordPress plugin for event management, booking, and registration. Built by
Handmade Web & Design. Targets PHP 8.0+ and WordPress 6.3+.

## Quick orientation

| What | Where |
|---|---|
| Plugin bootstrap | `hmw-events.php` |
| Main singleton class | `src/HMWEvents.php` |
| Component registry | `HMWEvents::get_components()` in `src/HMWEvents.php` |
| Component loader trait | `src/Traits/HasComponents.php` |
| Database schema | `includes/schema.php` |
| Install / activation | `includes/install.php` |
| ACF field groups (JSON) | `acf-json/` |
| Services (business logic) | `src/Services/` |
| Post types | `src/PostTypes/` |
| Taxonomies | `src/Taxonomies/` |
| Admin handlers | `src/Admin/` |
| REST API | `src/Api/` |
| Helpers | `src/Helpers/`, `src/Helpers.php` |
| Frontend shortcodes | `src/Shortcodes/` |
| Views | `src/views/` |
| Tests | `tests/` (Unit + Integration) |

## Directory structure

```
src/
├── Admin/                 # Admin screen handlers (RecurringEventHandler, Reporting, etc.)
├── Api/                   # REST API (Routes, StripeWebhook, ApiHelper)
│   └── Routes/            # Individual route classes
├── Breakdance/            # Breakdance page builder integration (elements, macros, presets)
├── CLI/                   # WP-CLI commands (RepairForeignKeys, ImportEventVenue)
├── Config/                # Configuration classes (BookingFields)
├── Factory/               # Factory patterns
├── Helpers/               # Helper classes (RecurringEvent, EventHelper, Encryption, Booking)
├── Interfaces/            # PHP interfaces
├── Meta/                  # Post meta compatibility shim (CourseMeta)
├── Middleware/            # Middleware patterns
├── PostTypes/             # CPT registrations (Event, Registrant, Coupon)
├── Providers/             # Service provider patterns
├── Registry/              # Config registries (EventTypeRegistry, RegistrationFieldRegistry)
├── Roles/                 # Custom user roles (EventOrganizerRole)
├── Services/              # Business logic services (35+ classes)
│   ├── Emails/            # Email system (queue, templates, handlers, dispatch)
│   │   └── Handlers/      # Per-event email handlers
│   ├── Gateways/          # Payment gateway implementations (Stripe, Manual)
│   └── Mailing/           # CRM/Mailing integrations
├── Shortcodes/            # Frontend shortcode handlers (BookingForm, BookingConfirmation)
├── Taxonomies/            # Custom taxonomy registrations
├── Traits/                # Shared traits (HasComponents, MakesHttpRequests)
├── Utils/                 # Utility classes
└── views/                 # PHP view templates
```

## Architecture

### Component system

Every major subsystem is a class with a `register()` method. They are
registered in `HMWEvents::get_components()` and instantiated by the
`HasComponents` trait (`src/Traits/HasComponents.php`), which calls
`->register()` on each. A component can also have a static `init()` method,
which the trait calls directly without instantiation.

To add a new service, create a class in `src/Services/`, give it a
`register()` method that adds its WordPress hooks, and add the FQCN to the
array in `get_components()`.

### Namespaces

PSR-4 with the `HMWEvents\` prefix mapped to `src/`. Examples:
- `src/Services/SessionService.php` → `HMWEvents\Services\SessionService`
- `src/Admin/RecurringEventHandler.php` → `HMWEvents\Admin\RecurringEventHandler`

Test namespaces: `HMWEvents\Tests\Unit` and `HMWEvents\Tests\Integration`.

### Database

Custom tables use the `hmwevents_` prefix (e.g. `wp_hmwevents_bookings`).
Schema is defined in `includes/schema.php` and created on activation via
`dbDelta()` in `includes/install.php`. Foreign keys are managed separately
through `hmwevents_ensure_foreign_keys()`.

**Always use `DatabaseService::get_table_name('bookings')` to resolve a
table name.** Never hardcode table names.

There are 19 custom tables spanning event recurrence, bookings, payments
(Stripe), availability caches, waitlists, email queues, templates, and
more. See `includes/schema.php` for the full schema.

### ACF

ACF field groups are stored as JSON in `acf-json/`. The save/load paths are
set in `src/Services/ACF.php`. Field keys use the `field_*` convention;
field names are prefixed with an underscore (e.g. `_event_start_date`).

**Field groups in `acf-json/`:**

| File | Title | Post type / location |
|---|---|---|
| `group_hmw_event_details.json` | Event Details | `hmw_event` |
| `group_hmw_registrant_details.json` | Registrant Details | `hmw_registrant` |
| `group_67bbf23362437.json` | Global Settings | Options page |
| `group_69897a1809a0a.json` | Birth Stories Posts | `post` (category: birth-stories) |

The Event Details group contains 20 fields including the new recurrence
fields (see below). The Registrant Details group has 9 fields.

**ACF field naming rule:** For `hmw_event` posts, field names are prefixed
`_event_` (e.g. `_event_start_date`). For legacy `educator_course` posts,
field names use the `course_` prefix (e.g. `course_start_date`).

The `RecurringEventHandler` class has private `get_field()` and
`update_field()` methods that translate between these prefixes. Internal
methods should use those, not the raw ACF functions, when field names might
differ between post types.

### Post types

| Post type | Purpose | Key class |
|---|---|---|
| `hmw_event` | Core event CPT (replaces legacy `educator_course`) | `src/PostTypes/Event.php` |
| `hmw_registrant` | A booking/registrant record | `src/PostTypes/Registrant.php` |
| `hmw_coupon` | Coupon codes | `src/PostTypes/Coupon.php` |

### Custom post statuses (hmw_event only)

| Status | Public | Searchable | Behaviour |
|---|---|---|---|
| `draft` | — | — | Standard WP draft |
| `publish` | ✓ | ✓ | Live, bookable |
| `fully_booked` | ✓ | ✓ | All spots filled |
| `cancelled` | ✓ | ✗ | No longer running |
| `archived` | ✗ | ✗ | Hidden from frontend |
| `by_invitation` | ✗ | ✗ | Private/invite-only |

### Taxonomies

| Taxonomy | Purpose |
|---|---|
| `hmw_event_type` | Event category/type |
| `hmw_event_audience` | Target audience |
| `hmw_event_delivery_mode` | In-person vs online (webinar) |
| `hmw_event_state` | Australian state |

### Recurring events

Two systems coexist:

**Pattern-based** (daily/weekly/monthly):
- `SessionService::calculate_dates()` computes dates from config
- `generate_sessions()` creates child posts with `post_parent` set
- Config stored in `hmwevents_event_recurrence` table
- `regenerate_sessions()` handles re-cloning; preserves sessions with
  per-field overrides

**Custom dates** (specific dates):
- `RecurringEventHandler::handle_course_cloning()` creates standalone
  clone posts from a repeater field
- Clones are independent posts (no `post_parent`), always created as drafts
- Each clone gets its own availability row and is marked non-recurring

**Entry point:** `RecurringEventHandler` hooks `acf/save_post` at priority
20. It reads `_event_recurrence_unit` and dispatches to the appropriate
system. After creating children, it sets `_clones_created` on the parent
and disables the `_event_is_recurring` toggle to prevent re-triggering.
Re-enabling the toggle triggers regeneration.

**New recurrence ACF fields** (all conditional on `_event_is_recurring == 1`):

| Field name | Label | Type | Visible when |
|---|---|---|---|
| `_event_recurrence_interval` | Repeat every | number (min 1) | Always |
| `_event_recurrence_unit` | Repeat unit | select (daily/weekly/monthly/custom) | Always |
| `_event_recurrence_days` | On | checkbox (Mon–Sun) | unit = weekly |
| `_event_recurrence_end_type` | Ends | select (never/date/count) | unit ≠ custom |
| `_event_recurrence_end_date` | End date | date_picker | end_type = date |
| `_event_recurrence_max_occurrences` | After occurrences | number | end_type = count |
| `_event_recurrence_custom_dates` | Specific Dates | repeater {date} | unit = custom |

**Never extend the legacy `RecurringEvent` helper**
(`src/Helpers/RecurringEvent.php`) — it references the old
`educator_course_recurrence` table. Use `SessionService` for all new
recurrence work.

### Payments

Stripe is the primary payment gateway via:
- `src/Services/StripeService.php` — Stripe API client wrapper
- `src/Services/Gateways/StripePaymentGateway.php` — gateway adapter
- `src/Api/StripeWebhook.php` — webhook endpoint handler
- `src/Services/PaymentService.php` — orchestration layer

Other payment features:
- Net terms / pay-later: `src/Services/NetTermsHandler.php`
- Payment recovery / resume: `src/Shortcodes/ResumePayment.php`
- Payment overrides (admin): `src/Services/PaymentOverrideService.php`
- Manual booking gateway: `src/Services/Gateways/ManualBookingGateway.php`
- Vouchers: `src/Services/VoucherService.php`
- Coupons: `src/Services/CouponService.php`
- GST calculation: `src/Services/GstCalculator.php`

### REST API

All routes under `hmwevents/v1`. Defined in classes under `src/Api/Routes/`:

| Route class | Endpoints |
|---|---|
| `ProcessPayment.php` | `/payment/process`, `/payment/create`, `/payment/confirm`, `/payment/status/{id}`, `/payment/resume/{token}`, `/payment/gateway-config`, `/payment/verify-amount`, `/voucher/validate`, `/coupon/validate` |
| `BookingActions.php` | `/booking/transfer`, `/booking/resend-confirmation`, `/booking/resend-receipt`, `/booking/create-manual`, `/booking/update`, `/booking/send-payment-link` |
| `Events.php` | `/courses/educator/{educator_id}`, `/educators/search` |

Routes are registered in `src/Api/RegisterRoutes.php`. The webhook endpoint
(live mode) and test webhook endpoint are in `src/Api/StripeWebhook.php`.

### Email system

`src/Services/Emails/` handles all outgoing communication. Email templates
are stored in the `hmwevents_email_templates` table, queued to
`hmwevents_email_queue`, and dispatched by `EmailQueueProcessor`.

Core services:
- `EmailQueueProcessor` — processes the `hmwevents_email_queue` table
- `EmailEventHooks` — fires emails based on plugin events
- `EmailTemplateManager` — manages templates in `hmwevents_email_templates`
- `EmailDispatchService` — sends via WordPress or external SMTP

Email handlers in `src/Services/Emails/Handlers/`:
- `BookingConfirmationHandler.php`
- `OrganizerNewBookingHandler.php`
- `StatusChangeHandler.php`
- `ReminderHandler.php`
- `PaymentLinkHandler.php`
- `PostEventHandler.php`

All handlers extend `AbstractEmailHandler.php`.

### Event templates

`EventTemplateService` (`src/Services/EventTemplateService.php`) manages
reusable event templates stored in `hmwevents_event_templates`. Templates
hold default config (fields, attendance options, ACF data) that gets
applied when creating a new event via the AJAX endpoint
`hmwevents_create_event_from_template`. Templates are resolved by
`TemplateResolver` and validated by `TemplateSchemaValidator`.

### CLI commands

| Command | File |
|---|---|
| `wp hmw import-event-venue` | `src/CLI/ImportEventVenueCommand.php` |
| `wp hmw repair-foreign-keys` | `src/CLI/RepairForeignKeysCommand.php` |

### Registries

`src/Registry/` contains configuration registry classes:
- `EventTypeRegistry` — maps event type slugs to field visibility configs
  (which ACF fields to hide/require per event type)
- `RegistrationFieldRegistry` — registration form field definitions
- `CommunicationTriggerMatrix` — maps events to email triggers

### Custom user roles

`src/Roles/EventOrganizerRole.php` — the `event_organizer` role with custom
capabilities scoped to `hmw_event` and `hmw_registrant` CPTs.

## Code conventions

- **No comments in source code** unless explicitly requested. The codebase
  convention is self-documenting code through clear method and variable
  names. Existing legacy comments are being phased out — do not add new
  ones.
- `defined('ABSPATH') || die('Don\'t run this file directly!');` at the
  top of every PHP file in `src/` and `includes/`.
- WordPress coding standards (configured in `phpcs.xml`). Text domain is
  `hmw-events`. Use `/* translators: */` comments for placeholder strings
  passed through `_n()`, `sprintf()`, etc. (this is an exception to the
  no-comments rule — it's a WordPress i18n requirement).
- Constructor injection is minimal; most services are instantiated directly
  by the component loader. If a service needs another service, prefer a
  lazy-loaded private property with a getter:
  ```php
  private ?SessionService $session_service = null;
  private function session_service(): SessionService {
      if ($this->session_service === null) {
          $this->session_service = new SessionService();
      }
      return $this->session_service;
  }
  ```
- ACF field reads: use `get_field()` when ACF is active. For `hmw_event`
  posts, field names are prefixed with `_event_`. For legacy
  `educator_course` posts, field names use the `course_` prefix. If a
  method needs to work with both, use the `RecurringEventHandler`'s
  private helper methods as a pattern.
- Database access: always `$wpdb->prepare()` with correct placeholder
  types (`%s`, `%d`, `%f`). Never concatenate user input into SQL strings.
- Use `DatabaseService::get_table_name('table_name')` to resolve table
  names. Never hardcode `wp_hmwevents_` prefixes.
- PHP 8.0+ features are available: typed properties, constructor property
  promotion, `match` expressions, named arguments, union types, `nullsafe`
  operator, `str_starts_with()` / `str_contains()`.
- Post type constants: `Event::POST_TYPE` (`'hmw_event'`),
  `Registrant::POST_TYPE` (`'hmw_registrant'`),
  `Coupon::POST_TYPE` (`'hmw_coupon'`).

## Testing

### Run tests

```bash
composer install --dev              # first time only
./vendor/bin/phpunit                # all tests
./vendor/bin/phpunit tests/Unit/    # unit tests only
./vendor/bin/phpunit --filter Foo   # specific test
```

### Test stack

- **PHPUnit 9.6** (`phpunit.xml`)
- **Brain Monkey 2.6+** — mocking WordPress functions
- **Mockery 1.4+** — object mocking with behavioural expectations
- **Patchwork** — redefining built-in PHP functions at runtime

### Test structure

```
tests/
├── bootstrap.php              # Loads autoloader + WP stubs + Monkey mocks
├── stubs/class-wp-stubs.php   # Minimal WP_Error, WP_REST_Response stubs
├── Unit/                      # Unit tests (no DB, mocked WP functions)
└── Integration/               # Integration tests (currently sparse)
```

### Test patterns

- Test classes live in `HMWEvents\Tests\Unit` or `HMWEvents\Tests\Integration`.
- Each test class extends `PHPUnit\Framework\TestCase`.
- `setUp()` calls `Monkey\setUp()` and stubs common WP functions. See
  `tests/Unit/WaitlistAndSessionsTest.php` for the canonical example.
- `tearDown()` calls `Monkey\tearDown()`. If Mockery was used, also call
  `Mockery::close()`.
- Mock WP functions with `\Brain\Monkey\Functions\when('func')->justReturn()`
  or `->alias()`.
- Mock `DatabaseService::get_table_name()` in `setUp()` for tests that hit
  services that use DB tables:
  ```php
  \Brain\Monkey\Functions\when('HMWEvents\\Services\\DatabaseService::get_table_name')
      ->alias(function ($name) { return 'wp_hmwevents_' . $name; });
  ```
- Define `HMWEvents_ABSPATH` in `setUp()` if not already defined.
- Set `$GLOBALS['wpdb']` to a stub object when testing DB-dependent code.

### Known failing test

`tests/Unit/Services/BookingPdfGeneratorTest.php` has one pre-existing
failure unrelated to most work. Don't be alarmed if it shows up red. Do
not attempt to fix it unless specifically asked.

## Build / assets

- Frontend assets: `resources/` with Vite (`vite.config.js`), built to `dist/`
- Composer autoloader is required: `composer install`
- Node dependencies: `pnpm install`

## V3 Form Builder System

The v3 form builder replaces the old hardcoded registration field system with
user-defined sections, custom fields, and multi-attendee booking. It's a three-layer
system: admin template editor → template storage → frontend form rendering + payment.

### Data model (schema v3)

Stored in the `template_data` JSON column of `hmwevents_event_templates` and in the
`_event_field_config` post meta on `hmw_event` posts:

```json
{
  "schema_version": 3,
  "registration_fields": {
    "sections": [
      {
        "id": "sec_abc123",
        "label": "Contact Details",
        "fields": [
          {
            "key": "first_name",
            "label": "First Name",
            "type": "text",
            "required": true,
            "placeholder": "",
            "width": "half",
            "source": "registrant_meta",
            "meta_key": "registrant_first_name",
            "preset": true,
            "per_attendee": true,
            "options": []
          }
        ]
      }
    ],
    "multi_booking": {
      "enabled": true,
      "min": 1,
      "max": 10
    }
  }
}
```

Allowed field types: `text, email, tel, textarea, select, checkbox, radio, date, number, file`.

**Field sources:**
- `registrant_meta` — stored as `wp_postmeta` on `hmw_registrant` posts (presets only: first_name, last_name, email, phone)
- `booking_details` — stored as JSON in the booking record (all custom fields)

**Field card data attributes** (for the template editor JS):
- `data-source`, `data-meta-key`, `data-preset` — set when using Quick Presets in the modal

### Key files

| File | Purpose |
|---|---|
| `src/Services/FormSubmissionService.php` | Validates v3 form POST data, separates source fields, creates registrants, initiates payment. Entry methods: `submit_and_pay()` (PHP AJAX) and `handle_v3_submission()` (REST). |
| `src/Services/RegistrationFormRenderer.php` | Renders frontend form from v3 sections config. Methods: `render_form()`, `render_v3_form()`, `render_field_v3()`. New shortcode `[hmw_registration_form]`. |
| `src/Services/TemplateSchemaValidator.php` | `SCHEMA_VERSION=3`. Validates sections/fields/multi_booking structure. Auto-migrates v2→v3. |
| `src/Services/TemplateResolver.php` | Emits v3 sections+multi_booking in resolved template output. |
| `src/Registry/RegistrationFieldRegistry.php` | `presets()` method returns 4 system fields (first_name, last_name, email, phone) with source/meta_key mappings. |
| `assets/js/v3-booking.js` | Frontend JS for v3 form: Stripe Elements, multi-attendee add/remove, AJAX submission. |
| `assets/css/v3-booking.css` | Modern CSS with custom properties, CSS nesting, logical properties, grid layout. |
| `resources/admin/js/event-templates.js` | Admin form builder UI: sections management, drag-to-split, ThickBox field settings modal, quick presets. |

### Post meta keys on hmw_event

| Key | Contents | Set by |
|---|---|---|
| `_event_field_config` | Full `registration_fields` + `event_fields` config from template | `EventTemplateService::persist_event_snapshot()` |
| `_event_template_override` | Per-event overrides for field visibility/config | `EventTemplateOverrideService::save_override()` |
| `_event_template_override_apply_to_children` | bool — cascade override to child sessions | Same |
| `_created_from_template_id` | Template ID the event was created from | `EventTemplateService` |
| `_template_snapshot` | Full resolved payload at creation time | `EventTemplateService` |

### Form submission pipeline

```
Frontend form → v3-booking.js → admin-ajax.php (action: hmwevents_submit_registration)
                                    ↓
                        RegistrationFormRenderer::handle_submission()
                                    ↓ (v3 detected)
                        FormSubmissionService::submit_and_pay($form_data, $event_id)
                                    ↓
                        1. normalize_from_post() — parses attendees[] array
                        2. validate_submission() — per-field type/required checks
                        3. create_registrant() — one hmw_registrant CPT per attendee
                        4. save_registrant_meta() — preset fields written to registrant postmeta
                        5. extract_booking_details() — custom fields → booking_details JSON
                        6. PaymentGateway::process_new_booking()
                                    ↓
                        Returns {booking_id, registrant_ids, total, client_secret}
```

### Multi-booking UX

- Main form fields render once for attendee 0 (the primary registrant)
- "+ Add Attendee" button clones per-attendee fields from a `<template>` element
- `__INDEX__` placeholder in the template is replaced with the attendee index
- Remove button deletes block and re-indexes remaining attendees
- Hidden `attendee_count` input is updated by JS on each add/remove
- Total = unit_price × attendee_count, updated dynamically via JS

### Shortcodes

| Shortcode | Purpose | v3 support |
|---|---|---|
| `[hmwevents_booking_form event_id="123"]` | Main booking form (legacy name). Auto-detects v3 config and delegates to `RegistrationFormRenderer::render_v3_form()`. | Auto-detect |
| `[hmw_registration_form event_id="123"]` | Pure v3 renderer. Same output but requires v3 config. | Always v3 |

### REST API

| Endpoint | Method | Purpose |
|---|---|---|
| `/hmwevents/v1/registration/v3-submit` | POST | JSON-based v3 form submission with multi-attendee support |

### Per-event template overrides

The "Template Override" meta box on the `hmw_event` edit screen allows overriding
the field config for a single event (and its recurring children). Changes are stored
in `_event_template_override` and take priority over `_event_field_config`.

Override cascade: when the parent event has `_event_template_override_apply_to_children`
set to true, child sessions inherit the override automatically (both existing children
and future generated sessions).

### Theme / Stripe config

Stripe keys are stored via the Exopite Simple Options Framework under a single
`hmw-events` WP option with an `en` key nesting. Always use `ConfigHelper::get_option('key_name')`
or `StripeHelper::get_publishable_key()` to read them. Never use `get_option()` directly.

### CSS architecture

The v3 booking form uses `assets/css/v3-booking.css` with:
- CSS custom properties via `:root` — 20+ variables for colors, spacing, fonts
- Native CSS nesting (`& label`, `&:hover`)
- Logical properties (`inline-size`, `margin-inline`)
- 2-column CSS grid for field layout
- Responsive: single column at 600px breakpoint

## Things to watch out for

- The `educator_course` post type is **legacy**. New code should target
  `hmw_event`. The `RecurringEventHandler` supports both but pattern-based
  recurrence only works with `hmw_event`.
- `RecurringEvent` helper (`src/Helpers/RecurringEvent.php`) is legacy code
  that references the old `educator_course_recurrence` table. Don't extend
  it for new features — use `SessionService` instead.
- The `RecurringEventHandler` has private `get_field()` and `update_field()`
  methods that map `course_*` → `_event_*` field names. Internal methods
  should use these helpers, not the raw ACF functions, to correctly handle
  both post types.
- `src/Helpers/Course.php` and `src/Meta/CourseMeta.php` are legacy
  compatibility shims. Don't add logic to them.
- The `tests/README.md` is outdated (references a different project and
  incorrect test counts). Trust the actual test files, not that README.
- `run-tests.sh` references the wrong plugin name — use
  `vendor/bin/phpunit` directly.
- There is no `phpcs` binary in the project's vendor directory. Use
  `php -l` for syntax validation instead.
