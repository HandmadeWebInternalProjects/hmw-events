/**
 * Course Bookings Meta Box Scripts
 *
 * @package HMWEvents
 * @since 1.0.0
 */

jQuery(document).ready(function($) {
  const restUrl = cmsBookings.restUrl;
  const restNonce = cmsBookings.restNonce;

  // Transfer booking
  $('.hmwevents-confirm-transfer').on('click', function(e) {
    e.preventDefault();
    var $btn = $(this);
    var bookingId = $btn.data('booking-id');
    var newCourseId = $('#transfer-course-' + bookingId).val();
    var $message = $btn.closest('#transfer-booking-modal-' + bookingId).find('.hmwevents-transfer-message');

    if (!newCourseId) {
      $message.removeClass('notice-success').addClass('notice notice-error').text(cmsBookings.i18n.selectCourse).show();
      return;
    }

    $btn.prop('disabled', true).text(cmsBookings.i18n.transferring);

    $.ajax({
      url: restUrl + '/booking/transfer',
      type: 'POST',
      beforeSend: function(xhr) {
        xhr.setRequestHeader('X-WP-Nonce', restNonce);
      },
      data: JSON.stringify({
        booking_id: bookingId,
        new_course_id: newCourseId
      }),
      contentType: 'application/json',
      success: function(response) {
        $message.removeClass('notice-error').addClass('notice notice-success').text(response.message).show();
        setTimeout(function() {
          tb_remove();
          location.reload();
        }, 1500);
      },
      error: function(xhr) {
        var errorMsg = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : cmsBookings.i18n.error;
        $message.removeClass('notice-success').addClass('notice notice-error').text(errorMsg).show();
        $btn.prop('disabled', false).text(cmsBookings.i18n.transferButton);
      }
    });
  });

  // Resend confirmation
  $('.hmwevents-resend-confirmation').on('click', function(e) {
    e.preventDefault();
    var $link = $(this);
    var bookingId = $link.data('booking-id');

    if (!confirm(cmsBookings.i18n.confirmResendConfirmation)) {
      return;
    }

    $link.text(cmsBookings.i18n.sending);

    $.ajax({
      url: restUrl + '/booking/resend-confirmation',
      type: 'POST',
      beforeSend: function(xhr) {
        xhr.setRequestHeader('X-WP-Nonce', restNonce);
      },
      data: JSON.stringify({
        booking_id: bookingId
      }),
      contentType: 'application/json',
      success: function(response) {
        alert(response.message);
        $link.text(cmsBookings.i18n.resendConfirmation);
      },
      error: function(xhr) {
        var errorMsg = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : cmsBookings.i18n.error;
        alert(errorMsg);
        $link.text(cmsBookings.i18n.resendConfirmation);
      }
    });
  });

  // Resend receipt
  $('.hmwevents-resend-receipt').on('click', function(e) {
    e.preventDefault();
    var $link = $(this);
    var bookingId = $link.data('booking-id');

    if (!confirm(cmsBookings.i18n.confirmResendReceipt)) {
      return;
    }

    $link.text(cmsBookings.i18n.sending);

    $.ajax({
      url: restUrl + '/booking/resend-receipt',
      type: 'POST',
      beforeSend: function(xhr) {
        xhr.setRequestHeader('X-WP-Nonce', restNonce);
      },
      data: JSON.stringify({
        booking_id: bookingId
      }),
      contentType: 'application/json',
      success: function(response) {
        alert(response.message);
        $link.text(cmsBookings.i18n.resendReceipt);
      },
      error: function(xhr) {
        var errorMsg = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : cmsBookings.i18n.error;
        alert(errorMsg);
        $link.text(cmsBookings.i18n.resendReceipt);
      }
    });
  });

  // ------------------------------------------------------------------
  // Add Manual Booking
  // ------------------------------------------------------------------
  var mbConfigCache = null;

  function manualBookingConfig() {
    if (mbConfigCache !== null) return mbConfigCache;
    var $el = $('#hmwevents-add-booking-config');
    if (!$el.length) {
      mbConfigCache = {};
      return mbConfigCache;
    }
    try {
      mbConfigCache = JSON.parse($el.text()) || {};
    } catch (e) {
      mbConfigCache = {};
    }
    return mbConfigCache;
  }

  function mbSelectedOption() {
    var cfg = manualBookingConfig();
    var $checked = $('#hmwevents-add-booking-form').find('input[name="attendance_type"]:checked');
    var selected = $checked.length ? $checked.val() : ($('#hmwevents-add-booking-form').find('input[name="attendance_type"]').val() || '');
    if (!selected) selected = cfg.defaultOptionKey || cfg.defaultOptionType;
    var options = cfg.options || [];
    if (!selected && options.length === 1) selected = options[0].option_key || options[0].option_type;
    for (var i = 0; i < options.length; i++) {
      var option = options[i];
      if (option.option_key ? option.option_key === selected : option.option_type === selected) return option;
    }
    return null;
  }

  function mbComposition() {
    var cfg = manualBookingConfig();
    var option = mbSelectedOption();
    if (option && option.composition) return option.composition;
    return { min_attendees: cfg.minAttendees || 1, max_attendees: cfg.maxAttendees || 1 };
  }

  function mbMinAttendees() {
    return Math.max(1, parseInt(mbComposition().min_attendees, 10) || 1);
  }

  function mbMaxAttendees() {
    var min = mbMinAttendees();
    var max = parseInt(mbComposition().max_attendees, 10);
    return Math.max(min, max || min);
  }

  function mbBlocks() {
    return $('.hmwevents-attendee-blocks .hmwevents-attendee-block');
  }

  function mbTotalAttendees() {
    return mbBlocks().length + 1;
  }

  function mbTitle(index) {
    var prefix = $('#hmwevents-manual-attendees').data('title-prefix') || 'Attendee';
    return manualBookingConfig().parentChildren ? prefix + ' ' + index : prefix + ' ' + (index + 1);
  }

  function mbAddAttendee() {
    if (mbTotalAttendees() >= mbMaxAttendees()) return;
    var template = document.getElementById('hmwevents-manual-attendee-template');
    if (!template) return;

    var index = mbBlocks().length + 1;
    var clone = template.content.cloneNode(true);
    var $block = $(clone).find('.hmwevents-attendee-block');

    $block.attr('data-attendee-index', index);
    $block.find('.hmwevents-attendee-title').text(mbTitle(index));

    $block.find('input, select, textarea').each(function () {
      var $el = $(this);
      ['name', 'id'].forEach(function (attr) {
        var value = $el.attr(attr);
        if (value) $el.attr(attr, value.replace(/__INDEX__/g, index));
      });
    });

    $block.find('label').each(function () {
      var fr = $(this).attr('for');
      if (fr) $(this).attr('for', fr.replace(/__INDEX__/g, index));
    });

    $('.hmwevents-attendee-blocks').append($block);
    mbUpdateTotal();
  }

  function mbRemoveAttendee($block) {
    $block.remove();
    mbReindex();
  }

  function mbReindex() {
    mbBlocks().each(function (i) {
      var newIndex = i + 1;
      var oldIndex = parseInt($(this).attr('data-attendee-index'), 10);
      var $block = $(this);

      if (oldIndex === newIndex) return;

      $block.attr('data-attendee-index', newIndex);
      $block.find('.hmwevents-attendee-title').text(mbTitle(newIndex));

      $block.find('input, select, textarea').each(function () {
        var $el = $(this);
        ['name', 'id'].forEach(function (attr) {
          var value = $el.attr(attr);
          if (value) $el.attr(attr, value.split('attendees[' + oldIndex + ']').join('attendees[' + newIndex + ']'));
        });
      });

      $block.find('label').each(function () {
        var fr = $(this).attr('for');
        if (fr) $(this).attr('for', fr.split('attendees[' + oldIndex + ']').join('attendees[' + newIndex + ']'));
      });
    });
    mbUpdateTotal();
  }

  function mbSyncRange() {
    var min = mbMinAttendees();
    var max = mbMaxAttendees();

    var guard = 0;
    while (mbTotalAttendees() < min && guard++ < 20) {
      mbAddAttendee();
    }

    guard = 0;
    while (mbTotalAttendees() > max && guard++ < 20) {
      mbRemoveAttendee(mbBlocks().last());
    }

    $('.hmwevents-add-attendee').toggle(mbTotalAttendees() < max);
    $('.hmwevents-remove-attendee').toggle(mbTotalAttendees() > min);
  }

  function mbRuleRolePrice(option, role) {
    if (!option || !option.pricing_rules) return null;
    var fallback = null;
    for (var i = 0; i < option.pricing_rules.length; i++) {
      var rule = option.pricing_rules[i];
      var ruleRole = rule.role || 'any';
      if (ruleRole === role) return parseFloat(rule.price) || 0;
      if (ruleRole === 'any') fallback = parseFloat(rule.price) || 0;
    }
    return fallback;
  }

  function mbAgeForDate(dob, eventDate) {
    if (!dob || !eventDate) return null;
    var dobDate = new Date(dob);
    var event = new Date(eventDate);
    if (isNaN(dobDate.getTime()) || isNaN(event.getTime())) return null;
    if (event < dobDate) return null;
    var age = event.getFullYear() - dobDate.getFullYear();
    var m = event.getMonth() - dobDate.getMonth();
    if (m < 0 || (m === 0 && event.getDate() < dobDate.getDate())) age--;
    return age;
  }

  function mbRuleAgeBandPrice(option, role, dob) {
    if (!option || !option.pricing_rules) return null;
    var age = mbAgeForDate(dob, manualBookingConfig().eventDate);
    for (var i = 0; i < option.pricing_rules.length; i++) {
      var rule = option.pricing_rules[i];
      var ruleRole = rule.role || 'any';
      if (ruleRole !== 'any' && ruleRole !== role) continue;
      if (age !== null) {
        var minAge = parseInt(rule.min_age, 10) || 0;
        var maxAge = (rule.max_age === null || rule.max_age === '' || rule.max_age === undefined) ? null : parseInt(rule.max_age, 10);
        if (age < minAge) continue;
        if (maxAge !== null && age > maxAge) continue;
      }
      return parseFloat(rule.price) || 0;
    }
    return null;
  }

  function mbCollectRoles() {
    var roles = [];
    $('#hmwevents-add-booking-form')
      .find('select[name$="[attendee_role]"], input[type="hidden"][name$="[attendee_role]"]')
      .each(function () { roles.push($(this).val()); });
    return roles;
  }

  function mbCollectDobs() {
    var dobs = [];
    $('#hmwevents-add-booking-form')
      .find('input[name$="[date_of_birth]"]')
      .each(function () { dobs.push($(this).val()); });
    return dobs;
  }

  function mbComputeBase() {
    var cfg = manualBookingConfig();
    var option = mbSelectedOption();
    if (!option) return parseFloat(cfg.basePrice) || 0;
    var mode = option.price_mode || 'flat';

    if (mode === 'per_attendee') {
      var total = 0;
      mbCollectRoles().forEach(function (role) {
        var price = mbRuleRolePrice(option, role);
        total += price === null ? 0 : price;
      });
      return total;
    }

    if (mode === 'age_band') {
      var sum = 0;
      var roles = mbCollectRoles();
      var dobs = mbCollectDobs();
      for (var i = 0; i < roles.length; i++) {
        var price = mbRuleAgeBandPrice(option, roles[i], dobs[i] || '');
        sum += price === null ? 0 : price;
      }
      return sum;
    }

    return parseFloat(option.display_price !== undefined && option.display_price !== null ? option.display_price : option.price) || 0;
  }

  function mbSurcharge(base) {
    var cfg = manualBookingConfig();
    var mode = cfg.surchargeMode || 'flat';
    var rate = parseFloat(cfg.surchargeRate);
    if (isNaN(rate)) rate = parseFloat(cfg.surcharge) || 0;
    if (mode === 'percent') {
      return Math.round(base * rate) / 100;
    }
    return rate;
  }

  function mbUpdateTotal() {
    var cfg = manualBookingConfig();
    var $modal = $('#hmwevents-add-booking-modal');
    if (!$modal.length || !cfg.options) return;
    var base = mbComputeBase();
    var total = base + mbSurcharge(base);
    $modal.find('.hmwevents-total-value').text('$' + total.toFixed(2));
  }

  function mbUpdateFieldVisibility() {
    var selected = (mbSelectedOption() || {}).option_type || '';
    $('#hmwevents-add-booking-modal').find('[data-attendance-types]').each(function () {
      var types;
      try {
        types = JSON.parse($(this).attr('data-attendance-types')) || [];
      } catch (e) {
        types = [];
      }
      $(this).toggle(!types.length || types.indexOf(selected) !== -1);
    });
  }

  function mbUpdateDobState() {
    var option = mbSelectedOption();
    var isAgeBand = !!(option && option.price_mode === 'age_band');

    $('#hmwevents-add-booking-form').find('.hmwevents-age-dob').each(function () {
      var $field = $(this);
      $field.toggle(isAgeBand);
      $field.find('input, select, textarea').each(function () {
        $(this).prop('disabled', !isAgeBand);
      });
    });
  }

  function mbAssignPath(target, path, value) {
    var keys = path.replace(/\]/g, '').split('[');
    var node = target;
    for (var i = 0; i < keys.length - 1; i++) {
      var key = keys[i];
      var nextKey = keys[i + 1];
      if (typeof node[key] !== 'object' || node[key] === null) {
        node[key] = /^\d+$/.test(nextKey) ? [] : {};
      }
      node = node[key];
    }
    node[keys[keys.length - 1]] = value;
  }

  function mbCollectPayload() {
    var data = {};
    $('#hmwevents-add-booking-form').find('input[name], select[name], textarea[name]').each(function () {
      var $el = $(this);
      if ($el.is(':radio') && !$el.is(':checked')) return;
      if ($el.is(':disabled')) return;
      var value = $el.is(':checkbox') ? ($el.is(':checked') ? '1' : '0') : $el.val();
      mbAssignPath(data, $el.attr('name'), value === null || value === undefined ? '' : value);
    });
    return data;
  }

  $('#hmwevents-add-booking-btn').on('click', function(e) {
    e.preventDefault();
    var $form = $('#hmwevents-add-booking-form');

    $form.find('input[type="text"], input[type="email"], input[type="date"], input[type="number"], input[type="tel"], textarea').val('');
    $form.find('input[type="checkbox"], input[type="radio"]').prop('checked', false);
    $form.find('select').prop('selectedIndex', 0);

    var defaultKey = manualBookingConfig().defaultOptionKey || manualBookingConfig().defaultOptionType;
    var $radios = $form.find('input[name="attendance_type"]');
    if (defaultKey && $radios.filter('[value="' + defaultKey + '"]').length) {
      $radios.prop('checked', false);
      $radios.filter('[value="' + defaultKey + '"]').prop('checked', true);
    }

    $('.hmwevents-attendee-blocks').empty();
    $('#hmwevents-add-booking-message').hide().text('');
    $('#hmwevents-add-booking-submit').prop('disabled', false).text(cmsBookings.i18n.addBookingSubmit || 'Create Booking');

    mbSyncRange();
    mbUpdateFieldVisibility();
    mbUpdateDobState();
    mbUpdateTotal();
    $('#hmwevents-add-booking-modal').fadeIn(150);
  });

  $('#hmwevents-add-booking-modal-close, #hmwevents-add-booking-cancel').on('click', function() {
    $('#hmwevents-add-booking-modal').fadeOut(150);
  });

  $(document).on('click', '#hmwevents-add-booking-modal', function(e) {
    if ($(e.target).is('#hmwevents-add-booking-modal')) {
      $('#hmwevents-add-booking-modal').fadeOut(150);
    }
  });

  $('#hmwevents-add-booking-form').on('click', '.hmwevents-add-attendee', function() {
    mbAddAttendee();
    mbSyncRange();
  });

  $('#hmwevents-add-booking-form').on('click', '.hmwevents-remove-attendee', function() {
    mbRemoveAttendee($(this).closest('.hmwevents-attendee-block'));
    mbSyncRange();
  });

  $('#hmwevents-add-booking-form').on('change', 'input[name="attendance_type"]', function() {
    mbUpdateFieldVisibility();
    mbUpdateDobState();
    mbSyncRange();
    mbUpdateTotal();
  });

  $('#hmwevents-add-booking-form').on('change', 'select[name$="[attendee_role]"], input[name$="[date_of_birth]"]', function() {
    mbUpdateTotal();
  });

  $('#hmwevents-add-booking-submit').on('click', function() {
    var $container = $('#hmwevents-add-booking-form');
    var $submit = $(this);
    var $msg = $('#hmwevents-add-booking-message');

    var missing = false;
    $container.find('[data-required]').each(function() {
      var $el = $(this);
      if (!$el.is(':visible')) return;
      if (!$el.val() || !String($el.val()).trim()) { missing = true; $el.focus(); return false; }
    });
    if (missing) {
      $msg.removeClass('notice-success').addClass('notice notice-error')
        .text('Please fill in all required fields.').show();
      return;
    }

    var payload = mbCollectPayload();
    var totalAttendees = mbTotalAttendees();
    payload.attendee_count = totalAttendees;
    payload.ticket_quantity = totalAttendees;

    $submit.prop('disabled', true).text(cmsBookings.i18n.saving || 'Saving\u2026');
    $msg.hide().text('');

    $.ajax({
      url: restUrl + '/booking/create-manual',
      type: 'POST',
      beforeSend: function(xhr) {
        xhr.setRequestHeader('X-WP-Nonce', restNonce);
      },
      data: JSON.stringify(payload),
      contentType: 'application/json',
      success: function(response) {
        $msg.removeClass('notice-error').addClass('notice notice-success')
          .text(response.message || cmsBookings.i18n.bookingCreated || 'Booking created successfully.').show();
        setTimeout(function() {
          $('#hmwevents-add-booking-modal').fadeOut(150);
          location.reload();
        }, 1800);
      },
      error: function(xhr) {
        var errorMsg = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : cmsBookings.i18n.error;
        $msg.removeClass('notice-success').addClass('notice notice-error').text(errorMsg).show();
        $submit.prop('disabled', false).text(cmsBookings.i18n.addBookingSubmit || 'Create Booking');
      }
    });
  });

  // ------------------------------------------------------------------
  // Edit Booking
  // ------------------------------------------------------------------
  $(document).on('click', '.hmwevents-edit-booking', function(e) {
    e.preventDefault();
    var bookingId = $(this).data('booking-id');
    var nonce     = $(this).data('nonce');
    var $modal    = $('#hmwevents-edit-booking-modal');
    var $body     = $('#hmwevents-edit-booking-modal-body');

    $body.html('<p style="text-align:center;color:#666;">' + (cmsBookings.i18n.loading || 'Loading\u2026') + '</p>');
    $('#hmwevents-edit-booking-modal-title').text(cmsBookings.i18n.editBooking || 'Edit Booking');
    $modal.fadeIn(150);

    $.post(ajaxurl, {
      action: 'hmwevents_get_booking_details',
      booking_id: bookingId,
      nonce: nonce
    }, function(response) {
      if (!response.success) {
        $body.html('<p style="color:red;">' + (response.data && response.data.message ? response.data.message : 'Error loading booking.') + '</p>');
        return;
      }
      var d  = response.data;
      var cv = d.current_values || {};
      var fields = (d.fields || []);

      var html = '<div id="hmwevents-edit-booking-form">' +
        '<input type="hidden" name="booking_id" value="' + bookingId + '">' +
        '<table class="form-table" style="margin:0;">';

      fields.forEach(function(field) {
        var value = (cv[field.key] !== undefined && cv[field.key] !== null) ? cv[field.key] : '';
        switch (field.type) {
          case 'email':
            html += editEmailRow(field.label, field.key, value);
            break;
          case 'tel':
            html += editTelRow(field.label, field.key, value);
            break;
          case 'number':
            html += editNumberRow(field.label, field.key, value);
            break;
          case 'date':
            html += editDateRow(field.label, field.key, value);
            break;
          case 'textarea':
            html += editTextareaRow(field.label, field.key, value);
            break;
          case 'select':
            html += editSelectRow(field.label, field.key, value, normalizeOptions(field.options));
            break;
          case 'radio':
            html += editRadioRow(field.label, field.key, value, normalizeOptions(field.options));
            break;
          case 'checkbox':
            html += editCheckboxRow(field.label, field.key, value);
            break;
          default:
            html += editRow(field.label, field.key, value);
        }
      });

      html +=
        '</table>' +
        '<div id="hmwevents-edit-booking-message" style="margin:12px 0; display:none;"></div>' +
        '<div style="margin-top:20px; display:flex; gap:10px; justify-content:flex-end;">' +
        '<button type="button" class="button" id="hmwevents-edit-booking-cancel">Cancel</button>' +
        '<button type="button" class="button button-primary" id="hmwevents-edit-booking-submit">Save Changes</button>' +
        '</div>' +
        '</div>';

      $body.html(html);
    });
  });

  $('#hmwevents-edit-booking-modal-close').on('click', function() {
    $('#hmwevents-edit-booking-modal').fadeOut(150);
  });

  $(document).on('click', '#hmwevents-edit-booking-cancel', function() {
    $('#hmwevents-edit-booking-modal').fadeOut(150);
  });

  $(document).on('click', '#hmwevents-edit-booking-modal', function(e) {
    if ($(e.target).is('#hmwevents-edit-booking-modal')) {
      $('#hmwevents-edit-booking-modal').fadeOut(150);
    }
  });

  $(document).on('click', '#hmwevents-edit-booking-submit', function(e) {
    var $form   = $('#hmwevents-edit-booking-form');
    var $submit = $('#hmwevents-edit-booking-submit');
    var $msg    = $('#hmwevents-edit-booking-message');
    var data    = {};
    $form.find(':input').serializeArray().forEach(function(field) {
      data[field.name] = field.value;
    });

    $submit.prop('disabled', true).text(cmsBookings.i18n.saving || 'Saving\u2026');
    $msg.hide().text('');

    $.ajax({
      url: restUrl + '/booking/update',
      type: 'POST',
      beforeSend: function(xhr) {
        xhr.setRequestHeader('X-WP-Nonce', restNonce);
      },
      data: JSON.stringify(data),
      contentType: 'application/json',
      success: function(response) {
        $msg.removeClass('notice-error').addClass('notice notice-success')
          .text(response.message || cmsBookings.i18n.bookingUpdated || 'Booking updated.').show();
        setTimeout(function() {
          $('#hmwevents-edit-booking-modal').fadeOut(150);
          location.reload();
        }, 1500);
      },
      error: function(xhr) {
        var errorMsg = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : cmsBookings.i18n.error;
        $msg.removeClass('notice-success').addClass('notice notice-error').text(errorMsg).show();
        $submit.prop('disabled', false).text('Save Changes');
      }
    });
  });

  // ------------------------------------------------------------------
  // Send Payment Link
  // ------------------------------------------------------------------
  $(document).on('click', '.hmwevents-send-payment-link', function(e) {
    e.preventDefault();
    var $link     = $(this);
    var bookingId = $link.data('booking-id');
    var confirmMsg = cmsBookings.i18n.confirmSendPaymentLink || 'Send a payment link to this customer for the remaining course amount?';

    if (!confirm(confirmMsg)) {
      return;
    }

    $link.text(cmsBookings.i18n.sending || 'Sending\u2026');

    $.ajax({
      url: restUrl + '/booking/send-payment-link',
      type: 'POST',
      beforeSend: function(xhr) {
        xhr.setRequestHeader('X-WP-Nonce', restNonce);
      },
      data: JSON.stringify({ booking_id: bookingId }),
      contentType: 'application/json',
      success: function(response) {
        alert(response.message || cmsBookings.i18n.paymentLinkSent || 'Payment link sent.');
        $link.text('Send Payment Link');
      },
      error: function(xhr) {
        var errorMsg = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : cmsBookings.i18n.error;
        alert(errorMsg);
        $link.text('Send Payment Link');
      }
    });
  });

  $(document).on('click', '.hmwevents-mark-as-paid', function(e) {
    e.preventDefault();
    e.stopPropagation();
    var $link     = $(this);
    var bookingId = $link.attr('data-booking-id');
    var confirmMsg = cmsBookings.i18n.confirmMarkAsPaid || 'Mark this booking as paid?';

    if (!bookingId) {
      alert('Error: No booking ID found.');
      return;
    }

    if (!confirm(confirmMsg)) {
      return;
    }

    $link.text(cmsBookings.i18n.saving || 'Saving\u2026');

    $.ajax({
      url: restUrl + '/booking/mark-as-paid',
      type: 'POST',
      beforeSend: function(xhr) {
        xhr.setRequestHeader('X-WP-Nonce', restNonce);
      },
      data: JSON.stringify({ booking_id: parseInt(bookingId, 10) }),
      contentType: 'application/json',
      success: function(response) {
        alert(response.message || cmsBookings.i18n.markAsPaidSuccess || 'Payment marked as paid successfully.');
        location.reload();
      },
      error: function(xhr) {
        var errorMsg = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : cmsBookings.i18n.error;
        alert(errorMsg);
        $link.text('Mark as Paid');
      }
    });
  });

  $(document).on('click', '.hmwevents-resend-invoice', function(e) {
    e.preventDefault();
    var $link     = $(this);
    var bookingId = $link.data('booking-id');
    var confirmMsg = cmsBookings.i18n.confirmResendInvoice || 'Resend the invoice email to this customer?';

    if (!confirm(confirmMsg)) {
      return;
    }

    $link.text(cmsBookings.i18n.sending || 'Sending\u2026');

    $.ajax({
      url: restUrl + '/booking/resend-invoice',
      type: 'POST',
      beforeSend: function(xhr) {
        xhr.setRequestHeader('X-WP-Nonce', restNonce);
      },
      data: JSON.stringify({ booking_id: parseInt(bookingId, 10) }),
      contentType: 'application/json',
      success: function(response) {
        alert(response.message || 'Invoice resent successfully.');
        $link.text('Resend Invoice');
      },
      error: function(xhr) {
        var errorMsg = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : cmsBookings.i18n.error;
        alert(errorMsg);
        $link.text('Resend Invoice');
      }
    });
  });

  $(document).on('click', '.hmwevents-mark-invoice-paid', function(e) {
    e.preventDefault();
    e.stopPropagation();
    var $link     = $(this);
    var bookingId = $link.attr('data-booking-id');
    var confirmMsg = cmsBookings.i18n.confirmMarkInvoicePaid || 'Mark this invoice as paid? This will record the EFT payment and send a receipt.';

    if (!bookingId) {
      alert('Error: No booking ID found.');
      return;
    }

    if (!confirm(confirmMsg)) {
      return;
    }

    var reference = prompt(cmsBookings.i18n.eftReferencePrompt || 'Enter the EFT / payment reference (optional):', '');
    if (reference === null) {
      return;
    }

    $link.text(cmsBookings.i18n.saving || 'Saving\u2026');

    $.ajax({
      url: restUrl + '/booking/mark-invoice-paid',
      type: 'POST',
      beforeSend: function(xhr) {
        xhr.setRequestHeader('X-WP-Nonce', restNonce);
      },
      data: JSON.stringify({ booking_id: parseInt(bookingId, 10), reference: reference }),
      contentType: 'application/json',
      success: function(response) {
        alert(response.message || 'Invoice marked as paid successfully.');
        location.reload();
      },
      error: function(xhr) {
        var errorMsg = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : cmsBookings.i18n.error;
        alert(errorMsg);
        $link.text('Mark Paid (EFT)');
      }
    });
  });

  $(document).on('click', '#hmwevents-generate-token', function(e) {
    e.preventDefault();
    var $btn = $(this);
    var eventId = $btn.data('event-id');
    var nonce = $btn.data('nonce');
    var email = $('#hmw-token-email').val().trim();
    var $result = $('#hmwevents-token-result');

    if (!email) {
      $result.removeClass('notice-success').addClass('notice notice-error').html('<p>Please enter a recipient email.</p>').show();
      return;
    }

    $btn.prop('disabled', true).text('Generating...');

    $.post(ajaxurl, {
      action: 'hmwevents_generate_token',
      _wpnonce: nonce,
      event_id: eventId,
      email: email
    }, function(response) {
      if (response && response.success) {
        $result.removeClass('notice-error').addClass('notice notice-success')
          .html('<p>' + response.data.message + '</p><p><input type="text" readonly value="' + escAttr(response.data.registration_url) + '" class="regular-text" onclick="this.select()"></p>')
          .show();
      } else {
        var msg = (response && response.data && response.data.message) ? response.data.message : 'Failed to generate token.';
        $result.removeClass('notice-success').addClass('notice notice-error').html('<p>' + msg + '</p>').show();
      }
      $btn.prop('disabled', false).text('Generate Registration Link');
    }).fail(function() {
      $result.removeClass('notice-success').addClass('notice notice-error').html('<p>Request failed.</p>').show();
      $btn.prop('disabled', false).text('Generate Registration Link');
    });
  });

  // ------------------------------------------------------------------
  // Waitlist Promote
  // ------------------------------------------------------------------
  $(document).on('click', '.hmwevents-promote-waitlist', function(e) {
    e.preventDefault();
    var $btn = $(this);
    var entryId = $btn.data('entry-id');
    var eventId = $btn.data('event-id');
    var nonce = $btn.data('nonce');

    if (!confirm('Promote this person from the waitlist? An invitation email will be sent with a private registration link.')) {
      return;
    }

    $btn.prop('disabled', true).text('Promoting\u2026');

    $.post(ajaxurl, {
      action: 'hmwevents_promote_waitlist',
      _wpnonce: nonce,
      entry_id: entryId,
      event_id: eventId
    }, function(response) {
      if (response && response.success) {
        $btn.text('Invited');
        setTimeout(function() { location.reload(); }, 1000);
      } else {
        var msg = (response && response.data && response.data.message) ? response.data.message : 'Failed to promote.';
        alert(msg);
        $btn.prop('disabled', false).text('Promote');
      }
    }).fail(function() {
      alert('Request failed.');
      $btn.prop('disabled', false).text('Promote');
    });
  });

  // ------------------------------------------------------------------
  // Edit form row helpers
  // ------------------------------------------------------------------
  function editRow(label, name, value) {
    return '<tr><th style="width:35%;">' + label + '</th>' +
      '<td><input type="text" name="' + name + '" value="' + escAttr(value) + '" class="regular-text"></td></tr>';
  }
  function editEmailRow(label, name, value) {
    return '<tr><th style="width:35%;">' + label + '</th>' +
      '<td><input type="email" name="' + name + '" value="' + escAttr(value) + '" class="regular-text"></td></tr>';
  }
  function editTelRow(label, name, value) {
    return '<tr><th style="width:35%;">' + label + '</th>' +
      '<td><input type="tel" name="' + name + '" value="' + escAttr(value) + '" class="regular-text"></td></tr>';
  }
  function editNumberRow(label, name, value) {
    return '<tr><th style="width:35%;">' + label + '</th>' +
      '<td><input type="number" name="' + name + '" value="' + escAttr(value) + '" class="regular-text"></td></tr>';
  }
  function editDateRow(label, name, value) {
    return '<tr><th>' + label + '</th>' +
      '<td><input type="date" name="' + name + '" value="' + escAttr(value) + '" class="regular-text"></td></tr>';
  }
  function editTextareaRow(label, name, value) {
    return '<tr><th>' + label + '</th>' +
      '<td><textarea name="' + name + '" class="regular-text" rows="3">' + escHtml(value) + '</textarea></td></tr>';
  }
  function editSelectRow(label, name, current, options) {
    var opts = options.map(function(o) {
      return '<option value="' + escAttr(o.val) + '"' + (String(o.val) === String(current) ? ' selected' : '') + '>' + escHtml(o.label) + '</option>';
    }).join('');
    return '<tr><th>' + label + '</th><td><select name="' + name + '" class="regular-text">' + opts + '</select></td></tr>';
  }
  function editRadioRow(label, name, current, options) {
    var radios = options.map(function(o) {
      return '<label style="margin-right:12px;"><input type="radio" name="' + name + '" value="' + escAttr(o.val) + '"' +
        (String(o.val) === String(current) ? ' checked' : '') + '> ' + escHtml(o.label) + '</label>';
    }).join('');
    return '<tr><th>' + label + '</th><td>' + radios + '</td></tr>';
  }
  function editCheckboxRow(label, name, value) {
    var checked = (value === '1' || value === 'true' || value === true || value === 1) ? ' checked' : '';
    return '<tr><th>' + label + '</th>' +
      '<td><input type="hidden" name="' + name + '" value="0">' +
      '<label><input type="checkbox" name="' + name + '" value="1"' + checked + '> ' + escHtml(label) + '</label></td></tr>';
  }
  function normalizeOptions(options) {
    if (Array.isArray(options)) {
      return options.map(function(o) {
        if (o && typeof o === 'object' && 'value' in o) {
          return {val: o.value, label: (o.label !== undefined ? o.label : o.value)};
        }
        return {val: o, label: o};
      });
    }
    return Object.entries(options || {}).map(function(pair) {
      return {val: pair[0], label: pair[1]};
    });
  }
  function escAttr(s) {
    return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }
  function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }
});
