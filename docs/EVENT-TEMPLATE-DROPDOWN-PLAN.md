# Event Template Dropdown Plan

**Status:** Draft  
**Date:** 2026-07-10  
**Estimated effort:** 4-5 hours

---

## Goal

Decouple ACF field visibility from the `hmw_event_type` taxonomy. Instead, make named "Event Templates" the primary driver of which fields are shown/hidden/required on the event edit screen. Event type reverts to a regular multi-select taxonomy used only for categorization and filtering.

## Current Architecture

```
User selects "Event Type" in dropdown
  → EventType::save_event_type() writes hmw_event_type term
  → EventTypeDefaultsService::auto_apply_on_first_save() reads term slug
  → EventTypeRegistry::get(slug) returns {hidden_fields, required_fields}
  → apply_defaults_to_event() writes _event_field_config post meta
  → ACF::apply_event_field_config() reads _event_field_config → shows/hides fields
```

## Proposed Architecture

```
User selects "Event Template" in dropdown
  → New save handler writes _selected_template_id post meta
  → EventTypeDefaultsService reads template ID, fetches template row
  → template_data.event_fields becomes _event_field_config
  → ACF::apply_event_field_config() reads _event_field_config → shows/hides fields
  → JS live toggling uses template-specific field configs

Event type taxonomy goes back to standard WP checkboxes — categorization only.
```

## What Stays (No Changes Needed)

| Component | Reason |
|---|---|
| `ACF::apply_event_field_config()` | Already reads `_event_field_config` — source-agnostic |
| `_event_field_config` post meta | Already the single source of truth for field visibility |
| JS live field toggling pattern | Already built — just swap the data source |
| `hmwevents_event_templates` table | Already has `title`, `template_data`, `is_active` |
| `EventTemplateService::get_all()` | Already lists templates by active/retired status |
| `EventTemplateService::persist_event_snapshot()` | Already writes `_event_field_config` from resolved template data |
| Template editor UI | Already allows configuring hidden/required fields per template |
| `EventTypeRegistry` | Still used by template editor for base presets when creating templates |
| `ACF.php` EventTypeRegistry fallback | Legacy events without a template still get correct field visibility |

## What Changes

### Step 1 — Revert EventType.php changes

**File:** `src/Taxonomies/EventType.php`

Remove the three methods and two hooks we added:
- Remove `replace_taxonomy_meta_box()` hook and method
- Remove `render_event_type_select()` method
- Remove `save_event_type()` hook and method

The default `hmw_event_typediv` metabox renders checkboxes again. Event type becomes a regular multi-select taxonomy.

### Step 2 — Create template selector dropdown

**File:** `src/Services/EventTypeDefaultsService.php` (or new `src/Admin/EventTemplateSelector.php`)

New meta box "Event Template" on the `hmw_event` edit screen, replacing the event type dropdown:

```php
public function render_template_select(\WP_Post $post): void
{
    $current_template_id = (int) get_post_meta($post->ID, '_selected_template_id', true);
    $templates = $this->template_service->get_all(['is_active' => 1]);

    wp_nonce_field('hmwevents_template_select_save', 'hmwevents_template_select_nonce');

    echo '<select name="hmwevents_event_template" id="hmwevents-event-template-select">';
    echo '<option value="">— Select Event Template —</option>';
    foreach ($templates as $template) {
        $selected = ($template->id === $current_template_id) ? ' selected' : '';
        printf(
            '<option value="%d"%s>%s</option>',
            $template->id,
            $selected,
            esc_html($template->title)
        );
    }
    echo '</select>';
}
```

Save handler writes `_selected_template_id` post meta.

### Step 3 — Localize template field configs to JS

**File:** `src/Services/EventTypeDefaultsService.php`

Instead of localizing `hmwEventTypeDefaults.hiddenFields` (keyed by type slug), localize `hmwEventTemplateDefaults` keyed by template ID:

```php
$templates = $template_service->get_all(['is_active' => 1]);
$hidden_fields_by_id = [];
foreach ($templates as $template) {
    $data = json_decode($template->template_data, true);
    $hidden_fields_by_id[(int) $template->id] = array_values(
        array_map('sanitize_key', (array) ($data['event_fields']['hidden'] ?? []))
    );
}
wp_localize_script('jquery', 'hmwEventTemplateDefaults', [
    'hiddenFields' => $hidden_fields_by_id,
    'allHideableFields' => $all_hideable_fields,
]);
```

### Step 4 — Update live JS field toggling

