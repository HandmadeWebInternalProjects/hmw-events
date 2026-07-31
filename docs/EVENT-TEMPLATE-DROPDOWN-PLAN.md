# Event Template Dropdown Plan

**Status:** Draft  
**Last updated:** 2026-07-10  
**Estimated effort:** 3-4 hours

---

## Goal

Decouple ACF field visibility from the `hmw_event_type` taxonomy. Make named "Event Templates" the primary driver of field visibility. Event type reverts to a regular multi-select taxonomy for categorization/filtering only. Templates are standalone — not tied to event type except as a "default starting point" hint in the template editor.

---

## Current Architecture (as of 2026-07-10)

```
User selects "Event Type" in dropdown
  → EventType::save_event_type() writes single hmw_event_type term
  → EventTypeDefaultsService::auto_apply_on_first_save() reads term slug
  → apply_defaults_to_event() → resolve_event_fields()
      → checks _created_from_template_id first
      → falls back to find_template_by_event_type(slug)
      → then to EventTypeRegistry::get(slug)
  → Writes _event_field_config post meta
  → JS live toggling via hmwevents-hidden-by-type CSS class
  → ACF::apply_event_field_config() reads _event_field_config → shows/hides fields
```

### What's already template-aware

The system has evolved significantly since the original plan. Key methods in `EventTypeDefaultsService`:

- **`resolve_event_fields($post_id, $type_slug)`** (`src/Services/EventTypeDefaultsService.php:209`):
  1. Checks `_created_from_template_id` → uses that template via `TemplateResolver`
  2. Falls back to `find_template_by_event_type($type_slug)` → gets first active template matching the event type slug
  3. Falls back to `EventTypeRegistry::get($type_slug)` → pure registry config

- **`find_template_by_event_type($type_slug)`** (`src/Services/EventTypeDefaultsService.php:241`):
  Queries `EventTemplateService::get_all(['event_type_slug' => $type_slug, 'is_active' => true])`

- **JS live toggling** uses CSS class `hmwevents-hidden-by-type` instead of jQuery `.hide()`, and already respects template overrides via `hasActiveOverride()` check.

---

## Proposed Architecture

```
Event type taxonomy → regular WP checkboxes (multi-select, categorization only)

New "Event Template" dropdown in sidebar
  → Stores _selected_template_id post meta
  → On change: JS live toggles fields from template's event_fields.hidden
  → On first save: reads template ID, fetches template_data, writes _event_field_config
  → "Apply Template Defaults" button re-applies template fields + meta defaults
  → resolve_event_fields() reads _selected_template_id instead of matching by type slug
```

### Multi-event-type behavior

If an event has multiple event types assigned, the template dropdown drives the field config independently. When the dropdown says "— Select —", the system loads the config for the first assigned event type from the Registry as a fallback. If that's not right, the user picks a template manually.

### What the template editor does with event type

The template admin page already has an "Event Type" dropdown that loads Registry presets for that archetype into the form builder. This is reworded to:

> "Start with defaults for this event type:"

It pre-populates the field visibility drag-and-drop from the Registry. The admin configures from there. The template is saved with whatever customizations they make — the `event_type_slug` is just a hint/starting-point, not a binding.

---

## What Stays (No Changes Needed)

| Component | Reason |
|---|---|
| `ACF::apply_event_field_config()` | Reads `_event_field_config` — source-agnostic |
| `_event_field_config` post meta | Already the single source of truth |
| `resolve_event_fields()` | Already template-first resolution — just update which ID it reads |
| `find_template_by_event_type()` | No changes needed |
| JS live toggling pattern (CSS class + override check) | Reuse selectors and data source swap |
| `hmwevents_event_templates` table | Has `title`, `template_data`, `is_active` |
| `EventTemplateService::get_all()` | Lists templates by active status |
| `EventTemplateService::persist_event_snapshot()` | Already writes `_event_field_config` |
| Template editor drag-and-drop UI | Already configures `event_fields.hidden/required` per template |
| `EventTypeRegistry` | Base presets for template editor + fallback for untemplated events |
| `ACF.php` EventTypeRegistry fallback | Safety net for legacy events |
| `ajax_apply_defaults()` response with `field_config` | Already returns hidden/required — works for template data too |

---

## Step-by-Step Implementation

### Step 1 — Revert EventType.php changes

**File:** `src/Taxonomies/EventType.php`

