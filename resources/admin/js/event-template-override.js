(function ($) {
  'use strict';

  var postId;
  var fieldConfig = null;
  var acfFields = [];
  var regFields = [];
  var sectionLabels = {};
  var hasChildren = false;

  function init() {
    var $container = $('#hmwevents-override-container');
    postId = $container.data('post-id');
    hasChildren = $container.data('has-children') === 1;
    acfFields = window.hmwEventOverride.acfEventFields;
    regFields = window.hmwEventOverride.registrationFields.slice();
    sectionLabels = window.hmwEventOverride.sectionLabels;
  }

  function openModal() {
    var $status = $('#hmwevents-override-status');
    $status.text('Loading...');
    regFields = window.hmwEventOverride.registrationFields.slice();

    $.post(window.hmwEventOverride.ajaxUrl, {
      action: 'hmwevents_load_event_override',
      event_id: postId,
      _wpnonce: window.hmwEventOverride.nonce
    }, function (response) {
      $status.text('');
      if (response.success) {
        fieldConfig = response.data.resolved;
        renderACFDnD();
        renderRegFields();
        bindRegFieldHandlers();
        $('#hmwevents-override-modal-children').toggle(hasChildren);
        if (response.data.apply_to_children) {
          $('#hmwevents-override-apply-children').prop('checked', true);
        }
        tb_show('Field Override', '#TB_inline?width=750&height=600&inlineId=hmwevents-override-modal');
      }
    }).fail(function () {
      $status.text('');
    });
  }

  function renderACFDnD() {
    if (!fieldConfig) return;

    var eventFields = fieldConfig.event_fields || { required: [], optional: [], hidden: [] };
    var included = [];
    var hidden = [];

    acfFields.forEach(function (f) {
      var isHidden = false;
      var isRequired = false;
      if (f.type === 'group') {
        var children = f.children || [];
        isHidden = children.length > 0 && children.every(function (c) { return eventFields.hidden.indexOf(c) !== -1; });
        if (!isHidden) {
          isRequired = children.length > 0 && children.every(function (c) { return eventFields.required.indexOf(c) !== -1; });
        }
      } else {
        isHidden = eventFields.hidden.indexOf(f.key) !== -1;
        isRequired = eventFields.required.indexOf(f.key) !== -1;
      }
      if (isHidden) {
        hidden.push(f);
      } else {
        included.push({ field: f, required: isRequired });
      }
    });

    var hiddenHtml = '';
    hidden.forEach(function (f) {
      if (f.type === 'group') {
        var childrenJson = JSON.stringify(f.children || []);
        hiddenHtml += '<li class="hmwevents-override-dnd-item hmwevents-dnd-group" data-key="' + escAttr(f.key) + '" data-group-children="' + escAttr(childrenJson) + '"><span class="hmwevents-dnd-handle">☰</span><span class="hmwevents-dnd-label"><strong>' + escHtml(f.label) + '</strong> <span class="hmwevents-dnd-group-badge">group</span></span></li>';
      } else {
        hiddenHtml += '<li class="hmwevents-override-dnd-item" data-key="' + escAttr(f.key) + '"><span class="hmwevents-dnd-handle">☰</span><span class="hmwevents-dnd-label"><strong>' + escHtml(f.label) + '</strong> <code>' + escHtml(f.key) + '</code></span></li>';
      }
    });
    $('#hmwevents-override-acf-hidden').html(hiddenHtml);

    var includedHtml = '';
    included.forEach(function (item) {
      var f = item.field;
      if (f.type === 'group') {
        var childrenJson = JSON.stringify(f.children || []);
        includedHtml += '<li class="hmwevents-override-dnd-item hmwevents-dnd-group" data-key="' + escAttr(f.key) + '" data-group-children="' + escAttr(childrenJson) + '"><span class="hmwevents-dnd-handle">☰</span><span class="hmwevents-dnd-label"><strong>' + escHtml(f.label) + '</strong> <span class="hmwevents-dnd-group-badge">group</span></span><label class="hmwevents-req-toggle"><input type="checkbox" class="hmwevents-override-acf-required" ' + (item.required ? 'checked' : '') + '> Required</label></li>';
      } else {
        includedHtml += '<li class="hmwevents-override-dnd-item" data-key="' + escAttr(f.key) + '"><span class="hmwevents-dnd-handle">☰</span><span class="hmwevents-dnd-label"><strong>' + escHtml(f.label) + '</strong> <code>' + escHtml(f.key) + '</code></span><label class="hmwevents-req-toggle"><input type="checkbox" class="hmwevents-override-acf-required" ' + (item.required ? 'checked' : '') + '> Required</label></li>';
      }
    });
    $('#hmwevents-override-acf-included').html(includedHtml);

    $('#hmwevents-override-acf-hidden, #hmwevents-override-acf-included').sortable({
      connectWith: '.hmwevents-override-dnd-list',
      handle: '.hmwevents-dnd-handle',
      placeholder: 'hmwevents-dnd-placeholder'
    }).disableSelection();
  }

  function renderRegFields() {
    if (!fieldConfig) return;

    var regConfig = fieldConfig.registration_fields || {};
    var regHidden = regConfig.hidden || [];
    var regRequired = regConfig.required || [];
    var container = $('#hmwevents-override-reg-fields');

    var mbEnabled = $('#hmwevents-override-multi-booking-toggle').prop('checked');
    var mbMin = parseInt($('#hmwevents-override-mb-min').val(), 10) || 1;
    var mbMax = parseInt($('#hmwevents-override-mb-max').val(), 10) || 10;
    var hasExistingToggle = $('#hmwevents-override-multi-booking-toggle').length > 0;

    if (!hasExistingToggle) {
      var mb = regConfig.multi_booking || { enabled: false, min: 1, max: 10 };
      mbEnabled = mb.enabled || false;
      mbMin = mb.min || 1;
      mbMax = mb.max || 10;
    }

    var html = '<div style="margin-bottom:12px; padding:8px 12px; background:#f0f6fc; border-left:4px solid #2271b1;">';
    html += '<label><input type="checkbox" id="hmwevents-override-multi-booking-toggle"' + (mbEnabled ? ' checked' : '') + '> <strong>Enable multi-attendee booking</strong></label>';
    html += '<p class="description" style="margin:4px 0 0 20px;">Allows customers to register multiple attendees in one booking.</p>';
    html += '<div id="hmwevents-override-multi-booking-settings"' + (mbEnabled ? '' : ' style="display:none"') + '>';
    html += '<label style="margin-top:8px;display:inline-block;">Min attendees: <input type="number" id="hmwevents-override-mb-min" value="' + mbMin + '" min="1" max="100" style="width:60px;"></label>';
    html += '<label style="margin-left:12px;">Max attendees: <input type="number" id="hmwevents-override-mb-max" value="' + mbMax + '" min="1" max="100" style="width:60px;"></label>';
    html += '</div></div>';

    html += '<div id="hmwevents-override-sections">';
    getOverrideSections().forEach(function (section) {
      var fields = [];
      regFields.forEach(function (f) {
        if ((f.section || 'additional') !== section.id) return;
        if (regHidden.indexOf(f.key) !== -1) return;
        fields.push({
          key: f.key,
          label: f.label || f.key,
          type: f.type || 'text',
          required: regRequired.indexOf(f.key) !== -1,
          width: 'full',
          source: f.source || 'booking_details',
          meta_key: f.meta_key || null,
          preset: f.preset || false,
          per_attendee: f.per_attendee || false,
          options: f.options || [],
          placeholder: f.placeholder || '',
          section: f.section || 'additional'
        });
      });

      html += '<div class="hmwevents-override-section" data-section-id="' + escAttr(section.id) + '" style="margin-bottom:14px;">';
      html += '<div class="hmwevents-override-section-header" style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">';
      html += '<span class="hmwevents-dnd-handle hmwevents-override-section-handle" style="cursor:grab;">☰</span>';
      html += '<strong style="flex:1;font-size:13px;">' + escHtml(section.label) + '</strong>';
      html += '<button type="button" class="hmwevents-override-delete-section" style="background:none;border:none;color:#b32d2e;cursor:pointer;font-size:16px;padding:0 4px;">&times;</button>';
      html += '</div>';
      html += '<div class="hmwevents-override-section-fields" style="display:grid;grid-template-columns:1fr 1fr;gap:6px;">';
      fields.forEach(function (f) {
        html += HmwFormBuilder.renderFieldCard(f);
      });
      html += '</div>';
      html += '<button type="button" class="button button-small hmwevents-override-add-reg-field" data-section-id="' + escAttr(section.id) + '" style="margin-top:6px;">+ Add Field</button>';
      html += '</div>';
    });
    html += '</div>';
    html += '<button type="button" class="button hmwevents-override-add-section-btn" style="margin-top:4px;">+ Add Section</button>';
    container.html(html);

    initOverrideSectionsSortable();
    $('.hmwevents-override-section-fields').each(function () {
      HmwFormBuilder.initFieldSortable($(this), null);
    });

    $('#hmwevents-override-multi-booking-toggle').off('change.mb').on('change.mb', function () {
      $('#hmwevents-override-multi-booking-settings').toggle(this.checked);
      $('#hmwevents-modal-per-attendee-row').toggle(this.checked);
    });
  }

  function readMultiBookingState() {
    return {
      enabled: $('#hmwevents-override-multi-booking-toggle').prop('checked') || false,
      min: parseInt($('#hmwevents-override-mb-min').val(), 10) || 1,
      max: parseInt($('#hmwevents-override-mb-max').val(), 10) || 10
    };
  }

  function initOverrideSectionsSortable() {
    $('#hmwevents-override-sections').sortable({
      handle: '.hmwevents-override-section-handle',
      placeholder: 'hmwevents-dnd-placeholder'
    }).disableSelection();
  }

  function getOverrideSections() {
    var sections = [];
    var seen = {};
    var known = Object.keys(sectionLabels);
    known.forEach(function (s) { seen[s] = true; sections.push({ id: s, label: sectionLabels[s] || s }); });
    regFields.forEach(function (f) {
      var s = f.section || 'additional';
      if (!seen[s]) { seen[s] = true; sections.push({ id: s, label: s.charAt(0).toUpperCase() + s.slice(1).replace(/_/g, ' ') }); }
    });
    return sections;
  }

  function bindRegFieldHandlers() {
    $(document).off('click.fbo', '.hmwevents-fb-field-card');
    $(document).on('click.fbo', '.hmwevents-fb-field-card', function (e) {
      if ($(e.target).closest('.hmwevents-dnd-handle').length) return;
      var sectionId = $(this).closest('.hmwevents-override-section').data('section-id');
      openRegFieldModal($(this), sectionId);
    });

    $(document).off('click.fbo', '.hmwevents-override-add-reg-field');
    $(document).on('click.fbo', '.hmwevents-override-add-reg-field', function () {
      var sectionId = $(this).data('section-id');
      openRegFieldModal(null, sectionId);
    });

    $(document).off('click.fbo', '.hmwevents-override-delete-section');
    $(document).on('click.fbo', '.hmwevents-override-delete-section', function () {
      if (!confirm('Delete this section and move its fields to Additional?')) return;
      var sectionId = $(this).closest('.hmwevents-override-section').data('section-id');
      regFields.forEach(function (f) {
        if ((f.section || 'additional') === sectionId) f.section = 'additional';
      });
      renderRegFields();
      bindRegFieldHandlers();
    });

    $(document).off('click.fbo', '.hmwevents-override-add-section-btn');
    $(document).on('click.fbo', '.hmwevents-override-add-section-btn', function () {
      var name = prompt('Section name:');
      if (!name) return;
      var slug = name.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '');
      if (!slug) return;
      regFields.forEach(function (f) { f.section = f.section || 'additional'; });
      sectionLabels[slug] = name;
      renderRegFields();
      bindRegFieldHandlers();
    });
  }

  function openRegFieldModal(card, sectionId) {
    var cardKey = card ? card.data('field-key') : '';
    var fieldData = card ? HmwFormBuilder.readFieldCardData(card) : null;

    tb_remove();

    var showPerAttendee = $('#hmwevents-override-multi-booking-toggle').prop('checked') || false;

    HmwFormBuilder.openFieldModal(
      'override',
      fieldData,
      window.hmwEventOverride.registrationPresets || {},
      showPerAttendee,
      function (data) {
        if (cardKey) {
          for (var i = 0; i < regFields.length; i++) {
            if (regFields[i].key === cardKey) {
              regFields[i].label = data.label;
              regFields[i].type = data.type;
              regFields[i].section = sectionId;
              break;
            }
          }
        } else {
          var exists = false;
          for (var j = 0; j < regFields.length; j++) {
            if (regFields[j].key === data.key) { exists = true; break; }
          }
          if (exists) {
            alert('A field with key "' + data.key + '" already exists.');
          } else {
            regFields.push({
              key: data.key,
              label: data.label,
              type: data.type,
              source: data.source,
              meta_key: data.meta_key,
              preset: data.preset,
              per_attendee: data.per_attendee || false,
              options: data.options || [],
              placeholder: data.placeholder || '',
              section: sectionId
            });
          }
        }
        setTimeout(reopenOverride, 200);
      },
      function () {
        if (cardKey) {
          regFields = regFields.filter(function (f) { return f.key !== cardKey; });
        }
        setTimeout(reopenOverride, 200);
      }
    );
  }

  function reopenOverride() {
    renderACFDnD();
    renderRegFields();
    bindRegFieldHandlers();
    if (hasChildren) $('#hmwevents-override-modal-children').show();
    tb_show('Field Override', '#TB_inline?width=750&height=600&inlineId=hmwevents-override-modal');
  }

  function readRegFieldsState() {
    var required = [];
    var optional = [];

    $('.hmwevents-override-section-fields .hmwevents-fb-field-card').each(function () {
      var key = $(this).data('field-key');
      var isRequired = $(this).find('.hmwevents-fb-field-req').length > 0;
      if (isRequired) required.push(key);
      else optional.push(key);
    });

    return { required: required, optional: optional };
  }

  function assembleOverrideJSON() {
    var event_fields = { required: [], optional: [], hidden: [] };

    $('#hmwevents-override-acf-hidden .hmwevents-override-dnd-item').each(function () {
      var $item = $(this);
      if ($item.hasClass('hmwevents-dnd-group')) {
        addKeysFromGroup(event_fields.hidden, $item);
      } else {
        event_fields.hidden.push($item.data('key'));
      }
    });

    $('#hmwevents-override-acf-included .hmwevents-override-dnd-item').each(function () {
      var $item = $(this);
      var required = $item.find('.hmwevents-override-acf-required').prop('checked');
      var keys;
      if ($item.hasClass('hmwevents-dnd-group')) {
        keys = getGroupChildren($item);
      } else {
        keys = [$item.data('key')];
      }
      if (required) {
        addKeysFromGroup(event_fields.required, $item, keys);
      } else {
        addKeysFromGroup(event_fields.optional, $item, keys);
      }
    });

    function addKeysFromGroup(target, $item, overrideKeys) {
      var keys = overrideKeys || getGroupChildren($item) || [$item.data('key')];
      for (var i = 0; i < keys.length; i++) {
        if (target.indexOf(keys[i]) === -1) {
          target.push(keys[i]);
        }
      }
    }

    var regState = readRegFieldsState();
    var allRegKeys = regFields.map(function (f) { return f.key; });
    var visible = regState.required.concat(regState.optional);
    var hidden = [];
    allRegKeys.forEach(function (k) {
      if (visible.indexOf(k) === -1) hidden.push(k);
    });

    var registration_fields = {
      required: regState.required,
      optional: regState.optional,
      hidden: hidden,
      order: visible,
      field_overrides: {},
      multi_booking: readMultiBookingState()
    };

    return {
      schema_version: 2,
      event_fields: event_fields,
      registration_fields: registration_fields
    };
  }

  function saveOverride() {
    var data = assembleOverrideJSON();
    var applyToChildren = $('#hmwevents-override-apply-children').prop('checked');
    var $status = $('#hmwevents-override-status');

    $status.text('Saving...');

    $.post(window.hmwEventOverride.ajaxUrl, {
      action: 'hmwevents_save_event_override',
      event_id: postId,
      override_data: JSON.stringify(data),
      apply_to_children: applyToChildren ? 1 : 0,
      _wpnonce: window.hmwEventOverride.nonce
    }, function (response) {
      if (response.success) {
        $status.text('Saved.');
        setTimeout(function () {
          tb_remove();
          location.reload();
        }, 600);
      } else {
        $status.text('Save failed.');
      }
    });
  }

  function resetOverride() {
    if (!confirm('Reset override and revert to defaults?')) return;

    $.post(window.hmwEventOverride.ajaxUrl, {
      action: 'hmwevents_reset_event_override',
      event_id: postId,
      _wpnonce: window.hmwEventOverride.nonce
    }, function (response) {
      if (response.success) {
        tb_remove();
        location.reload();
      }
    });
  }

  $(document).on('click', '.hmwevents-open-override', function () {
    openModal();
  });

  $(document).on('click', '.hmwevents-save-override', saveOverride);
  $(document).on('click', '.hmwevents-reset-override', resetOverride);

  function escHtml(str) {
    if (!str) return '';
    var map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
    return String(str).replace(/[&<>"']/g, function (m) { return map[m]; });
  }

  function escAttr(str) {
    return String(str).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  function getGroupChildren($item) {
    try {
      return JSON.parse($item.attr('data-group-children')) || [];
    } catch (e) {
      return [];
    }
  }

  $(function () {
    if ($('#hmwevents-override-container').length) {
      init();
    }
  });
})(jQuery);
