(function ($) {
  'use strict';

  var statusEl;
  var currentModalSectionId = '';
  var currentModalFieldKey = '';

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
      if (hiddenSet[f.key]) {
        availableHtml += buildAcfItemHtml(f, false);
      } else {
        includedHtml += buildAcfItemHtml(f, requiredSet[f.key]);
      }
    });

    fields.forEach(function (f) {
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
    var reqId = 'acf-req-' + escAttr(f.key);
    return '<li class="hmwevents-dnd-item" data-field-key="' + escAttr(f.key) + '">' +
      '<span class="hmwevents-dnd-handle">&#9776;</span>' +
      '<span class="hmwevents-dnd-label"><strong>' + escHtml(f.label) + '</strong> <code>' + escHtml(f.key) + '</code></span>' +
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
    var mb = initReg.multi_booking || { enabled: false, min: 1, max: 10 };

    var html = '';

    html += '<div style="margin-bottom:12px; padding:8px 12px; background:#f0f6fc; border-left:4px solid #2271b1;">';
    html += '<label><input type="checkbox" id="hmwevents-multi-booking-toggle"' + (mb.enabled ? ' checked' : '') + '> <strong>Enable multi-attendee booking</strong></label>';
    html += '<p class="description" style="margin:4px 0 0 20px;">Allows customers to register multiple attendees in one booking. Mark fields as "per-attendee" in field settings.</p>';
    html += '<div id="hmwevents-multi-booking-settings"' + (mb.enabled ? '' : ' style="display:none"') + '>';
    html += '<label style="margin-top:8px;display:inline-block;">Min attendees: <input type="number" id="hmwevents-mb-min" value="' + (mb.min || 1) + '" min="1" max="100" style="width:60px;"></label>';
    html += '<label style="margin-left:12px;">Max attendees: <input type="number" id="hmwevents-mb-max" value="' + (mb.max || 10) + '" min="1" max="100" style="width:60px;"></label>';
    html += '</div></div>';

    html += '<div id="hmwevents-fb-sections">';
    sections.forEach(function (section) {
      html += renderSectionHtml(section);
    });
    html += '</div>';

    html += '<button type="button" class="button hmwevents-add-section-btn" style="margin-top:4px;">+ Add Section</button>';
    container.html(html);

    initSectionsSortable();
    initFieldsSortable();
    initMultiBookingToggle();

    $('#hmwevents-fb-sections .hmwevents-fb-section-fields').each(function () {
      initSectionFieldSortable($(this));
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
      html += renderFieldCardHtml(field);
    });
    html += '</div>';
    html += '<button type="button" class="hmwevents-fb-add-field button button-small">+ Add Field</button>';
    html += '</div>';
    return html;
  }

function renderFieldCardHtml(field) {
    var widthClass = field.width === 'half' ? 'hmwevents-fb-field--half' : 'hmwevents-fb-field--full';
    var reqMark = field.required ? ' <span class="hmwevents-fb-field-req">*</span>' : '';
    var perAttendeeIcon = field.per_attendee ? '<span class="hmwevents-fb-field-per-attendee" title="Per attendee">&#128101;</span>' : '';
    var typeBadge = '<span class="hmwevents-fb-field-type">' + escHtml(field.type || 'text') + '</span>';
    var presetAttr = field.preset ? ' data-preset="1"' : '';
    var sourceAttr = ' data-source="' + escAttr(field.source || 'booking_details') + '"';
    var metaKeyAttr = field.meta_key ? ' data-meta-key="' + escAttr(field.meta_key) + '"' : '';

    return '<div class="hmwevents-fb-field-card ' + widthClass + '" data-field-key="' + escAttr(field.key) + '"' + presetAttr + sourceAttr + metaKeyAttr + '>' +
      '<div class="hmwevents-fb-field-card-inner">' +
      '<span class="hmwevents-dnd-handle">&#9776;</span>' +
      '<span class="hmwevents-fb-field-label">' + escHtml(field.label) + reqMark + '</span>' +
      typeBadge + perAttendeeIcon +
      '</div></div>';
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
      initSectionFieldSortable($(this));
    });
  }

  function initSectionFieldSortable(el) {
    el.sortable({
      handle: '.hmwevents-dnd-handle',
      placeholder: 'hmwevents-fb-grid-placeholder',
      opacity: 0.6,
      start: function (event, ui) {
        ui.placeholder.addClass(ui.item.hasClass('hmwevents-fb-field--full') ? 'hmwevents-fb-field--full' : '');
      },
      sort: function (event, ui) {
        handleSplitIndicator(event, ui);
      },
      stop: function (event, ui) {
        $('.hmwevents-fb-split-indicator').remove();
        handleSplitDrop(ui);
        var pairedItem = ui.item.data('split-paired');
        if (pairedItem) {
          pairedItem.removeClass('hmwevents-fb-field--full').addClass('hmwevents-fb-field--half').data('split-paired', null);
        }
        ui.item.removeData('split-paired');

        var leftPair = ui.item.next('.hmwevents-fb-field--half');
        if (leftPair.length && !ui.item.hasClass('hmwevents-fb-field--full')) {
          ui.item.addClass('hmwevents-fb-field--half').removeClass('hmwevents-fb-field--full');
        }

        saveJSON();
      }
    }).disableSelection();
  }

  function handleSplitIndicator(event, ui) {
    $('.hmwevents-fb-split-indicator').remove();
    var dragged = ui.item;
    var hovered = null;
    var hoverX = event.clientX;

    $('.hmwevents-fb-field-card').not(dragged).each(function () {
      var rect = this.getBoundingClientRect();
      if (hoverX >= rect.left && hoverX <= rect.right && event.clientY >= rect.top && event.clientY <= rect.bottom) {
        hovered = $(this);
        return false;
      }
    });

    if (hovered && hovered.hasClass('hmwevents-fb-field--full') && dragged.hasClass('hmwevents-fb-field--full')) {
      var rect = hovered[0].getBoundingClientRect();
      var relX = event.clientX - rect.left;
      if (relX > rect.width * 0.6) {
        hovered.css('position', 'relative');
        var indicator = $('<div class="hmwevents-fb-split-indicator" style="position:absolute;right:0;top:0;bottom:0;width:40%;border-left:2px dashed #2271b1;background:rgba(34,113,177,0.08);pointer-events:none;"></div>');
        hovered.append(indicator);
        dragged.data('split-target', hovered);
      }
    }
  }

  function handleSplitDrop(ui) {
    var target = ui.item.data('split-target');
    if (target && target.length) {
      target.removeClass('hmwevents-fb-field--full').addClass('hmwevents-fb-field--half');
      ui.item.removeClass('hmwevents-fb-field--full').addClass('hmwevents-fb-field--half');
      ui.item.data('split-target', null);
    }
  }

  // ================================================================
  // THICKBOX MODAL
  // ================================================================

  function openFieldModal(sectionId, fieldData) {
    currentModalSectionId = sectionId;
    currentModalFieldKey = fieldData ? fieldData.key : '';

    var isNew = !fieldData;
    var presetBtns = $('#hmwevents-modal-preset-buttons');
    if (presetBtns.children().length === 0) {
      var presets = window.hmwEventTemplates.registrationPresets || {};
      Object.keys(presets).forEach(function (key) {
        var p = presets[key];
        var btn = $('<button type="button" class="button button-small hmwevents-preset-btn">')
          .text(p.label + ' (' + p.type + ')')
          .data('preset-key', key)
          .on('click', function () { applyPreset(key); });
        presetBtns.append(btn);
      });
    }

    if (isNew) {
      $('#hmwevents-modal-presets').show();
    }

    var f = fieldData || { key: '', label: '', placeholder: '', type: 'text', required: false, width: 'full', per_attendee: false, options: [] };
    $('#hmwevents-modal-key').val(f.key || '');
    $('#hmwevents-modal-label').val(f.label || '');
    $('#hmwevents-modal-placeholder').val(f.placeholder || '');
    $('#hmwevents-modal-type').val(f.type || 'text');
    $('#hmwevents-modal-required').prop('checked', !!f.required);
    $('input[name="hmwevents-modal-width"][value="' + (f.width || 'full') + '"]').prop('checked', true);
    $('#hmwevents-modal-per-attendee').prop('checked', !!f.per_attendee);

    if (fieldData && fieldData.preset) {
      $('#hmwevents-modal-key').prop('readonly', true);
    } else {
      $('#hmwevents-modal-key').prop('readonly', false);
    }

    updateModalOptionsPanel();
    renderModalOptions(f.options || []);

    var mbEnabled = $('#hmwevents-multi-booking-toggle').prop('checked');
    $('#hmwevents-modal-per-attendee-row').toggle(mbEnabled);

    tb_show('Field Settings', '#TB_inline?width=520&height=480&inlineId=hmwevents-field-modal');
  }

  function applyPreset(presetKey) {
    var presets = window.hmwEventTemplates.registrationPresets || {};
    var p = presets[presetKey];
    if (!p) return;

    $('#hmwevents-modal-key').val(p.key).prop('readonly', true);
    $('#hmwevents-modal-label').val(p.label);
    $('#hmwevents-modal-type').val(p.type);
    $('#hmwevents-modal-required').prop('checked', !!p.required);
    $('input[name="hmwevents-modal-width"][value="' + (p.width || 'full') + '"]').prop('checked', true);
    updateModalOptionsPanel();
  }

  function updateModalOptionsPanel() {
    var type = $('#hmwevents-modal-type').val();
    var showOptions = ['select', 'checkbox', 'radio'].indexOf(type) !== -1;
    $('#hmwevents-modal-options-panel').toggle(showOptions);
  }

  function renderModalOptions(options) {
    var list = $('#hmwevents-modal-options-list');
    list.empty();
    (options || []).forEach(function (opt, i) {
      var value = typeof opt === 'string' ? opt : (opt.value || '');
      var label = typeof opt === 'string' ? opt : (opt.label || '');
      list.append(
        '<li style="display:flex;align-items:center;gap:6px;padding:4px 0;border-bottom:1px solid #f0f0f1;">' +
        '<span class="hmwevents-dnd-handle" style="cursor:grab;">&#9776;</span>' +
        '<input type="text" class="hmwevents-option-value" value="' + escAttr(value) + '" placeholder="Value" style="flex:1;padding:3px 6px;font-size:12px;">' +
        '<input type="text" class="hmwevents-option-label" value="' + escAttr(label) + '" placeholder="Label" style="flex:1;padding:3px 6px;font-size:12px;">' +
        '<button type="button" class="hmwevents-delete-option" style="flex-shrink:0;background:none;border:none;color:#b32d2e;cursor:pointer;font-size:14px;">&times;</button>' +
        '</li>'
      );
    });

    list.sortable({
      handle: '.hmwevents-dnd-handle',
      opacity: 0.6
    }).disableSelection();
  }

  function collectModalOptions() {
    var opts = [];
    $('#hmwevents-modal-options-list li').each(function () {
      var val = $(this).find('.hmwevents-option-value').val();
      var lbl = $(this).find('.hmwevents-option-label').val();
      if (val || lbl) {
        opts.push({ value: val, label: lbl || val });
      }
    });
    return opts;
  }

  function collectModalFieldData() {
    return {
      key: $('#hmwevents-modal-key').val().trim(),
      label: $('#hmwevents-modal-label').val().trim(),
      placeholder: $('#hmwevents-modal-placeholder').val().trim(),
      type: $('#hmwevents-modal-type').val(),
      required: $('#hmwevents-modal-required').prop('checked'),
      width: $('input[name="hmwevents-modal-width"]:checked').val() || 'full',
      per_attendee: $('#hmwevents-modal-per-attendee').prop('checked'),
      preset: $('#hmwevents-modal-key').prop('readonly'),
      source: $('#hmwevents-modal-key').prop('readonly') ? 'registrant_meta' : 'booking_details',
      meta_key: $('#hmwevents-modal-key').prop('readonly') ? ('registrant_' + $('#hmwevents-modal-key').val()) : null,
      options: collectModalOptions()
    };
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
      initSectionFieldSortable($('#hmwevents-fb-sections').find('.hmwevents-fb-section:last .hmwevents-fb-section-fields'));
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

    $(document).off('click.fb', '#hmwevents-modal-save');
    $(document).on('click.fb', '#hmwevents-modal-save', function () {
      var data = collectModalFieldData();
      if (!data.key || !data.label) {
        alert('Field key and label are required.');
        return;
      }

      var sectionEl = $('.hmwevents-fb-section[data-section-id="' + currentModalSectionId + '"]');
      var fieldsDiv = sectionEl.find('.hmwevents-fb-section-fields');

      if (currentModalFieldKey) {
        var existingCard = fieldsDiv.find('.hmwevents-fb-field-card[data-field-key="' + currentModalFieldKey + '"]');
        if (existingCard.length) {
          existingCard.replaceWith(renderFieldCardHtml(data));
        }
      } else {
        var allKeys = [];
        $('.hmwevents-fb-field-card').each(function () { allKeys.push($(this).data('field-key')); });
        if (allKeys.indexOf(data.key) !== -1) {
          alert('A field with key "' + data.key + '" already exists.');
          return;
        }
        fieldsDiv.append(renderFieldCardHtml(data));
      }

      fieldsDiv.sortable('refresh');
      tb_remove();
      saveJSON();
    });

    $(document).off('click.fb', '#hmwevents-modal-delete');
    $(document).on('click.fb', '#hmwevents-modal-delete', function () {
      if (!currentModalFieldKey) return;
      if (!confirm('Delete this field?')) return;

      $('.hmwevents-fb-field-card[data-field-key="' + currentModalFieldKey + '"]').remove();
      tb_remove();
      saveJSON();
    });

    $(document).off('change.fb', '#hmwevents-modal-type');
    $(document).on('change.fb', '#hmwevents-modal-type', function () {
      updateModalOptionsPanel();
    });

    $(document).off('click.fb', '#hmwevents-modal-add-option');
    $(document).on('click.fb', '#hmwevents-modal-add-option', function () {
      var list = $('#hmwevents-modal-options-list');
      list.append(
        '<li style="display:flex;align-items:center;gap:6px;padding:4px 0;border-bottom:1px solid #f0f0f1;">' +
        '<span class="hmwevents-dnd-handle" style="cursor:grab;">&#9776;</span>' +
        '<input type="text" class="hmwevents-option-value" value="" placeholder="Value" style="flex:1;padding:3px 6px;font-size:12px;">' +
        '<input type="text" class="hmwevents-option-label" value="" placeholder="Label" style="flex:1;padding:3px 6px;font-size:12px;">' +
        '<button type="button" class="hmwevents-delete-option" style="flex-shrink:0;background:none;border:none;color:#b32d2e;cursor:pointer;font-size:14px;">&times;</button>' +
        '</li>'
      );
    });

    $(document).off('click.fb', '.hmwevents-delete-option');
    $(document).on('click.fb', '.hmwevents-delete-option', function () {
      $(this).closest('li').remove();
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

    $('#hmwevents-acf-available .hmwevents-dnd-item').each(function () {
      hidden.push($(this).data('field-key'));
    });

    $('#hmwevents-acf-included .hmwevents-dnd-item').each(function () {
      var key = $(this).data('field-key');
      var isRequired = $(this).find('.hmwevents-dnd-required input').prop('checked');
      if (isRequired) required.push(key);
      else optional.push(key);
    });

    return { hidden: hidden, required: required, optional: optional };
  }

  function readSectionsState() {
    var sections = [];
    $('.hmwevents-fb-section').each(function () {
      var sectionId = $(this).data('section-id');
      var label = $(this).find('.hmwevents-fb-section-title').val() || $(this).data('section-id');
      var fields = [];

      $(this).find('.hmwevents-fb-field-card').each(function () {
        var index = fields.length;
        var card = $(this);
        var field = {
          key: card.data('field-key'),
          label: card.find('.hmwevents-fb-field-label').text().replace(' *', '').trim(),
          type: card.find('.hmwevents-fb-field-type').text().trim() || 'text',
          required: card.find('.hmwevents-fb-field-req').length > 0,
          placeholder: '',
          width: card.hasClass('hmwevents-fb-field--full') ? 'full' : 'half',
          source: card.data('source') || 'booking_details',
          meta_key: card.data('meta-key') || null,
          preset: card.data('preset') === 1 || card.data('preset') === '1',
          per_attendee: card.find('.hmwevents-fb-field-per-attendee').length > 0,
          options: []
        };
        fields.push(field);
      });

      sections.push({ id: sectionId, label: label, fields: fields });
    });
    return sections;
  }

  function readMultiBookingState() {
    return {
      enabled: $('#hmwevents-multi-booking-toggle').prop('checked') || false,
      min: parseInt($('#hmwevents-mb-min').val(), 10) || 1,
      max: parseInt($('#hmwevents-mb-max').val(), 10) || 10
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
        attendance_options: []
      }
    };

    if (templateId > 0) {
      var raw = $('#hmwevents_template_data_raw').val();
      try {
        var prev = JSON.parse(raw);
        if (prev && prev.template_version) {
          data.template_version = parseInt(prev.template_version, 10) || 1;
        }
        if (prev && prev.defaults && prev.defaults.attendance_options && prev.defaults.attendance_options.length) {
          data.defaults.attendance_options = prev.defaults.attendance_options;
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
        var key = $(this).data('field-key');
        if (hiddenSet[key]) {
          $(this).find('.hmwevents-dnd-required input').prop('checked', false);
          $(this).appendTo('#hmwevents-acf-available');
        }
      });

      $('#hmwevents-acf-available .hmwevents-dnd-item').each(function () {
        var key = $(this).data('field-key');
        if (!hiddenSet[key]) {
          $(this).appendTo('#hmwevents-acf-included');
          if (requiredSet[key]) {
            $('#hmwevents-acf-included .hmwevents-dnd-item[data-field-key="' + escAttr(key) + '"] .hmwevents-dnd-required input').prop('checked', true);
          }
        }
      });

      $('#hmwevents-acf-included .hmwevents-dnd-item').each(function () {
        var key = $(this).data('field-key');
        if (requiredSet[key]) {
          $(this).find('.hmwevents-dnd-required input').prop('checked', true);
        }
      });
    }

    if (preset.default_meta) {
      Object.keys(preset.default_meta).forEach(function (k) {
        var input = $('.hmwevents-meta-default[data-field-key="' + k + '"]');
        if (input.length) input.val(preset.default_meta[k]);
      });
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
    $('#hmwevents-advanced-edit').slideToggle();
    $(this).text($(this).text() === 'Show advanced editor' ? 'Hide advanced editor' : 'Show advanced editor');
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