**Same file** — the inline script changes from:
```javascript
var hiddenFields = window.hmwEventTypeDefaults.hiddenFields;
var selected = $('#hmwevents-event-type-select').val();
```
to:
```javascript
var hiddenFields = window.hmwEventTemplateDefaults.hiddenFields;
var selected = $('#hmwevents-event-template-select').val();
```

All the `showAllTypeFields()` / `hideFieldsForType()` logic is reused — just the data source changes.

### Step 5 — Auto-apply template config on first save

**File:** `src/Services/EventTypeDefaultsService.php`

Replace the current `auto_apply_on_first_save()` logic (which reads event type taxonomy term → EventTypeRegistry) with:

```php
public function auto_apply_on_first_save(int $post_id): void
{
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    $existing = get_post_meta($post_id, '_event_field_config', true);
    if (is_array($existing) && !empty($existing)) return;

    $template_id = (int) get_post_meta($post_id, '_selected_template_id', true);
    if ($template_id <= 0) return;

    $template = $this->template_service->get($template_id);
    if (!$template) return;

    $template_data = is_array($template->template_data)
        ? $template->template_data
        : json_decode($template->template_data, true);

    if (!is_array($template_data)) return;

    $event_fields = $template_data['event_fields'] ?? [];

    update_post_meta($post_id, '_event_field_config', [
        'event_fields' => [
            'required' => array_values(array_map('sanitize_key', (array) ($event_fields['required'] ?? []))),
            'optional' => array_values(array_map('sanitize_key', (array) ($event_fields['optional'] ?? []))),
            'hidden'   => array_values(array_map('sanitize_key', (array) ($event_fields['hidden'] ?? []))),
        ],
        'registration_fields' => $template_data['registration_fields'] ?? [],
    ]);
}
```

### Step 6 — Apply template defaults (optional enhancement)

If the template has default meta values (e.g., `event_capacity`, `event_delivery_mode`), apply them during auto-save for empty fields. This can reuse the existing `apply_defaults_to_event()` pattern but source defaults from the template's `template_data.defaults` instead of `EventTypeRegistry::get_default_meta()`.

### Step 7 — Update "Apply Defaults" button

The sidebar "Apply Type Defaults" meta box becomes "Apply Template Defaults". It re-applies the selected template's field config and default meta values. The AJAX handler changes from `term_slug` to `template_id`.

### Step 8 — Test coverage

Update the two test files:
- `tests/Unit/Services/EventTypeDefaultsServiceTest.php` — update for template-based auto-apply
- `tests/Unit/Taxonomies/EventTypeTest.php` — revert tests to remove custom metabox assertions, keep only taxonomy constant test

Add new tests:
- Template selector renders all active templates
- Auto-apply extracts `event_fields` from template JSON correctly
- JS localized data maps template ID → hidden fields

## Files Touched

| File | Change type |
|---|---|
| `src/Taxonomies/EventType.php` | Revert — remove custom metabox code |
| `src/Services/EventTypeDefaultsService.php` | Major — swap event type → template logic |
| `tests/Unit/Taxonomies/EventTypeTest.php` | Revert — remove save/render tests |
| `tests/Unit/Services/EventTypeDefaultsServiceTest.php` | Update — template-based assertions |

## Migration Notes

- **Existing events** keep working via the `ACF.php` EventTypeRegistry fallback in `get_event_field_config()`. If no `_event_field_config` exists and no template is selected, it reads the `hmw_event_type` taxonomy term and falls back to Registry config. Zero migration needed.
- **Events created from templates** (via the existing "Create from Template" flow) already have `_event_field_config` written by `persist_event_snapshot()`. They're unaffected.
- **The template editor** still ties templates to event types via `event_type_slug`. This is intentional — when creating a template, selecting "Webinar" pre-populates the field visibility from the Webinar archetype. The admin can then customize from there.

## Open Questions

1. **Should template auto-apply also set `_created_from_template_id`?** Probably not — that meta key signals "created from template" and triggers the template override UI. Auto-apply on save should be lighter weight.

2. **Should the template dropdown also filter by event type?** Could show two-tier: event type first, then templates of that type. Or keep it flat — all active templates visible.

3. **What happens if a template is retired after events use it?** The `_event_field_config` is a snapshot written at save time, so existing events keep their config. New events can't select retired templates (filtered by `is_active=1`).

4. **Should we keep the EventTypeRegistry fallback in ACF.php?** Yes — it's a safety net for events that have no template and no `_event_field_config` written yet.
