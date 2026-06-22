# AJAX Email Template Editor - Implementation Notes

## Overview
The email template editor now uses AJAX to switch between templates and save changes without page reloads. This prevents the "unsaved changes" warning when editing user profiles.

## What Was Implemented

### 1. JavaScript (email-templates.js)
- **Template Switching**: Load templates via AJAX without page reload
- **AJAX Form Saving**: Save templates in background
- **Change Tracking**: Monitor form changes with `formIsDirty` flag
- **Unsaved Changes Warning**: `beforeunload` event prevents accidental navigation
- **Admin Notices**: Inline success/error messages with auto-dismiss
- **Loading States**: Visual feedback with spinners and disabled buttons
- **TinyMCE Integration**: Properly extracts content from WYSIWYG editor

### 2. PHP AJAX Handlers (EmailTemplates.php)
- `ajax_load_template()`: Loads template data, checks permissions, returns JSON
- `ajax_save_template()`: Saves system templates
- `ajax_save_educator_template()`: Saves educator overrides
- `ajax_reset_educator_template()`: Deletes educator overrides

### 3. Security
- WordPress nonces (`hmwevents_email_templates`)
- Capability checks (`manage_options` for system, `edit_user` for educators)
- Input sanitization (`sanitize_text_field`, `wp_kses_post`)
- AJAX referer checks

## AJAX Endpoints

### Load Template
**Action**: `hmwevents_load_email_template`
**POST Data**:
```javascript
{
  action: 'hmwevents_load_email_template',
  template_key: 'string',
  context: 'system' | 'educator',
  user_id: int, // if educator context
  nonce: 'string'
}
```
**Response**:
```json
{
  "success": true,
  "data": {
    "template": {
      "template_key": "string",
      "subject": "string",
      "body": "string",
      "is_active": 0|1
    },
    "editor_id": "string",
    "status_message": "html",
    "variables_html": "html"
  }
}
```

### Save System Template
**Action**: `hmwevents_save_email_template_ajax`
**POST Data**:
```javascript
{
  action: 'hmwevents_save_email_template_ajax',
  template_key: 'string',
  subject: 'string',
  body: 'string',
  is_active: 0|1,
  ajax_nonce: 'string'
}
```

### Save Educator Template
**Action**: `hmwevents_save_educator_email_template_ajax`
**POST Data**:
```javascript
{
  action: 'hmwevents_save_educator_email_template_ajax',
  user_id: int,
  template_key: 'string',
  subject: 'string',
  body: 'string',
  is_active: 0|1,
  ajax_nonce: 'string'
}
```

### Reset Educator Template
**Action**: `hmwevents_reset_educator_email_template_ajax`
**POST Data**:
```javascript
{
  action: 'hmwevents_reset_educator_email_template_ajax',
  user_id: int,
  template_key: 'string',
  ajax_nonce: 'string'
}
```

## Testing Checklist

### System Templates (HMWEvents Email Templates page)
- [ ] Switch between templates - no page reload
- [ ] Save template - shows success message
- [ ] Edit content - warning appears if navigating away
- [ ] Save template - warning disappears
- [ ] TinyMCE editor content saves correctly

### Educator Templates (User Profile page)
- [ ] Switch between templates - no page reload
- [ ] Status message updates ("Using system default" vs "Using your customized template")
- [ ] Save override - success message appears
- [ ] Reset to default - confirmation dialog appears
- [ ] Reset to default - status message updates
- [ ] Other profile fields can be edited without interference
- [ ] Switching templates doesn't trigger WordPress unsaved changes warning

### Edge Cases
- [ ] Invalid template key - error message
- [ ] Permission denied - error message
- [ ] Network error - error message with retry option
- [ ] Multiple rapid template switches - only last request completes
- [ ] TinyMCE not initialized - graceful fallback

## Browser Console Testing

You can test AJAX calls directly in the browser console:

```javascript
// Load a template
jQuery.post(ajaxurl, {
  action: 'hmwevents_load_email_template',
  template_key: 'booking_confirmation',
  context: 'system',
  nonce: cmsEmailTemplates.nonce
}, console.log);

// Save system template
jQuery.post(ajaxurl, {
  action: 'hmwevents_save_email_template_ajax',
  template_key: 'booking_confirmation',
  subject: 'Test Subject',
  body: '<p>Test body</p>',
  is_active: 1,
  ajax_nonce: cmsEmailTemplates.nonce
}, console.log);
```

## Features

### Auto-Save (Disabled by Default)
The JavaScript includes auto-save functionality that can be enabled by uncommenting:
```javascript
// Auto-save every 60 seconds (disabled by default)
// setInterval(function() {
//   if (formIsDirty) {
//     saveForm(true); // true = silent save
//   }
// }, 60000);
```

### Keyboard Shortcuts
None implemented yet, but easy to add:
```javascript
// Example: Ctrl/Cmd + S to save
$(document).on('keydown', function(e) {
  if ((e.ctrlKey || e.metaKey) && e.key === 's') {
    e.preventDefault();
    $('.hmwevents-email-template-form').submit();
  }
});
```

## File Locations
- JavaScript: `/resources/admin/js/email-templates.js`
- PHP Class: `/src/Admin/EmailTemplates.php`
- CSS: `/resources/admin/css/email-admin.css`
- Enqueue: `/src/Admin/Admin.php` (enqueue_assets method)

## Dependencies
- jQuery (WordPress core)
- TinyMCE (WordPress core)
- WordPress AJAX API

## Known Limitations
1. No real-time collaboration (last save wins)
2. No revision history
3. Auto-save disabled by default (to prevent accidental saves)
4. No draft/preview mode

## Future Enhancements
- [ ] Add keyboard shortcuts (Ctrl+S to save)
- [ ] Add template preview modal
- [ ] Add revision history
- [ ] Add template duplication
- [ ] Add bulk actions for educator templates
- [ ] Add template export/import
- [ ] Add rich text variables dropdown (insert button)
- [ ] Add template testing (send test email)
