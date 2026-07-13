(function ($) {
  'use strict';

  var postId;
  var fieldConfig = null;
  var acfFields = [];
  var regFields = [];
  var sectionLabels = {};
  var statusEl;

  function init() {
    postId = $('#hmwevents-override-container').data('post-id');
    statusEl = $('#hmwevents-override-status');
    acfFields = window.hmwEventOverride.acfEventFields;
    regFields = window.hmwEventOverride.registrationFields;
    sectionLabels = window.hmwEventOverride.sectionLabels;
  }

  function loadOverride() {
    $.post(window.hmwEventOverride.ajaxUrl, {
      action: 'hmwevents_load_event_override',
      event_id: postId,
      _wpnonce: window.hmwEventOverride.nonce
    }, function (response) {
      if (response.success) {
        fieldConfig = response.data.resolved;
        renderACFDnD();
        renderRegFields();
        if (response.data.apply_to_children) {
          $('#hmwevents-override-apply-children').prop('checked', true);
        }
      }
    });
  }

  function renderACFDnD() {
    var eventFields = fieldConfig.event_fields || { required: [], optional: [], hidden: [] };
    var included = [];
    var hidden = [];

    acfFields.forEach(function (f) {
      if (eventFields.hidden.indexOf(f.key) !== -1) {
        hidden.push(f);
      } else if (eventFields.required.indexOf(f.key) !== -1) {
        included.push({ field: f, required: true });
      } else if (eventFields.optional.indexOf(f.key) !== -1) {
        included.push({ field: f, required: false });
      } else {
        included.push({ field: f, required: false });
      }
    });

    var hiddenHtml = '';
    hidden.forEach(function (f) {
      hiddenHtml += '<li class="hmwevents-override-dnd-item" data-key="' + escAttr(f.key) + '"><span class="hmwevents-dnd-handle">☰</span><span class="hmwevents-dnd-label"><strong>' + escHtml(f.label) + '</strong> <code>' + escHtml(f.key) + '</code></span></li>';
    });
    $('#hmwevents-override-acf-hidden').html(hiddenHtml);

    var includedHtml = '';
    included.forEach(function (item) {
      includedHtml += '<li class="hmwevents-override-dnd-item" data-key="' + escAttr(item.field.key) + '"><span class="hmwevents-dnd-handle">☰</span><span class="hmwevents-dnd-label"><strong>' + escHtml(item.field.label) + '</strong> <code>' + escHtml(item.field.key) + '</code></span><label class="hmwevents-req-toggle"><input type="checkbox" class="hmwevents-override-acf-required" ' + (item.required ? 'checked' : '') + '> Required</label></li>';
    });
    $('#hmwevents-override-acf-included').html(includedHtml);

    $('#hmwevents-override-acf-hidden, #hmwevents-override-acf-included').sortable({
      connectWith: '.hmwevents-override-dnd-list',
      handle: '.hmwevents-dnd-handle',
      placeholder: 'hmwevents-dnd-placeholder'
    }).disableSelection();
  }

  function renderRegFields() {
    var regConfig = fieldConfig.registration_fields || { required: [], optional: [], hidden: [], order: [], field_overrides: {} };
    var container = $('#hmwevents-override-reg-fields');

    var sections = {};
    Object.keys(sectionLabels).forEach(function (s) { sections[s] = []; });

    regFields.forEach(function (f) {
      var key = f.key;
      var isHidden = regConfig.hidden.indexOf(key) !== -1;
      if (isHidden) return;

      var isRequired = regConfig.required.indexOf(key) !== -1;
      var override = regConfig.field_overrides[key] || {};
      var section = override.section || f.section || 'additional';

      if (!sections[section]) sections[section] = [];
      sections[section].push({
        field: f,
        required: isRequired,
        override: override
      });
    });

    if (regConfig.order && regConfig.order.length) {
      Object.keys(sections).forEach(function (section) {
        sections[section].sort(function (a, b) {
          return regConfig.order.indexOf(a.field.key) - regConfig.order.indexOf(b.field.key);
        });
      });
    }

    var html = '';
    Object.keys(sections).forEach(function (section) {
      if (!sections[section].length) return;
      html += '<div class="hmwevents-override-reg-section"><h5>' + escHtml(sectionLabels[section] || section) + '</h5><ul class="hmwevents-override-dnd-list hmwevents-override-reg-list" data-section="' + escAttr(section) + '">';
      sections[section].forEach(function (item) {
        html += '<li class="hmwevents-override-dnd-item" data-key="' + escAttr(item.field.key) + '"><span class="hmwevents-dnd-handle">☰</span><span class="hmwevents-dnd-label"><strong>' + escHtml(item.field.label) + '</strong> <code>' + escHtml(item.field.key) + '</code></span><label class="hmwevents-req-toggle"><input type="checkbox" class="hmwevents-override-reg-required" ' + (item.required ? 'checked' : '') + '> Required</label></li>';
      });
      html += '</ul></div>';
    });
    container.html(html);

    $('.hmwevents-override-reg-list').sortable({
      connectWith: '.hmwevents-override-reg-list',
      handle: '.hmwevents-dnd-handle',
      placeholder: 'hmwevents-dnd-placeholder'
    }).disableSelection();
  }

  function assembleOverrideJSON() {
    var event_fields = { required: [], optional: [], hidden: [] };

    $('#hmwevents-override-acf-hidden .hmwevents-override-dnd-item').each(function () {
      event_fields.hidden.push($(this).data('key'));
    });

    $('#hmwevents-override-acf-included .hmwevents-override-dnd-item').each(function () {
      var key = $(this).data('key');
      var required = $(this).find('.hmwevents-override-acf-required').prop('checked');
      if (required) event_fields.required.push(key);
      else event_fields.optional.push(key);
    });

    var registration_fields = { required: [], optional: [], hidden: [], order: [], field_overrides: {} };

    $('.hmwevents-override-reg-list .hmwevents-override-dnd-item').each(function () {
      var key = $(this).data('key');
      var required = $(this).find('.hmwevents-override-reg-required').prop('checked');
      if (required) registration_fields.required.push(key);
      else registration_fields.optional.push(key);
      registration_fields.order.push(key);
    });

    var allRegKeys = regFields.map(function (f) { return f.key; });
    var visible = registration_fields.required.concat(registration_fields.optional);
    allRegKeys.forEach(function (k) {
      if (visible.indexOf(k) === -1) registration_fields.hidden.push(k);
    });

    return {
      schema_version: 2,
      event_fields: event_fields,
      registration_fields: registration_fields
    };
  }

  function saveOverride() {
    var data = assembleOverrideJSON();
    var applyToChildren = $('#hmwevents-override-apply-children').prop('checked');

    statusEl.show().css('color', '#2271b1').text('Saving...');

    $.post(window.hmwEventOverride.ajaxUrl, {
      action: 'hmwevents_save_event_override',
      event_id: postId,
      override_data: JSON.stringify(data),
      apply_to_children: applyToChildren ? 1 : 0,
      _wpnonce: window.hmwEventOverride.nonce
    }, function (response) {
      if (response.success) {
        statusEl.css('color', '#00a32a').text('Override saved.');
        setTimeout(function () { statusEl.fadeOut(); }, 2000);
      } else {
        statusEl.css('color', '#b32d2e').text('Save failed.');
      }
    });
  }

  function resetOverride() {
    if (!confirm('Reset override and revert to template defaults?')) return;
    statusEl.show().css('color', '#2271b1').text('Resetting...');

    $.post(window.hmwEventOverride.ajaxUrl, {
      action: 'hmwevents_reset_event_override',
      event_id: postId,
      _wpnonce: window.hmwEventOverride.nonce
    }, function (response) {
      if (response.success) {
        location.reload();
      } else {
        statusEl.css('color', '#b32d2e').text('Reset failed.');
      }
    });
  }

  $(document).on('click', '.hmwevents-enable-override, .hmwevents-edit-override', function () {
    $('#hmwevents-override-editor').show();
    $(this).hide();
    if (!fieldConfig) loadOverride();
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

  $(function () {
    if ($('#hmwevents-override-container').length) {
      init();
    }
  });
})(jQuery);