Remove the three methods and two hooks:
- Remove `replace_taxonomy_meta_box()` hook and method (lines 25, 105-116)
- Remove `render_event_type_select()` method (lines 118-136)
- Remove `save_event_type()` hook and method (lines 26, 138-159)

The default `hmw_event_typediv` metabox renders checkboxes again. Event type becomes a regular multi-select taxonomy.

### Step 2 — Create template selector dropdown

**File:** `src/Services/EventTypeDefaultsService.php`

The existing `add_meta_box()`, `render_meta_box()` stay structurally the same but become template-focused. The sidebar meta box title changes to "Event Template". The dropdown is populated from templates instead of taxonomy terms:

```php
public function render_meta_box(\WP_Post $post): void
{
    $template_id = (int) get_post_meta($post->ID, '_selected_template_id', true);
    $templates = $this->template_service()->get_all(['is_active' => 1]);

    wp_nonce_field('hmwevents_template_select_save', 'hmwevents_template_select_nonce');
    ?>
    <p><?php esc_html_e('Select an event template to configure fields and defaults.', 'hmw-events'); ?></p>
    <select name="hmwevents_event_template" id="hmwevents-event-template-select">
        <option value="">— Select Template —</option>
        <?php foreach ($templates as $template): ?>
            <option value="<?php echo (int) $template->id; ?>" <?php selected($template_id, $template->id); ?>>
                <?php echo esc_html($template->title); ?>
            </option>
        <?php endforeach; ?>
    </select>
    <input type="hidden" id="hmwevents-current-template-id" value="<?php echo esc_attr((string) $template_id); ?>" />
    <button type="button" class="button button-secondary" id="hmwevents-apply-template-defaults" disabled>
        <?php esc_html_e('Apply Template Defaults', 'hmw-events'); ?>
    </button>
    <p id="hmwevents-template-defaults-status" style="margin-top:8px;"></p>
    <?php
}
```

Add a save handler (new hook in `register()` at priority 9):

```php
add_action('save_post_hmw_event', [$this, 'save_template_selection'], 9);
```

```php
public function save_template_selection(int $post_id): void
{
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!isset($_POST['hmwevents_template_select_nonce'])) return;
    if (!wp_verify_nonce($_POST['hmwevents_template_select_nonce'], 'hmwevents_template_select_save')) return;
    if (!current_user_can('edit_post', $post_id)) return;

    $template_id = (int) ($_POST['hmwevents_event_template'] ?? 0);
    if ($template_id > 0) {
        update_post_meta($post_id, '_selected_template_id', $template_id);
    } else {
        delete_post_meta($post_id, '_selected_template_id');
    }
}
```

### Step 3 — Update `resolve_event_fields()` to read `_selected_template_id`

**File:** `src/Services/EventTypeDefaultsService.php` (line 209)

The method already does template-first resolution. Just change the first check from `_created_from_template_id` to also check `_selected_template_id`:

```php
private function resolve_event_fields(int $post_id, string $type_slug): array
{
    $template = null;

    $template_id = (int) get_post_meta($post_id, '_selected_template_id', true)
        ?: (int) get_post_meta($post_id, '_created_from_template_id', true);

    if ($template_id) {
        $template = $this->template_service()->get($template_id);
    }

    if (!$template) {
        $template = $this->find_template_by_event_type($type_slug);
    }

    if ($template) {
        $resolver = new TemplateResolver(new TemplateSchemaValidator());
        $resolved = $resolver->resolve(
            $template->event_type_slug ?: $type_slug,
            (array) $template->template_data
        );
        if (!is_wp_error($resolved) && isset($resolved['field_config']['event_fields'])) {
            return $resolved['field_config']['event_fields'];
        }
    }

    $type_config = EventTypeRegistry::get($type_slug);
    return [
        'required' => array_values(array_map('sanitize_key', (array) ($type_config['required_fields'] ?? []))),
        'optional' => [],
        'hidden'   => array_values(array_map('sanitize_key', (array) ($type_config['hidden_fields'] ?? []))),
    ];
}
```

### Step 4 — Localize template field configs to JS

**File:** `src/Services/EventTypeDefaultsService.php` — `enqueue_assets()`

Replace the event-type-based localization with template-based:

