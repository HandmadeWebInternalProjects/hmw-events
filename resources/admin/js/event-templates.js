(function ($) {
  'use strict';

  var buckets = { event: {}, registration: {} };
  var statusEl;

  function notice(type, msg) {
    if (!statusEl) { statusEl = $('#hmwevents-template-save-status'); }
    statusEl.removeClass('notice-success notice-error').addClass('notice notice-' + type + ' inline')
      .html('<p>' + msg + '</p>').show();
  }

  // ================================================================
  // FIELD BUCKET RENDERING
  // ================================================================

  function renderFieldBuckets(section, container) {
    var fields = section === 'event' ? window.hmwEventTemplates.acfEventFields : window.hmwEventTemplates.registrationFields;
    if (!fields || !fields.length) return;

    var buckets = ['hidden', 'optional', 'required'];
    var state = readBucketState(section);
    var html = '<table class="widefat striped hmwevents-field-bucket-table"><thead><tr>';
    html += '<th style="width:50%;">Field</th>';
    html += '<th style="width:50%;">State</th>';
    html += '</tr></thead><tbody>';

    fields.forEach(function (f) {
      var currentState = 'optional';
      if (state.hidden.indexOf(f.key) !== -1) currentState = 'hidden';
      else if (state.required.indexOf(f.key) !== -1) currentState = 'required';

      var badge = '';
      if (section === 'registration') {
        var audience = (f.audience_variants || []).join(', ');
        if (audience && audience !== '*') {
          badge = ' <span class="hmwevents-audience-badge">' + escHtml(audience) + '</span>';
        }
      }

      var rowClass = '';
      if (currentState === 'hidden') rowClass = ' hmwevents-field-hidden';
      else if (currentState === 'required') rowClass = ' hmwevents-field-required';

      html += '<tr class="hmwevents-field-row' + rowClass + '" data-section="' + section + '" data-field-key="' + escAttr(f.key) + '">';
      html += '<td><strong>' + escHtml(f.label) + '</strong> <code>' + escHtml(f.key) + '</code>' + badge + '</td>';
      html += '<td><select class="hmwevents-field-state">';
      buckets.forEach(function (b) {
        html += '<option value="' + b + '"' + (b === currentState ? ' selected' : '') + '>' + b.charAt(0).toUpperCase() + b.slice(1) + '</option>';
      });
      html += '</select></td>';
      html += '</tr>';
    });

    html += '</tbody></table>';
    container.html(html);
  }

  function readBucketState(section) {
    var hidden = [];
    var optional = [];
    var required = [];
    $('.hmwevents-field-row[data-section="' + section + '"]').each(function () {
      var key = $(this).data('field-key');
      var state = $(this).find('.hmwevents-field-state').val();
      if (state === 'hidden') hidden.push(key);
      else if (state === 'required') required.push(key);
      else optional.push(key);
    });
    return { hidden: hidden, optional: optional, required: required };
  }

  // ================================================================
  // EVENT META DEFAULTS
  // ================================================================

  function renderEventMetaDefaults() {
    var fields = window.hmwEventTemplates.acfEventFields;
    if (!fields || !fields.length) return;
    var defaults = readEventMetaDefaults();
    var container = $('#hmwevents-event-meta-defaults');
    var html = '<table class="widefat striped"><thead><tr>';
    html += '<th>Field</th><th>Type</th><th>Default Value</th>';
    html += '</tr></thead><tbody>';

    fields.forEach(function (f) {
      var current = '';
      if (defaults.hasOwnProperty(f.key)) {
        current = defaults[f.key];
      }
      if (current === null || current === undefined) current = '';

      var typeHint = 'text';
      var placeholder = '';
      if (f.type === 'number') { placeholder = 'e.g. 20'; }
      if (f.type === 'true_false') {
        html += '<tr><td><strong>' + escHtml(f.label) + '</strong> <code>' + escHtml(f.key) + '</code></td>';
        html += '<td>' + escHtml(f.type) + '</td>';
        html += '<td><select class="hmwevents-meta-default" data-field-key="' + escAttr(f.key) + '">';
        html += '<option value="">(no default)</option>';
        html += '<option value="1"' + (current === '1' || current === 1 ? ' selected' : '') + '>Yes (1)</option>';
        html += '<option value="0"' + (current === '0' || current === 0 ? ' selected' : '') + '>No (0)</option>';
        html += '</select></td></tr>';
        return;
      }
      html += '<tr><td><strong>' + escHtml(f.label) + '</strong> <code>' + escHtml(f.key) + '</code></td>';
      html += '<td>' + escHtml(f.type) + '</td>';
      html += '<td><input type="' + typeHint + '" class="regular-text hmwevents-meta-default" data-field-key="' + escAttr(f.key) + '" value="' + escAttr(String(current)) + '" placeholder="' + escAttr(placeholder) + '"></td>';
      html += '</tr>';
    });

    html += '</tbody></table>';
    container.html(html);
  }

  function readEventMetaDefaults() {
    var defaults = {};
    $('.hmwevents-meta-default').each(function () {
      var key = $(this).data('field-key');
      var val = $(this).val();
      if (val !== '') {
        if ($(this).is('select')) {
          defaults[key] = parseInt(val, 10);
        } else if (!isNaN(val) && val.trim() !== '') {
          defaults[key] = parseFloat(val) === parseInt(val, 10) ? parseInt(val, 10) : parseFloat(val);
        } else {
          defaults[key] = val;
        }
      }
    });
    return defaults;
  }

  // ================================================================
  // JSON ASSEMBLY
  // ================================================================

  function assembleJSON() {
    var templateId = parseInt($('input[name="template_id"]').val(), 10) || 0;
    var eventState = readBucketState('event');
    var regState = readBucketState('registration');
    var metaDefaults = readEventMetaDefaults();

    var data = {
      schema_version: 1,
      template_version: 1,
      post: {
        title_pattern: $('#hmwevents_title_pattern').val() || '',
        post_content: $('#hmwevents_post_content').val() || '',
        post_title: ''
      },
      event_fields: {
        required: eventState.required,
        optional: eventState.optional,
        hidden: eventState.hidden
      },
      registration_fields: {
        required: regState.required,
        optional: regState.optional,
        hidden: regState.hidden
      },
      defaults: {
        event_meta: metaDefaults,
        registration: {
          attendance_default: 'individual',
          field_overrides: {}
        },
        attendance_options: []
      }
    };

    // Retain existing template_version if editing
    if (templateId > 0) {
      var raw = $('#hmwevents_template_data_raw').val();
      try {
        var prev = JSON.parse(raw);
        if (prev && prev.template_version) {
          data.template_version = parseInt(prev.template_version, 10) || 1;
        }
        // Preserve attendance_options
        if (prev && prev.defaults && prev.defaults.attendance_options && prev.defaults.attendance_options.length) {
          data.defaults.attendance_options = prev.defaults.attendance_options;
        }
        // Preserve registration defaults
        if (prev && prev.defaults && prev.defaults.registration) {
          data.defaults.registration = prev.defaults.registration;
        }
      } catch (e) {}
    }

    return data;
  }

  function saveJSON() {
    var json = assembleJSON();
    var jsonStr = JSON.stringify(json);
    $('#hmwevents_template_data_raw').val(jsonStr);
    $('#hmwevents_template_data_raw_display').text(JSON.stringify(json, null, 2));
    return jsonStr;
  }

  // ================================================================
  // EVENT TYPE PRESET LOADING
  // ================================================================

  function loadEventTypePresets(slug) {
    var presets = window.hmwEventTemplates.registryPresets;
    if (!presets || !presets[slug]) return;

    var preset = presets[slug];

    // Set event field buckets from preset
    if (preset.required_fields || preset.hidden_fields) {
      var hiddenSet = {};
      var requiredSet = {};
      (preset.hidden_fields || []).forEach(function (k) { hiddenSet[k] = true; });
      (preset.required_fields || []).forEach(function (k) { requiredSet[k] = true; });

      $('.hmwevents-field-row[data-section="event"]').each(function () {
        var key = $(this).data('field-key');
        var state = 'optional';
        if (hiddenSet[key]) state = 'hidden';
        else if (requiredSet[key]) state = 'required';
        $(this).find('.hmwevents-field-state').val(state);
        updateRowClass($(this), state);
      });
    }

    // Set event meta defaults from preset
    if (preset.default_meta) {
      Object.keys(preset.default_meta).forEach(function (k) {
        var input = $('.hmwevents-meta-default[data-field-key="' + k + '"]');
        if (input.length) {
          input.val(preset.default_meta[k]);
        }
      });
    }

    notice('success', 'Presets loaded for <strong>' + escHtml(slug) + '</strong>. Review and adjust as needed.');
  }

  function updateRowClass(row, state) {
    row.removeClass('hmwevents-field-hidden hmwevents-field-required');
    if (state === 'hidden') row.addClass('hmwevents-field-hidden');
    else if (state === 'required') row.addClass('hmwevents-field-required');
  }

  // ================================================================
  // INITIALIZATION
  // ================================================================

  function init() {
    var rawJson = $('#hmwevents_template_data_raw').val();
    var initialData = {};
    try { initialData = JSON.parse(rawJson); } catch (e) {}

    // Read initial state from raw JSON
    var initEventFields = (initialData.event_fields) || { required: [], optional: [], hidden: [] };
    var initRegFields = (initialData.registration_fields) || { required: ['first_name', 'last_name', 'email', 'phone'], optional: [], hidden: [] };
    var initMeta = (initialData.defaults && initialData.defaults.event_meta) ? initialData.defaults.event_meta : {};

    // Render UI
    renderFieldBuckets('event', $('#hmwevents-event-fields-container'));

    // Apply initial state to event fields
    (initEventFields.hidden || []).forEach(function (k) {
      $('.hmwevents-field-row[data-section="event"][data-field-key="' + k + '"] .hmwevents-field-state').val('hidden');
      $('.hmwevents-field-row[data-section="event"][data-field-key="' + k + '"]').addClass('hmwevents-field-hidden');
    });
    (initEventFields.required || []).forEach(function (k) {
      $('.hmwevents-field-row[data-section="event"][data-field-key="' + k + '"] .hmwevents-field-state').val('required');
      $('.hmwevents-field-row[data-section="event"][data-field-key="' + k + '"]').addClass('hmwevents-field-required');
    });

    renderFieldBuckets('registration', $('#hmwevents-registration-fields-container'));

    // Apply initial state to registration fields
    (initRegFields.hidden || []).forEach(function (k) {
      $('.hmwevents-field-row[data-section="registration"][data-field-key="' + k + '"] .hmwevents-field-state').val('hidden');
      $('.hmwevents-field-row[data-section="registration"][data-field-key="' + k + '"]').addClass('hmwevents-field-hidden');
    });
    (initRegFields.required || []).forEach(function (k) {
      $('.hmwevents-field-row[data-section="registration"][data-field-key="' + k + '"] .hmwevents-field-state').val('required');
      $('.hmwevents-field-row[data-section="registration"][data-field-key="' + k + '"]').addClass('hmwevents-field-required');
    });

    renderEventMetaDefaults();

    // Set initial meta defaults from raw JSON
    if (initMeta) {
      Object.keys(initMeta).forEach(function (k) {
        var input = $('.hmwevents-meta-default[data-field-key="' + k + '"]');
        if (input.length) input.val(initMeta[k]);
      });
    }

    // Post fields
    if (initialData.post) {
      $('#hmwevents_title_pattern').val(initialData.post.title_pattern || '');
      $('#hmwevents_post_content').val(initialData.post.post_content || '');
    }

    // Update raw JSON preview
    $('#hmwevents_template_data_raw_display').text(JSON.stringify(assembleJSON(), null, 2));
  }

  // ================================================================
  // EVENT HANDLERS
  // ================================================================

  $(document).on('change', '.hmwevents-field-state', function () {
    var row = $(this).closest('.hmwevents-field-row');
    var state = $(this).val();
    updateRowClass(row, state);
    saveJSON();
  });

  $(document).on('change input', '.hmwevents-meta-default', function () {
    saveJSON();
  });

  $(document).on('change input', '#hmwevents_title_pattern, #hmwevents_post_content', function () {
    saveJSON();
  });

  $(document).on('change', '#hmwevents_template_type', function () {
    var slug = $(this).val();
    if (slug) {
      loadEventTypePresets(slug);
    }
  });

  // Toggle JSON preview
  $(document).on('click', '.hmwevents-toggle-json-preview', function (e) {
    e.preventDefault();
    $('#hmwevents-json-preview').slideToggle();
    $(this).text($(this).text() === 'Show JSON' ? 'Hide JSON' : 'Show JSON');
  });

  // Toggle advanced raw edit
  $(document).on('click', '.hmwevents-toggle-advanced', function (e) {
    e.preventDefault();
    $('#hmwevents-advanced-edit').slideToggle();
    $(this).text($(this).text() === 'Show advanced editor' ? 'Hide advanced editor' : 'Show advanced editor');
  });

  // Before form submit, sync raw JSON
  $(document).on('submit', '#hmwevents-template-form', function () {
    saveJSON();
    return true;
  });

  // ================================================================
  // HELPERS
  // ================================================================

  function escHtml(str) {
    if (!str) return '';
    var map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
    return String(str).replace(/[&<>"']/g, function (m) { return map[m]; });
  }

  function escAttr(str) {
    return String(str).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  // ================================================================
  // BOOT
  // ================================================================

  $(function () {
    if ($('#hmwevents-event-fields-container').length) {
      init();
    }
  });

})(jQuery);
