# Event Templates (Current Implementation)

This document explains how the event template system works today in the rebuilt HMW Events plugin.

## What templates are

Templates are reusable blueprints for creating `hmw_event` posts.

Each template stores:

- Event type (`event_type_slug`)
- Template payload (`template_data`, JSON)
- Status flags (`is_active`, `is_retired`)
- Version info inside template payload (`template_version`)

Templates are global for admins (not owner-scoped).

## Where templates are stored

- Table: `hmwevents_event_templates`
- Schema source: `includes/schema.php`
- Service: `src/Services/EventTemplateService.php`

## Core services

- `src/Services/TemplateSchemaValidator.php`
  - Validates and normalizes incoming template JSON
  - Supports legacy payload shapes and converts to canonical shape
- `src/Services/TemplateResolver.php`
  - Resolves registry defaults + template defaults + overrides into one final payload
- `src/Services/EventTemplateService.php`
  - CRUD, create event from template, re-apply template to existing events, backfill snapshots

## Admin UI

Page: `wp-admin/admin.php?page=hmwevents-event-templates`

Implemented in:

- `src/Admin/EventTemplates.php`
- Menu wiring in `src/Admin/Admin.php`

The page currently supports:

- List templates
- Create template
- Edit template JSON
- Retire/unretire template
- Create a draft event from a template
- Re-apply template to an existing event (selective sections)

## Canonical template JSON shape

`template_data` is normalized to this shape:

```json
{
  "schema_version": 1,
  "template_version": 1,
  "post": {
    "title_pattern": "",
    "post_content": "",
    "post_title": ""
  },
  "event_fields": {
    "required": ["event_start_date", "event_end_date"],
    "optional": [],
    "hidden": []
  },
  "registration_fields": {
    "required": ["first_name", "last_name", "email", "phone"],
    "optional": [],
    "hidden": []
  },
  "defaults": {
    "event_meta": {
      "event_capacity": 20
    },
    "registration": {
      "attendance_default": "individual",
      "field_overrides": {
        "phone": {
          "label": "Mobile Number",
          "placeholder": "04xx xxx xxx"
        }
      }
    },
    "attendance_options": [
      {
        "option_type": "individual",
        "label": "Individual",
        "price": 0
      }
    ]
  }
}
```

## Resolution rules

When creating an event from template, final values resolve in this order:

1. Event type defaults from `EventTypeRegistry`
2. Template defaults from `template_data`
3. Explicit create-time overrides

Conflict rules:

- `hidden` wins over required/optional
- required wins over optional

## Immutable snapshot behavior

On event creation, resolved data is snapshotted to event meta:

- `_created_from_template_id`
- `_created_from_template_version`
- `_template_snapshot`
- `_event_field_config`
- `_event_default_values`

This protects existing events from future template edits.

Registration rendering uses event snapshot first when present (`RegistrationFormRenderer`).

## Template versioning

On template update:

- If `template_version` is not provided, it is automatically incremented
- Existing events keep their original snapshot/version
- New events created afterward use the newer version

## Re-apply template to existing event

Manual re-apply is supported via admin page and service.

Sections can be selectively applied:

- `event_meta`
- `field_config`
- `defaults`
- `attendance_options`

Re-apply is explicit. There is no automatic sync on template edits.

## CLI: backfill missing snapshots

For events that were created from templates before snapshot support, use:

```bash
wp hmwevents backfill-template-snapshots
```

Options:

```bash
wp hmwevents backfill-template-snapshots --batch=100 --max-batches=1000
```

Implemented in `src/CLI/RepairForeignKeysCommand.php` and `EventTemplateService::backfill_template_snapshots()`.

## Legacy template payload support

Older payloads like this are still accepted:

```json
{
  "post_title": "Legacy Template",
  "post_content": "Intro content",
  "meta": {
    "event_capacity": 40
  },
  "event_delivery_mode": "online"
}
```

They are normalized to the canonical schema before save/use.

## Current limitations and next improvements

- Re-apply currently executes directly (no preview diff UI yet)
- Admin UI is JSON-first (no visual schema form builder yet)
- No template revision history table yet (only version in payload)

## Quick examples

### Example 1: Parent education template

```json
{
  "schema_version": 1,
  "template_version": 1,
  "event_fields": {
    "required": ["event_start_date", "event_end_date", "event_venue_address"],
    "optional": ["event_capacity"],
    "hidden": ["event_webinar_url"]
  },
  "registration_fields": {
    "required": ["first_name", "last_name", "email", "phone", "due_date"],
    "optional": ["partner_name"],
    "hidden": []
  },
  "defaults": {
    "event_meta": {
      "event_capacity": 24,
      "event_delivery_mode": "in_person"
    },
    "registration": {
      "attendance_default": "parent",
      "field_overrides": {}
    },
    "attendance_options": [
      {"option_type": "parent", "label": "Parent", "price": 50},
      {"option_type": "couple", "label": "Couple", "price": 90}
    ]
  }
}
```

### Example 2: Webinar template

```json
{
  "schema_version": 1,
  "template_version": 1,
  "event_fields": {
    "required": ["event_start_date", "event_end_date", "event_webinar_url"],
    "optional": ["event_capacity"],
    "hidden": ["event_venue_name", "event_venue_address"]
  },
  "registration_fields": {
    "required": ["first_name", "last_name", "email", "phone"],
    "optional": [],
    "hidden": []
  },
  "defaults": {
    "event_meta": {
      "event_delivery_mode": "online",
      "event_capacity": 500
    },
    "registration": {
      "attendance_default": "individual",
      "field_overrides": {}
    },
    "attendance_options": [
      {"option_type": "individual", "label": "Individual", "price": 0}
    ]
  }
}
```
