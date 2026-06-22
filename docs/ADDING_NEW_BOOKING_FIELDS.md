# Adding a New Booking Field

All booking fields flow through a single registry: `src/Config/BookingFields.php`.
**In most cases, editing that one file is all you need to do.**

---

## 1. Add the field to `BookingFields::all()`

Every field needs these **core properties**:

| Property | Type | Notes |
|---|---|---|
| `label` | `string` | Human-readable (used in CSV headers, email tables, admin modal) |
| `type` | `string` | `text` · `email` · `tel` · `date` · `textarea` · `select` · `checkbox` |
| `source` | `string` | `customer_meta` or `booking_details` (see below) |
| `meta_key` | `string\|null` | WP post-meta key if `source = customer_meta` (or dual-write, e.g. `partner_name`) |
| `form_name` | `string` | HTML `name=` used in the **admin** manual-booking form |
| `manual_required` | `bool` | Required in the admin manual-booking form |
| `in_csv` | `bool` | Include as a column in the attendee CSV export |
| `in_email` | `bool` | Include in the `{all_fields}` email merge-tag table |

For a `select` field also add:

```php
'options' => ['' => '— Select —', 'yes' => 'Yes', 'no' => 'No'],
```

---

### Which `source` should I use?

| Source | Stored where | When to use |
|---|---|---|
| `customer_meta` | WP post meta on the `edu_customer` CPT | Personal contact data (name, email, phone, address…) that should be attached to the customer and updated on re-booking |
| `booking_details` | JSON in `educator_booking_details.form_data` | Booking-specific answers (due date, health fund, agreements…) that belong to the booking, not the person |

---

### Frontend form properties

Add these only if the field should appear in the **public `[hmwevents_booking_form]` shortcode**.
Leave `frontend_section` as `null` (or omit it) for admin-only fields.

| Property | Type | Notes |
|---|---|---|
| `frontend_section` | `string\|null` | Section key: `your_information` · `address` · `extra_details` · `agreements` |
| `frontend_width` | `string` | `'half'` (two per row) or `'full'` (spans full row) |
| `frontend_required` | `bool` | Adds `required` attribute and `*` indicator |
| `frontend_label` | `string\|null` | Override the label text for the frontend only (`null` = use `label`) |
| `frontend_placeholder` | `string` | Placeholder for text / textarea inputs |
| `frontend_input` | `string\|null` | `'radio'` renders a `select` field as radio buttons instead; `null` uses the field's `type` |
| `frontend_rows` | `int\|null` | Rows attribute for `textarea` inputs (default `3`) |

**Minimal example — a new optional text field in Extra Details:**

```php
'referral_source' => [
    'label'                => 'How did you hear about us?',
    'type'                 => 'text',
    'source'               => 'booking_details',
    'meta_key'             => null,
    'form_name'            => 'referral_source',
    'manual_required'      => false,
    'in_csv'               => true,
    'in_email'             => true,
    'frontend_section'     => 'extra_details',
    'frontend_width'       => 'full',
    'frontend_required'    => false,
    'frontend_label'       => null,
    'frontend_placeholder' => '',
    'frontend_input'       => null,
    'frontend_rows'        => null,
],
```

---

## 2. Everything else — automatic

### REST API args (`ProcessPayment.php`)

`ProcessPayment::build_registry_args()` generates WP REST arg definitions at runtime
from `BookingFields::for_frontend()`. The following is derived automatically:

| Registry property | REST arg produced |
|---|---|
| `type` | `type` + correct `sanitize_callback` |
| `frontend_required` | `required` |
| `options` (select fields) | `enum` (blank key excluded) |

No changes to `ProcessPayment.php` are needed.

### Customer meta syncing (`AbstractPaymentGateway.php`)

`sync_customer_meta()` loops over `BookingFields::with_meta_fallback()` and writes each
field's value (read from `booking_details` by canonical key) to its `meta_key` in the
customer CPT. Setting a non-null `meta_key` in the registry is all that's needed.

No changes to `AbstractPaymentGateway.php` are needed.

### Frontend form

`BookingForm.php` and `booking-form.js` are fully registry-driven. Setting
`frontend_section` in the registry entry is sufficient — the correct input type, label,
placeholder, required indicator, enum options, and JS field collection are all handled
automatically.

### Admin manual-booking form

`EducatorCourse.php` loops over `BookingFields::all()`. Setting `manual_required` and
`form_description` (for checkboxes) in the registry entry is all that's needed.

---

## Quick checklist

```
[ ] 1. src/Config/BookingFields.php  — add field entry with all properties
                                       (this is the only file you need to edit)
```

That's it for `booking_details` fields and for any field that only needs to appear
in the admin form or exports.

For `customer_meta` fields (those written to the customer CPT), also verify:
- `meta_key` is set to the correct ACF/WP meta key
- `source` is `'customer_meta'` (or `'booking_details'` with `meta_key` set for dual-write)

