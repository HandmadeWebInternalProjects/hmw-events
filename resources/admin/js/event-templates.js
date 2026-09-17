(function ($) {
  'use strict';

  var statusEl;
  var currentModalSectionId = '';
  var currentModalFieldKey = '';
  var ATTENDANCE_OPTION_TYPES = {
    individual: 'Individual',
    parent: 'Parent',
    parent_child: 'Parent + Child',
    couple: 'Couple',
    professional: 'Professional'
  };

  function notice(type, msg) {
    if (!statusEl) { statusEl = $('#hmwevents-template-save-status'); }
    statusEl.removeClass('notice-success notice-error').addClass('notice notice-' + type + ' inline')
      .html('<p>' + msg + '</p>').show();
  }

  function escHtml(str) {
    if (!str) return '';
    var map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
    return String(str).replace(/[&<>"']/g, function (m) { return map[m]; });
  }

  function escAttr(str) {
    return String(str).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  function generateId() {
    return 'sec_' + Math.random().toString(36).substring(2, 10);
  }

  // ================================================================
  // PANEL 1: ACF EVENT FIELDS (drag/drop between two connected columns)
  // ================================================================

  function renderAcfDndPanel(container, initialData) {
    var fields = window.hmwEventTemplates.acfEventFields;
    if (!fields || !fields.length) return;

    var initHidden = (initialData.event_fields && initialData.event_fields.hidden) ? initialData.event_fields.hidden : [];
    var initRequired = (initialData.event_fields && initialData.event_fields.required) ? initialData.event_fields.required : [];
    var initOptional = (initialData.event_fields && initialData.event_fields.optional) ? initialData.event_fields.optional : [];

    var hiddenSet = {};
    var requiredSet = {};
    initHidden.forEach(function (k) { hiddenSet[k] = true; });
    initRequired.forEach(function (k) { requiredSet[k] = true; });

    var availableHtml = '';
    var includedHtml = '';

    fields.forEach(function (f) {
      if (f.type === 'group') {
        var children = f.children || [];
        var allHidden = children.length > 0 && children.every(function (c) { return hiddenSet[c]; });
        var allRequired = children.length > 0 && children.every(function (c) { return requiredSet[c]; });
        if (allHidden) {
          availableHtml += buildAcfItemHtml(f, false);
        } else {
          includedHtml += buildAcfItemHtml(f, allRequired);
        }
      } else if (hiddenSet[f.key]) {
        availableHtml += buildAcfItemHtml(f, false);
      } else {
        includedHtml += buildAcfItemHtml(f, requiredSet[f.key]);
      }
    });

    fields.forEach(function (f) {
      if (f.type === 'group') {
        return;
      }
      if (!hiddenSet[f.key] && !requiredSet[f.key] && initOptional.indexOf(f.key) === -1) {
        if (availableHtml.indexOf('data-field-key="' + escAttr(f.key) + '"') === -1 &&
            includedHtml.indexOf('data-field-key="' + escAttr(f.key) + '"') === -1) {
          availableHtml += buildAcfItemHtml(f, false);
        }
      }
    });

    var html = '<div class="hmwevents-dnd-container">';
    html += '<div class="hmwevents-dnd-panel hmwevents-dnd-available">';
    html += '<h4>' + escHtml('Available Fields') + '</h4>';
    html += '<ul class="hmwevents-dnd-sortable" id="hmwevents-acf-available">' + availableHtml + '</ul></div>';
    html += '<div class="hmwevents-dnd-panel hmwevents-dnd-included">';
    html += '<h4>' + escHtml('Included Fields') + '</h4>';
    html += '<ul class="hmwevents-dnd-sortable" id="hmwevents-acf-included">' + includedHtml + '</ul></div>';
    html += '</div>';
    container.html(html);

    $('#hmwevents-acf-available, #hmwevents-acf-included').sortable({
      connectWith: '.hmwevents-dnd-sortable',
      placeholder: 'hmwevents-dnd-placeholder',
      handle: '.hmwevents-dnd-handle',
      opacity: 0.6,
      receive: function (event, ui) {
        var isAvailable = $(this).attr('id') === 'hmwevents-acf-available';
        var reqCheckbox = ui.item.find('.hmwevents-dnd-required input');
        if (isAvailable) {
          reqCheckbox.prop('checked', false);
        }
        saveJSON();
      },
      stop: function (event, ui) {
        ui.item.css('opacity', '');
        saveJSON();
      }
    }).disableSelection();
  }

  function buildAcfItemHtml(f, isRequired) {
    if (f.type === 'group') {
      return buildAcfGroupItemHtml(f, isRequired);
    }
    var reqId = 'acf-req-' + escAttr(f.key);
    return '<li class="hmwevents-dnd-item" data-field-key="' + escAttr(f.key) + '">' +
      '<span class="hmwevents-dnd-handle">&#9776;</span>' +
      '<span class="hmwevents-dnd-label"><strong>' + escHtml(f.label) + '</strong> <code>' + escHtml(f.key) + '</code></span>' +
      '<span class="hmwevents-dnd-required">' +
      '<input type="checkbox" id="' + escAttr(reqId) + '" ' + (isRequired ? 'checked' : '') + '>' +
      '<label for="' + escAttr(reqId) + '">Required</label></span></li>';
  }

  function buildAcfGroupItemHtml(f, isRequired) {
    var childrenJson = JSON.stringify(f.children || []);
    var reqId = 'acf-req-' + escAttr(f.key);
    return '<li class="hmwevents-dnd-item hmwevents-dnd-group" data-field-key="' + escAttr(f.key) + '" data-group-children="' + escAttr(childrenJson) + '">' +
      '<span class="hmwevents-dnd-handle">&#9776;</span>' +
      '<span class="hmwevents-dnd-label"><strong>' + escHtml(f.label) + '</strong> <span class="hmwevents-dnd-group-badge">group</span></span>' +
      '<span class="hmwevents-dnd-required">' +
      '<input type="checkbox" id="' + escAttr(reqId) + '" ' + (isRequired ? 'checked' : '') + '>' +
      '<label for="' + escAttr(reqId) + '">Required</label></span></li>';
  }

  // ================================================================
  // PANEL 2: REGISTRATION FORM BUILDER (sections + fields + ThickBox)
  // ================================================================

  function renderFormBuilder(container, initialData) {
    var initReg = (initialData.registration_fields) || { sections: [], multi_booking: { enabled: false, min: 1, max: 10 } };

    if (initialData.schema_version < 3 && !initReg.sections) {
      renderLegacyWarning(container);
      return;
    }

    var sections = initReg.sections || [];
    var html = '';

    html += '<div id="hmwevents-fb-sections">';
    sections.forEach(function (section) {
      html += renderSectionHtml(section);
    });
    html += '</div>';

    html += '<button type="button" class="button hmwevents-add-section-btn" style="margin-top:4px;">+ Add Section</button>';
    container.html(html);

    initSectionsSortable();
    initFieldsSortable();
    $('#hmwevents-fb-sections .hmwevents-fb-section-fields').each(function () {
      HmwFormBuilder.initFieldSortable($(this), saveJSON);
    });
  }

  function renderLegacyWarning(container) {
    container.html('<div class="notice notice-warning"><p>This template uses an older format. Save once to upgrade to the new form builder.</p></div>');
  }

  function renderSectionHtml(section) {
    var fields = section.fields || [];
    var html = '<div class="hmwevents-fb-section" data-section-id="' + escAttr(section.id || generateId()) + '">';
    html += '<div class="hmwevents-fb-section-header">';
    html += '<span class="hmwevents-dnd-handle hmwevents-fb-section-handle">&#9776;</span>';
    html += '<input type="text" class="hmwevents-fb-section-title" value="' + escAttr(section.label || 'Untitled Section') + '" style="flex:1;border:none;background:transparent;font-weight:600;font-size:14px;outline:none;">';
    html += '<button type="button" class="hmwevents-fb-delete-section">&times;</button>';
    html += '</div>';
    html += '<div class="hmwevents-fb-section-fields" style="display:grid;grid-template-columns:1fr 1fr;gap:6px;">';
    fields.forEach(function (field) {
      html += HmwFormBuilder.renderFieldCard(field);
    });
    html += '</div>';
    html += '<button type="button" class="hmwevents-fb-add-field button button-small">+ Add Field</button>';
    html += '</div>';
    return html;
  }

  // ================================================================
  // SORTABLE INITIALIZATION
  // ================================================================

  function initSectionsSortable() {
    $('#hmwevents-fb-sections').sortable({
      handle: '.hmwevents-fb-section-handle',
      placeholder: 'hmwevents-dnd-placeholder',
      opacity: 0.6,
      stop: function () {
        saveJSON();
      }
    }).disableSelection();
  }

  function initFieldsSortable() {
    $('.hmwevents-fb-section-fields').each(function () {
      HmwFormBuilder.initFieldSortable($(this), saveJSON);
    });
  }

  // ================================================================
  // FIELD MODAL
  // ================================================================

  function openFieldModal(sectionId, fieldData) {
    currentModalSectionId = sectionId;
    currentModalFieldKey = fieldData ? fieldData.key : '';

    HmwFormBuilder.openFieldModal(
      sectionId,
      fieldData,
      window.hmwEventTemplates.registrationPresets || {},
      $('#hmwevents-multi-booking-toggle').prop('checked'),
      function (data, secId, fieldKey) {
        onModalSave(data, secId, fieldKey);
      },
      function (fieldKey) {
        onModalDelete(fieldKey);
      }
    );
  }

  function onModalSave(data, secId, fieldKey) {
    var sectionEl = $('.hmwevents-fb-section[data-section-id="' + secId + '"]');
    var fieldsDiv = sectionEl.find('.hmwevents-fb-section-fields');

    if (fieldKey) {
      var existingCard = fieldsDiv.find('.hmwevents-fb-field-card[data-field-key="' + fieldKey + '"]');
      if (existingCard.length) {
        existingCard.replaceWith(HmwFormBuilder.renderFieldCard(data));
      }
    } else {
      var allKeys = [];
      $('.hmwevents-fb-field-card').each(function () { allKeys.push($(this).data('field-key')); });
      if (allKeys.indexOf(data.key) !== -1) {
        alert('A field with key "' + data.key + '" already exists.');
        return;
      }
      fieldsDiv.append(HmwFormBuilder.renderFieldCard(data));
    }

    fieldsDiv.sortable('refresh');
    saveJSON();
  }

  function onModalDelete(fieldKey) {
    var card = $('.hmwevents-fb-field-card[data-field-key="' + fieldKey + '"]');
    if (card.length) card.remove();
    saveJSON();
  }

  // ================================================================
  // EVENT HANDLERS
  // ================================================================

  function initMultiBookingToggle() {
    $(document).off('change.mb', '#hmwevents-multi-booking-toggle');
    $(document).on('change.mb', '#hmwevents-multi-booking-toggle', function () {
      $('#hmwevents-multi-booking-settings').toggle(this.checked);
      saveJSON();
    });

    $(document).off('change.mbmode', '#hmwevents-mb-mode');
    $(document).on('change.mbmode', '#hmwevents-mb-mode', function () {
      var isParentChildren = $(this).val() === 'parent_children';
      $('#hmwevents-mb-child-fields').toggle(isParentChildren);
      saveJSON();
    });
  }

  function initEventHandlers() {
    $(document).off('click.fb', '.hmwevents-fb-field-card');
    $(document).on('click.fb', '.hmwevents-fb-field-card', function (e) {
      if ($(e.target).closest('.hmwevents-dnd-handle').length) return;
      var sectionId = $(this).closest('.hmwevents-fb-section').data('section-id');
      var key = $(this).data('field-key');
      var fieldData = readFieldFromDom(sectionId, key);
      openFieldModal(sectionId, fieldData);
    });

    $(document).off('click.fb', '.hmwevents-fb-add-field');
    $(document).on('click.fb', '.hmwevents-fb-add-field', function () {
      var sectionId = $(this).closest('.hmwevents-fb-section').data('section-id');
      openFieldModal(sectionId, null);
    });

    $(document).off('click.fb', '.hmwevents-add-section-btn');
    $(document).on('click.fb', '.hmwevents-add-section-btn', function () {
      var section = { id: generateId(), label: 'New Section', fields: [] };
      $('#hmwevents-fb-sections').append(renderSectionHtml(section));
      HmwFormBuilder.initFieldSortable($('#hmwevents-fb-sections').find('.hmwevents-fb-section:last .hmwevents-fb-section-fields'), saveJSON);
      saveJSON();
    });

    $(document).off('click.fb', '.hmwevents-fb-delete-section');
    $(document).on('click.fb', '.hmwevents-fb-delete-section', function () {
      if (!confirm('Delete this section and all its fields?')) return;
      $(this).closest('.hmwevents-fb-section').remove();
      saveJSON();
    });

    $(document).off('blur.fb', '.hmwevents-fb-section-title');
    $(document).on('blur.fb', '.hmwevents-fb-section-title', function () {
      saveJSON();
    });

    $(document).off('change.mb', '#hmwevents-mb-min, #hmwevents-mb-max');
    $(document).on('change.mb', '#hmwevents-mb-min, #hmwevents-mb-max', function () {
      saveJSON();
    });

    $(document).off('change.mb', '#hmwevents-cf-name-enabled, #hmwevents-cf-name-required, #hmwevents-cf-age-enabled, #hmwevents-cf-age-required');
    $(document).on('change.mb', '#hmwevents-cf-name-enabled, #hmwevents-cf-name-required, #hmwevents-cf-age-enabled, #hmwevents-cf-age-required', function () {
      saveJSON();
    });
  }

  function readFieldFromDom(sectionId, key) {
    var sections = readSectionsState();
    for (var i = 0; i < sections.length; i++) {
      if (sections[i].id === sectionId) {
        var fields = sections[i].fields;
        for (var j = 0; j < fields.length; j++) {
          if (fields[j].key === key) return fields[j];
        }
      }
    }
    return null;
  }

  // ================================================================
  // STATE READING
  // ================================================================

  function readAcfFieldState() {
    var hidden = [];
    var required = [];
    var optional = [];

    function addKeys(target, keys) {
      for (var i = 0; i < keys.length; i++) {
        if (target.indexOf(keys[i]) === -1) {
          target.push(keys[i]);
        }
      }
    }

    $('#hmwevents-acf-available .hmwevents-dnd-item').each(function () {
      var $item = $(this);
      if ($item.hasClass('hmwevents-dnd-group')) {
        var groupChildren = getGroupChildren($item);
        addKeys(hidden, groupChildren);
      } else {
        hidden.push($item.data('field-key'));
      }
    });

    $('#hmwevents-acf-included .hmwevents-dnd-item').each(function () {
      var $item = $(this);
      var isRequired = $item.find('.hmwevents-dnd-required input').prop('checked');
      var keys;
      if ($item.hasClass('hmwevents-dnd-group')) {
        keys = getGroupChildren($item);
      } else {
        keys = [$item.data('field-key')];
      }
      if (isRequired) {
        addKeys(required, keys);
      } else {
        addKeys(optional, keys);
      }
    });

    return { hidden: hidden, required: required, optional: optional };
  }

  function getGroupChildren($item) {
    try {
      return JSON.parse($item.attr('data-group-children')) || [];
    } catch (e) {
      return [];
    }
  }

  function readSectionsState() {
    var sections = [];
    $('.hmwevents-fb-section').each(function () {
      var sectionId = $(this).data('section-id');
      var label = $(this).find('.hmwevents-fb-section-title').val() || $(this).data('section-id');
      var fields = [];

      $(this).find('.hmwevents-fb-field-card').each(function () {
        fields.push(HmwFormBuilder.readFieldCardData($(this)));
      });

      sections.push({ id: sectionId, label: label, fields: fields });
    });
    return sections;
  }

  function readMultiBookingState() {
    return {
      enabled: $('#hmwevents-multi-booking-toggle').prop('checked') || false,
      mode: $('#hmwevents-mb-mode').val() || 'attendees',
      min: parseInt($('#hmwevents-mb-min').val(), 10) || 1,
      max: parseInt($('#hmwevents-mb-max').val(), 10) || 10,
      child_fields: {
        name: {
          enabled: $('#hmwevents-cf-name-enabled').prop('checked'),
          required: $('#hmwevents-cf-name-required').prop('checked'),
          label: 'Child Name'
        },
        age: {
          enabled: $('#hmwevents-cf-age-enabled').prop('checked'),
          required: $('#hmwevents-cf-age-required').prop('checked'),
          label: 'Date of Birth'
        }
      }
    };
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
  // PANEL 4: ATTENDANCE OPTIONS EDITOR
  // ================================================================

  function renderAttendanceOptions(container, initialData) {
    var options = (initialData.defaults && initialData.defaults.attendance_options)
      ? initialData.defaults.attendance_options
      : [];

    var initReg = initialData.registration_fields || { multi_booking: { enabled: false, min: 1, max: 10 } };
    var mb = initReg.multi_booking || { enabled: false, min: 1, max: 10 };
    var mbMode = mb.mode || 'attendees';
    var childFields = mb.child_fields || {};
    var cfName = childFields.name || {};
    var cfAge = childFields.age || {};
    var html = '<div class="hmwevents-attendance-multi-booking">';
    html += '<label><input type="checkbox" id="hmwevents-multi-booking-toggle"' + (mb.enabled ? ' checked' : '') + '> <strong>Enable multi-attendee booking</strong></label>';
    html += '<p class="description">Allows customers to register multiple attendees in one booking. Mark fields as "per-attendee" in field settings.</p>';
    html += '<div id="hmwevents-multi-booking-settings"' + (mb.enabled ? '' : ' style="display:none"') + '>';
    html += '<label>Minimum attendees <input type="number" id="hmwevents-mb-min" value="' + (mb.min || 1) + '" min="1" max="100"></label>';
    html += '<label>Maximum attendees <input type="number" id="hmwevents-mb-max" value="' + (mb.max || 10) + '" min="1" max="100"></label>';
    html += '<label style="display:block;margin-top:8px;">Mode <select id="hmwevents-mb-mode">';
    html += '<option value="attendees"' + (mbMode === 'attendees' ? ' selected' : '') + '>Attendees (repeat per-attendee fields)</option>';
    html += '<option value="parent_children"' + (mbMode === 'parent_children' ? ' selected' : '') + '>Parent + Children</option>';
    html += '</select></label>';
    html += '<div id="hmwevents-mb-child-fields"' + (mbMode === 'parent_children' ? '' : ' style="display:none"') + '>';
    html += '<p class="description" style="margin-top:8px;">Child block fields shown for each child. Custom child fields are the per-attendee fields in the form builder.</p>';
    html += '<label><input type="checkbox" id="hmwevents-cf-name-enabled"' + (cfName.enabled !== false ? ' checked' : '') + '> Show child name</label>';
    html += '<label style="margin-left:8px;"><input type="checkbox" id="hmwevents-cf-name-required"' + (cfName.required !== false ? ' checked' : '') + '> Name required</label><br>';
    html += '<label><input type="checkbox" id="hmwevents-cf-age-enabled"' + (cfAge.enabled !== false ? ' checked' : '') + '> Show child age (date of birth)</label>';
    html += '<label style="margin-left:8px;"><input type="checkbox" id="hmwevents-cf-age-required"' + (cfAge.required ? ' checked' : '') + '> Age required</label>';
    html += '</div>';
    html += '</div></div>';
    html += '<ul class="hmwevents-attendance-options-list hmwevents-dnd-sortable" id="hmwevents-attendance-options-list">';
    options.forEach(function (opt) {
      html += buildAttendanceOptionRowHtml(opt);
    });
    html += '</ul>';
    html += '<button type="button" class="button hmwevents-add-attendance-option" style="margin-top:8px;">+ ' + escHtml('Add Option') + '</button>';
    container.html(html);
    initMultiBookingToggle();

    $('#hmwevents-attendance-options-list').sortable({
      handle: '.hmwevents-dnd-handle',
      placeholder: 'hmwevents-dnd-placeholder',
      opacity: 0.6,
      stop: function () { saveJSON(); }
    }).disableSelection();

    initRowDescriptionEditors($('#hmwevents-attendance-options-list'));
  }

  function attendanceTypeHint(type) {
    var hints = {
      individual: 'Individual — 1 attendee per booking.',
      parent: 'Parent — 1 attendee per booking.',
      parent_child: 'Parent + Child — requires at least 1 adult and 1 child per booking.',
      couple: 'Couple — requires 2 adults per booking.',
      professional: 'Professional — 1 attendee per booking.'
    };
    return hints[type] || hints.individual;
  }

  var attendanceRowCounter = 0;

  function initDescriptionQuickTags(id) {
    if (typeof window.quicktags === 'function') {
      quicktags({ id: id, buttons: 'strong,em,ul,ol,li,link' });
    } else if (typeof window.QTags === 'function') {
      new QTags({ id: id, buttons: 'strong,em,ul,ol,li,link' });
    }
  }

  function initRowDescriptionEditors($rows) {
    $rows.find('textarea.hmwevents-attendance-option-description').each(function () {
      var id = this.id;
      if (id && document.getElementById(id) && !(window.QTags && QTags.getInstance && QTags.getInstance(id))) {
        initDescriptionQuickTags(id);
      }
    });
  }

  function buildAttendanceOptionRowHtml(opt) {
    opt = opt || {};
    var type = opt.option_type || 'individual';
    var label = opt.label || '';
    var description = opt.description || '';
    var price = (opt.price !== undefined && opt.price !== null) ? opt.price : '';
    var capacity = (opt.capacity !== undefined && opt.capacity !== null) ? opt.capacity : '';
    var mode = opt.price_mode || 'flat';
    var rules = opt.pricing_rules || [];

    var adultPrice = '';
    var childPrice = '';
    rules.forEach(function (r) {
      if ((r.role || 'any') === 'adult') adultPrice = r.price;
      if ((r.role || 'any') === 'child') childPrice = r.price;
    });
    var ageBands = (mode === 'age_band' && rules.length) ? rules : [{ role: 'any', min_age: 0, max_age: '', price: 0 }];

    var html = '<li class="hmwevents-attendance-option-row">';
    html += '<span class="hmwevents-dnd-handle">&#9776;</span>';
    html += '<div class="hmwevents-attendance-option-main">';
    html += '<select class="hmwevents-attendance-option-type">';
    Object.keys(ATTENDANCE_OPTION_TYPES).forEach(function (t) {
      html += '<option value="' + t + '"' + (t === type ? ' selected' : '') + '>' + escHtml(ATTENDANCE_OPTION_TYPES[t]) + '</option>';
    });
    html += '</select>';
    html += '<input type="text" class="hmwevents-attendance-option-label" placeholder="' + escAttr('Label') + '" value="' + escAttr(label) + '">';
    html += '<select class="hmwevents-attendance-option-mode">';
    html += '<option value="flat"' + (mode === 'flat' ? ' selected' : '') + '>Flat price</option>';
    html += '<option value="per_attendee"' + (mode === 'per_attendee' ? ' selected' : '') + '>Per attendee</option>';
    html += '<option value="age_band"' + (mode === 'age_band' ? ' selected' : '') + '>Age bands</option>';
    html += '</select>';
    html += '</div>';
    html += '<p class="hmwevents-attendance-option-hint">' + escHtml(attendanceTypeHint(type)) + '</p>';

    html += '<div class="hmwevents-attendance-panel hmwevents-attendance-panel--flat"' + (mode === 'flat' ? '' : ' style="display:none"') + '>';
    html += '<p class="hmwevents-attendance-panel-help">One price for the complete booking.</p>';
    html += '<label class="hmwevents-attendance-control"><span>Booking price ($)</span><input type="number" class="hmwevents-attendance-option-price" min="0" step="0.01" value="' + escAttr(price) + '"></label>';
    html += '</div>';

    html += '<div class="hmwevents-attendance-panel hmwevents-attendance-panel--per_attendee"' + (mode === 'per_attendee' ? '' : ' style="display:none"') + '>';
    html += '<p class="hmwevents-attendance-panel-help">Each attendee is charged the Adult or Child price below, based on their Attendee Type.</p>';
    html += '<div class="hmwevents-attendance-control-grid">';
    html += '<label class="hmwevents-attendance-control"><span>Adult / parent ($)</span><input type="number" class="hmwevents-attendance-adult-price" min="0" step="0.01" value="' + escAttr(adultPrice) + '"></label>';
    html += '<label class="hmwevents-attendance-control"><span>Child ($)</span><input type="number" class="hmwevents-attendance-child-price" min="0" step="0.01" value="' + escAttr(childPrice) + '"></label>';
    html += '</div>';
    html += '</div>';

    html += '<div class="hmwevents-attendance-panel hmwevents-attendance-panel--age_band"' + (mode === 'age_band' ? '' : ' style="display:none"') + '>';
    html += '<p class="hmwevents-attendance-panel-help">Set the price for each age range. Select whether the range applies to adults, children, or everyone.</p>';
    html += '<div class="hmwevents-attendance-age-bands">';
    ageBands.forEach(function (rule) {
      html += buildAgeBandRuleHtml(rule);
    });
    html += '</div>';
    html += '<button type="button" class="button button-small hmwevents-add-age-band-rule">+ Add age band</button>';
    html += '</div>';

    html += '<label class="hmwevents-attendance-capacity"><span>Maximum bookings</span><input type="number" class="hmwevents-attendance-option-capacity" min="0" step="1" value="' + escAttr(capacity) + '"><small>Leave blank for unlimited</small></label>';
    html += '<label class="hmwevents-attendance-description"><span>Description</span><textarea id="hmwevents-att-desc-' + (++attendanceRowCounter) + '" class="hmwevents-attendance-option-description" rows="3" placeholder="' + escAttr('Optional description shown with this option on the booking form. Basic HTML allowed.') + '">' + escHtml(description) + '</textarea></label>';
    html += '<button type="button" class="button-link-delete hmwevents-remove-attendance-option">&times;</button>';
    html += '</li>';
    return html;
  }

  function buildAgeBandRuleHtml(rule) {
    rule = rule || {};
    var role = rule.role || 'any';
    var minAge = (rule.min_age !== undefined && rule.min_age !== null) ? rule.min_age : 0;
    var maxAge = (rule.max_age !== undefined && rule.max_age !== null) ? rule.max_age : '';
    var price = (rule.price !== undefined && rule.price !== null) ? rule.price : 0;

    var html = '<div class="hmwevents-age-band-rule">';
    html += '<label class="hmwevents-age-band-control"><span>Applies to</span><select class="hmwevents-age-band-role">';
    html += '<option value="any"' + (role === 'any' ? ' selected' : '') + '>Any</option>';
    html += '<option value="adult"' + (role === 'adult' ? ' selected' : '') + '>Adult</option>';
    html += '<option value="child"' + (role === 'child' ? ' selected' : '') + '>Child</option>';
    html += '</select></label>';
    html += '<label class="hmwevents-age-band-control"><span>From age</span><span class="hmwevents-age-band-field"><input type="number" class="hmwevents-age-band-min" min="0" step="1" value="' + escAttr(minAge) + '"><small>years</small></span></label>';
    html += '<label class="hmwevents-age-band-control"><span>To age</span><span class="hmwevents-age-band-field"><input type="number" class="hmwevents-age-band-max" min="0" step="1" value="' + escAttr(maxAge) + '"><small>blank = no limit</small></span></label>';
    html += '<label class="hmwevents-age-band-control"><span>Price per attendee ($)</span><input type="number" class="hmwevents-age-band-price" min="0" step="0.01" value="' + escAttr(price) + '"></label>';
    html += '<button type="button" class="hmwevents-age-band-remove" aria-label="Remove age band">&times;</button>';
    html += '</div>';
    return html;
  }

  function readAttendanceOptionsState() {
    var options = [];
    $('#hmwevents-attendance-options-list .hmwevents-attendance-option-row').each(function () {
      var $row = $(this);
      var label = $row.find('.hmwevents-attendance-option-label').val().trim();
      if (!label) return;

      var mode = $row.find('.hmwevents-attendance-option-mode').val() || 'flat';

      var price = parseFloat($row.find('.hmwevents-attendance-option-price').val());
      if (isNaN(price)) price = 0;

      var capacityRaw = $row.find('.hmwevents-attendance-option-capacity').val();
      var capacity = (capacityRaw === '' || capacityRaw === null) ? null : parseInt(capacityRaw, 10);
      if (isNaN(capacity)) capacity = null;

      var option = {
        option_type: $row.find('.hmwevents-attendance-option-type').val() || 'individual',
        label: label,
        description: ($row.find('.hmwevents-attendance-option-description').val() || '').trim(),
        price: price,
        capacity: capacity,
        price_mode: mode,
        pricing_rules: []
      };

      if (mode === 'per_attendee') {
        var adult = parseFloat($row.find('.hmwevents-attendance-adult-price').val());
        var child = parseFloat($row.find('.hmwevents-attendance-child-price').val());
        option.pricing_rules = [
          { role: 'adult', min_age: 0, max_age: null, price: isNaN(adult) ? 0 : adult },
          { role: 'child', min_age: 0, max_age: null, price: isNaN(child) ? 0 : child }
        ];
      } else if (mode === 'age_band') {
        $row.find('.hmwevents-age-band-rule').each(function () {
          var $rule = $(this);
          var minAge = parseInt($rule.find('.hmwevents-age-band-min').val(), 10);
          var maxAgeRaw = $rule.find('.hmwevents-age-band-max').val();
          var maxAge = (maxAgeRaw === '' || maxAgeRaw === null) ? null : parseInt(maxAgeRaw, 10);
          var rulePrice = parseFloat($rule.find('.hmwevents-age-band-price').val());
          option.pricing_rules.push({
            role: $rule.find('.hmwevents-age-band-role').val() || 'any',
            min_age: isNaN(minAge) ? 0 : minAge,
            max_age: isNaN(maxAge) ? null : maxAge,
            price: isNaN(rulePrice) ? 0 : rulePrice
          });
        });
      }

      options.push(option);
    });
    return options;
  }

  // ================================================================
  // JSON ASSEMBLY
  // ================================================================

  function assembleJSON() {
    var templateId = parseInt($('input[name="template_id"]').val(), 10) || 0;
    var acfState = readAcfFieldState();
    var sections = readSectionsState();
    var multiBooking = readMultiBookingState();
    var metaDefaults = readEventMetaDefaults();

    var data = {
      schema_version: 3,
      template_version: 1,
      post: {
        title_pattern: $('#hmwevents_title_pattern').val() || '',
        post_content: $('#hmwevents_post_content').val() || '',
        post_title: ''
      },
      event_fields: {
        required: acfState.required,
        optional: acfState.optional,
        hidden: acfState.hidden
      },
      registration_fields: {
        sections: sections,
        multi_booking: multiBooking
      },
      defaults: {
        event_meta: metaDefaults,
        registration: {
          attendance_default: 'individual',
          field_overrides: {}
        },
        attendance_options: readAttendanceOptionsState()
      }
    };

    if (templateId > 0) {
      var raw = $('#hmwevents_template_data_raw').val();
      try {
        var prev = JSON.parse(raw);
        if (prev && prev.template_version) {
          data.template_version = parseInt(prev.template_version, 10) || 1;
        }
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

    if (preset.required_fields || preset.hidden_fields) {
      var hiddenSet = {};
      var requiredSet = {};
      (preset.hidden_fields || []).forEach(function (k) { hiddenSet[k] = true; });
      (preset.required_fields || []).forEach(function (k) { requiredSet[k] = true; });

      $('#hmwevents-acf-included .hmwevents-dnd-item').each(function () {
        var $item = $(this);
        var shouldHide = false;
        if ($item.hasClass('hmwevents-dnd-group')) {
          var children = getGroupChildren($item);
          shouldHide = children.length > 0 && children.every(function (c) { return hiddenSet[c]; });
        } else {
          shouldHide = hiddenSet[$item.data('field-key')];
        }
        if (shouldHide) {
          $item.find('.hmwevents-dnd-required input').prop('checked', false);
          $item.appendTo('#hmwevents-acf-available');
        }
      });

      $('#hmwevents-acf-available .hmwevents-dnd-item').each(function () {
        var $item = $(this);
        var shouldShow = false;
        if ($item.hasClass('hmwevents-dnd-group')) {
          var children = getGroupChildren($item);
          shouldShow = children.length === 0 || !children.every(function (c) { return hiddenSet[c]; });
        } else {
          shouldShow = !hiddenSet[$item.data('field-key')];
        }
        if (shouldShow) {
          $item.appendTo('#hmwevents-acf-included');
          if ($item.hasClass('hmwevents-dnd-group')) {
            var gChildren = getGroupChildren($item);
            var allReq = gChildren.every(function (c) { return requiredSet[c]; });
            if (allReq) {
              $item.find('.hmwevents-dnd-required input').prop('checked', true);
            }
          } else {
            if (requiredSet[$item.data('field-key')]) {
              $item.find('.hmwevents-dnd-required input').prop('checked', true);
            }
          }
        }
      });

      $('#hmwevents-acf-included .hmwevents-dnd-item').each(function () {
        var $item = $(this);
        var shouldCheck = false;
        if ($item.hasClass('hmwevents-dnd-group')) {
          var children = getGroupChildren($item);
          shouldCheck = children.length > 0 && children.every(function (c) { return requiredSet[c]; });
        } else {
          shouldCheck = requiredSet[$item.data('field-key')];
        }
        if (shouldCheck) {
          $item.find('.hmwevents-dnd-required input').prop('checked', true);
        }
      });
    }

    if (preset.default_meta) {
      Object.keys(preset.default_meta).forEach(function (k) {
        var input = $('.hmwevents-meta-default[data-field-key="' + k + '"]');
        if (input.length) input.val(preset.default_meta[k]);
      });
    }

    if (preset.attendance_option_presets && preset.attendance_option_presets.length) {
      var currentOptions = readAttendanceOptionsState();
      if (currentOptions.length === 0) {
        $('#hmwevents-attendance-options-list').empty();
        preset.attendance_option_presets.forEach(function (opt) {
          $('#hmwevents-attendance-options-list').append(buildAttendanceOptionRowHtml(opt));
        });
        saveJSON();
      }
    }

    notice('success', 'Presets loaded for <strong>' + escHtml(slug) + '</strong>. Review and adjust as needed.');
  }

  // ================================================================
  // INITIALIZATION
  // ================================================================

  function init() {
    var rawJson = $('#hmwevents_template_data_raw').val();
    var initialData = {};
    try { initialData = JSON.parse(rawJson); } catch (e) {}

    if (initialData.schema_version < 3) {
      initialData.schema_version = 3;
      initialData.registration_fields = initialData.registration_fields || { sections: [], multi_booking: { enabled: false, min: 1, max: 10 } };
    }

    var initMeta = (initialData.defaults && initialData.defaults.event_meta) ? initialData.defaults.event_meta : {};

    renderAcfDndPanel($('#hmwevents-event-fields-container'), initialData);
    renderFormBuilder($('#hmwevents-registration-fields-container'), initialData);
    renderEventMetaDefaults();
    renderAttendanceOptions($('#hmwevents-attendance-options-container'), initialData);

    if (initMeta) {
      Object.keys(initMeta).forEach(function (k) {
        var input = $('.hmwevents-meta-default[data-field-key="' + k + '"]');
        if (input.length) input.val(initMeta[k]);
      });
    }

    if (initialData.post) {
      $('#hmwevents_title_pattern').val(initialData.post.title_pattern || '');
      $('#hmwevents_post_content').val(initialData.post.post_content || '');
    }

    $('#hmwevents_template_data_raw_display').text(JSON.stringify(assembleJSON(), null, 2));
    saveJSON();
    initEventHandlers();
  }

  function renderEventMetaDefaults() {
    var fields = window.hmwEventTemplates.acfEventFields;
    if (!fields || !fields.length) return;
    var defaults = readEventMetaDefaults();
    var container = $('#hmwevents-event-meta-defaults');
    var html = '<table class="widefat striped"><thead><tr><th>Field</th><th>Type</th><th>Default Value</th></tr></thead><tbody>';

    fields.forEach(function (f) {
      if (f.type === 'group') {
        return;
      }
      var current = defaults.hasOwnProperty(f.key) ? defaults[f.key] : '';
      if (current === null || current === undefined) current = '';

      var typeHint = 'text';
      if (f.type === 'number') { typeHint = 'number'; }
      if (f.type === 'true_false') {
        html += '<tr><td><strong>' + escHtml(f.label) + '</strong> <code>' + escHtml(f.key) + '</code></td><td>' + escHtml(f.type) + '</td>';
        html += '<td><select class="hmwevents-meta-default" data-field-key="' + escAttr(f.key) + '">';
        html += '<option value="">(no default)</option>';
        html += '<option value="1"' + (current === '1' || current === 1 ? ' selected' : '') + '>Yes (1)</option>';
        html += '<option value="0"' + (current === '0' || current === 0 ? ' selected' : '') + '>No (0)</option>';
        html += '</select></td></tr>';
        return;
      }
      html += '<tr><td><strong>' + escHtml(f.label) + '</strong> <code>' + escHtml(f.key) + '</code></td><td>' + escHtml(f.type) + '</td>';
      html += '<td><input type="' + typeHint + '" class="regular-text hmwevents-meta-default" data-field-key="' + escAttr(f.key) + '" value="' + escAttr(String(current)) + '"></td></tr>';
    });

    html += '</tbody></table>';
    container.html(html);
  }

  // ================================================================
  // GLOBAL EVENT HANDLERS
  // ================================================================

  $(document).on('change', '.hmwevents-meta-default', function () {
    saveJSON();
  });

  $(document).on('click', '.hmwevents-add-attendance-option', function () {
    var $list = $('#hmwevents-attendance-options-list');
    $list.append(buildAttendanceOptionRowHtml({
      option_type: 'individual',
      label: '',
      price: '',
      capacity: ''
    }));
    initRowDescriptionEditors($list);
    saveJSON();
  });

  $(document).on('click', '.quicktags-toolbar', function () {
    window.setTimeout(saveJSON, 0);
  });

  $(document).on('click', '.hmwevents-remove-attendance-option', function () {
    $(this).closest('.hmwevents-attendance-option-row').remove();
    saveJSON();
  });

  $(document).on('change input', '.hmwevents-attendance-option-type, .hmwevents-attendance-option-label, .hmwevents-attendance-option-description, .hmwevents-attendance-option-price, .hmwevents-attendance-option-capacity, .hmwevents-attendance-option-mode, .hmwevents-attendance-adult-price, .hmwevents-attendance-child-price, .hmwevents-age-band-role, .hmwevents-age-band-min, .hmwevents-age-band-max, .hmwevents-age-band-price', function () {
    saveJSON();
  });

  $(document).on('change', '.hmwevents-attendance-option-mode', function () {
    var $row = $(this).closest('.hmwevents-attendance-option-row');
    var mode = $(this).val();
    $row.find('.hmwevents-attendance-panel').hide();
    $row.find('.hmwevents-attendance-panel--' + mode).show();
  });

  $(document).on('change', '.hmwevents-attendance-option-type', function () {
    var $row = $(this).closest('.hmwevents-attendance-option-row');
    $row.find('.hmwevents-attendance-option-hint').text(attendanceTypeHint($(this).val()));
  });

  $(document).on('click', '.hmwevents-add-age-band-rule', function () {
    $(this).siblings('.hmwevents-attendance-age-bands').append(buildAgeBandRuleHtml({ role: 'any', min_age: 0, max_age: '', price: 0 }));
    saveJSON();
  });

  $(document).on('click', '.hmwevents-age-band-remove', function () {
    $(this).closest('.hmwevents-age-band-rule').remove();
    saveJSON();
  });

  $(document).on('change input', '#hmwevents_title_pattern, #hmwevents_post_content', function () {
    saveJSON();
  });

  $(document).on('change', '#hmwevents_template_type', function () {
    var slug = $(this).val();
    if (slug) loadEventTypePresets(slug);
  });

  $(document).on('change', '.hmwevents-dnd-required input', function () {
    saveJSON();
  });

  $(document).on('submit', '#hmwevents-template-form', function () {
    var $raw = $('#hmwevents-raw-json');
    if ($raw.length && $('#hmwevents-advanced-edit').is(':visible') && $raw.val().trim() !== '') {
      $('#hmwevents_template_data_raw').val($raw.val());
    }
    saveJSON();
    return true;
  });

  $(document).on('click', '.hmwevents-toggle-json-preview', function (e) {
    e.preventDefault();
    $('#hmwevents-json-preview').slideToggle();
    $(this).text($(this).text() === 'Show JSON' ? 'Hide JSON' : 'Show JSON');
  });

  $(document).on('click', '.hmwevents-toggle-advanced', function (e) {
    e.preventDefault();
    var $area = $('#hmwevents-advanced-edit');
    $area.slideToggle();
    $(this).text($area.is(':visible') ? 'Hide advanced editor' : 'Show advanced editor');

    var $raw = $('#hmwevents-raw-json');
    if ($area.is(':visible') && $raw.length) {
      var raw = $('#hmwevents_template_data_raw').val() || '{}';
      try {
        var parsed = JSON.parse(raw);
        $raw.val(JSON.stringify(parsed, null, 2));
      } catch (ex) {
        $raw.val(raw);
      }
    }
  });

  // ================================================================
  // BOOT
  // ================================================================

  $(function () {
    if ($('#hmwevents-event-fields-container').length) {
      init();
    }
  });

})(jQuery);
