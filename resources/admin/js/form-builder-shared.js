(function ($) {
  'use strict';

  var currentSectionId = '';
  var currentFieldKey = '';
  var onSaveFn = null;
  var onDeleteFn = null;

  function escHtml(str) {
    if (!str) return '';
    var map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
    return String(str).replace(/[&<>"']/g, function (m) { return map[m]; });
  }

  function escAttr(str) {
    return String(str).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  // ================================================================
  // FIELD CARD
  // ================================================================

  function renderFieldCard(field) {
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
  // SORTABLE WITH SPLIT-DROP
  // ================================================================

  function initFieldSortable(el, onStop) {
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

        if (onStop) onStop();
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
  // FIELD MODAL
  // ================================================================

  function openFieldModal(sectionId, fieldData, presets, multiBookingEnabled, saveCb, deleteCb) {
    currentSectionId = sectionId;
    currentFieldKey = fieldData ? fieldData.key : '';
    onSaveFn = saveCb;
    onDeleteFn = deleteCb;

    var isNew = !fieldData;
    var presetBtns = $('#hmwevents-modal-preset-buttons');
    if (presetBtns.children().length === 0) {
      Object.keys(presets || {}).forEach(function (key) {
        var p = presets[key];
        var btn = $('<button type="button" class="button button-small hmwevents-preset-btn">')
          .text(p.label + ' (' + p.type + ')')
          .data('preset-key', key)
          .on('click', function () { applyPreset(key, presets); });
        presetBtns.append(btn);
      });
    }

    $('#hmwevents-modal-presets').toggle(isNew);

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

    updateOptionsPanel();
    renderOptions(f.options || []);

    $('#hmwevents-modal-per-attendee-row').toggle(multiBookingEnabled);

    bindModalHandlers(saveCb, deleteCb);

    tb_show('Field Settings', '#TB_inline?width=520&height=480&inlineId=hmwevents-field-modal');
  }

  function applyPreset(presetKey, presets) {
    var p = presets[presetKey];
    if (!p) return;

    $('#hmwevents-modal-key').val(p.key).prop('readonly', true);
    $('#hmwevents-modal-label').val(p.label);
    $('#hmwevents-modal-type').val(p.type);
    $('#hmwevents-modal-required').prop('checked', !!p.required);
    $('input[name="hmwevents-modal-width"][value="' + (p.width || 'full') + '"]').prop('checked', true);
    updateOptionsPanel();
  }

  function updateOptionsPanel() {
    var type = $('#hmwevents-modal-type').val();
    var showOptions = ['select', 'checkbox', 'radio'].indexOf(type) !== -1;
    $('#hmwevents-modal-options-panel').toggle(showOptions);
  }

  function renderOptions(options) {
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

  function collectOptions() {
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

  function collectFieldData() {
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
      options: collectOptions()
    };
  }

  function readFieldCardData(card) {
    return {
      key: card.data('field-key'),
      label: card.find('.hmwevents-fb-field-label').text().replace(' *', '').trim(),
      type: card.find('.hmwevents-fb-field-type').text().trim() || 'text',
      required: card.find('.hmwevents-fb-field-req').length > 0,
      width: card.hasClass('hmwevents-fb-field--full') ? 'full' : 'half',
      source: card.data('source') || 'booking_details',
      meta_key: card.data('meta-key') || null,
      preset: card.data('preset') === 1 || card.data('preset') === '1',
      per_attendee: card.find('.hmwevents-fb-field-per-attendee').length > 0,
      options: [],
      placeholder: ''
    };
  }

  // ================================================================
  // MODAL EVENT BINDING
  // ================================================================

  function bindModalHandlers(saveCb, deleteCb) {
    $(document).off('click.fb', '#hmwevents-modal-save');
    $(document).on('click.fb', '#hmwevents-modal-save', function () {
      var data = collectFieldData();
      if (!data.key || !data.label) {
        alert('Field key and label are required.');
        return;
      }
      if (saveCb) saveCb(data, currentSectionId, currentFieldKey);
      tb_remove();
    });

    $(document).off('click.fb', '#hmwevents-modal-delete');
    $(document).on('click.fb', '#hmwevents-modal-delete', function () {
      if (!currentFieldKey) return;
      if (!confirm('Delete this field?')) return;
      if (deleteCb) deleteCb(currentFieldKey);
      tb_remove();
    });

    $(document).off('change.fb', '#hmwevents-modal-type');
    $(document).on('change.fb', '#hmwevents-modal-type', function () {
      updateOptionsPanel();
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

  // ================================================================
  // PUBLIC API
  // ================================================================

  window.HmwFormBuilder = {
    renderFieldCard: renderFieldCard,
    initFieldSortable: initFieldSortable,
    openFieldModal: openFieldModal,
    readFieldCardData: readFieldCardData,
    collectFieldData: collectFieldData
  };

})(jQuery);