```php
$templates = $this->template_service()->get_all(['is_active' => 1]);
$hidden_fields_map = [];
foreach ($templates as $template) {
    $data = is_array($template->template_data)
        ? $template->template_data
        : json_decode($template->template_data, true);
    $hidden_fields_map[(int) $template->id] = array_values(
        array_map('sanitize_key', (array) ($data['event_fields']['hidden'] ?? []))
    );
}

wp_localize_script('jquery', 'hmwEventTypeDefaults', [
    'hiddenFields' => $hidden_fields_map,
    'allHideableFields' => EventTypeRegistry::get_all_hideable_fields(),
]);
```

### Step 5 — Update JS inline script

**File:** `src/Services/EventTypeDefaultsService.php` — `get_inline_script()`

Change:
- Selector: `#hmwevents-event-type-select` → `#hmwevents-event-template-select`
- Value read: `getSelectedTypeSlug()` → `getSelectedTemplateId()` (returns int, not string)
- Hidden input: `#hmwevents-current-event-type-slug` → `#hmwevents-current-template-id`
- Button: `#hmwevents-apply-type-defaults` → `#hmwevents-apply-template-defaults`
- Status div: `#hmwevents-type-defaults-status` → `#hmwevents-template-defaults-status`
- AJAX param: `term_slug` → `template_id`

The `showAllTypeFields()` / `hideFieldsForType()` logic stays — just keyed by template ID string instead of type slug string.

### Step 6 — Update AJAX handler

**File:** `src/Services/EventTypeDefaultsService.php` — `ajax_apply_defaults()`

Accept `template_id` instead of `term_slug`:

```php
$template_id = (int) ($_POST['template_id'] ?? 0);
if (!$template_id) {
    wp_send_json_error(['message' => __('Missing template.', 'hmw-events')], 400);
}

$template = $this->template_service()->get($template_id);
if (!$template) {
    wp_send_json_error(['message' => __('Invalid template.', 'hmw-events')], 400);
}

// Use the template's event_type_slug for Registry lookups (attendance presets, etc.)
$type_slug = $template->event_type_slug;

$result = $this->apply_defaults_to_event($post_id, $type_slug);
```

### Step 7 — Update "Apply Defaults" button reset behavior

The AJAX success callback already re-applies `field_config` from the response. Keep `showAllTypeFields()` + hide logic, but update hidden input to `#hmwevents-current-template-id` with the template ID value.

### Step 8 — Update meta box IDs and text strings

Throughout `EventTypeDefaultsService.php`:
- Meta box ID: `hmwevents-event-type-defaults` → `hmwevents-event-template-defaults`
- Meta box title: "Event Type Defaults" → "Event Template"
- Help text: "When event type changes…" → "Select a template to configure fields and defaults."
- Nonce action/field names: update to template variants

---

## Files Touched

| File | Change type | Lines |
|---|---|---|
| `src/Taxonomies/EventType.php` | Revert — remove custom metabox code (3 methods, 2 hooks) | ~55 |
| `src/Services/EventTypeDefaultsService.php` | Major — dropdown data source, JS, AJAX, resolve logic | ~80 |
| `tests/Unit/Taxonomies/EventTypeTest.php` | Revert to pre-dropdown state | ~100 |
| `tests/Unit/Services/EventTypeDefaultsServiceTest.php` | Update for template-based assertions | ~20 |

---

## Migration Notes

- **Existing events** — keep working via the `ACF.php` EventTypeRegistry fallback and existing `_event_field_config`. Zero migration needed.
- **Events created from templates** — already have `_event_field_config` written by `persist_event_snapshot()`. Unaffected.
- **Events with `_selected_template_id` but no `_event_field_config`** — handled by `auto_apply_on_first_save` which now resolves from template.
- **The "Apply Type Defaults" AJAX action** — rename to `hmwevents_apply_template_defaults` for clarity, or keep the old action name and just change the params it accepts. Keeping the old name is simpler.
- **`hmwEventTypeDefaults` JS global** — keep the name to avoid breaking anything that might reference it. The structure stays the same (`hiddenFields` map + `allHideableFields` array).

---

## Open Questions

1. **Add a "Create New Template" link in the dropdown meta box?** A small "Manage Templates" link below the dropdown linking to `/wp-admin/admin.php?page=hmwevents-event-templates` would be helpful.

2. **Should the template dropdown show the event type in parentheses?** E.g., "Calmbirth Weekend Course (parent-course)". Depends on whether templates in the real system are named meaningfully enough on their own.

3. **What if no template is selected and event type has no matching template?** Falls back to `EventTypeRegistry::get(type_slug)` — shows/hides fields based on the archetype. Good default behavior.
