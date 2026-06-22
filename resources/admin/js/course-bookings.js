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
  $('#hmwevents-add-booking-btn').on('click', function() {
    // Clear all inputs in the form container (it's a div, not a form)
    $('#hmwevents-add-booking-form').find('input:not([type=hidden]), select, textarea').val('').prop('checked', false);
    $('#hmwevents-add-booking-message').hide().text('');
    $('#hmwevents-add-booking-submit').prop('disabled', false).text(cmsBookings.i18n.addBookingSubmit || 'Create Booking');
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

  $('#hmwevents-add-booking-submit').on('click', function() {
    var $container = $('#hmwevents-add-booking-form');
    var $submit = $(this);
    var $msg = $('#hmwevents-add-booking-message');

    // Basic required-field validation
    var missing = false;
    $container.find('[required]').each(function() {
      if (!$(this).val().trim()) { missing = true; $(this).focus(); return false; }
    });
    if (missing) {
      $msg.removeClass('notice-success').addClass('notice notice-error')
        .text('Please fill in all required fields.').show();
      return;
    }

    var data = {};
    $container.find('input[name], select[name], textarea[name]').each(function() {
      var $el = $(this);
      if ($el.is(':checkbox')) {
        data[$el.attr('name')] = $el.is(':checked') ? '1' : '0';
      } else {
        data[$el.attr('name')] = $el.val();
      }
    });

    $submit.prop('disabled', true).text(cmsBookings.i18n.saving || 'Saving\u2026');
    $msg.hide().text('');

    $.ajax({
      url: restUrl + '/booking/create-manual',
      type: 'POST',
      beforeSend: function(xhr) {
        xhr.setRequestHeader('X-WP-Nonce', restNonce);
      },
      data: JSON.stringify(data),
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
      var fields = (cmsBookings.bookingFields || []);

      var html = '<div id="hmwevents-edit-booking-form">' +
        '<input type="hidden" name="booking_id" value="' + bookingId + '">' +
        '<table class="form-table" style="margin:0;">';

      fields.forEach(function(field) {
        var value = (cv[field.key] !== undefined && cv[field.key] !== null) ? cv[field.key] : '';
        switch (field.type) {
          case 'email':
            html += editEmailRow(field.label, field.key, value);
            break;
          case 'date':
            html += editDateRow(field.label, field.key, value);
            break;
          case 'textarea':
            html += editTextareaRow(field.label, field.key, value);
            break;
          case 'select':
            var opts = Object.entries(field.options || {}).map(function(pair) {
              return {val: pair[0], label: pair[1]};
            });
            html += editSelectRow(field.label, field.key, value, opts);
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
      return '<option value="' + escAttr(o.val) + '"' + (o.val === current ? ' selected' : '') + '>' + escHtml(o.label) + '</option>';
    }).join('');
    return '<tr><th>' + label + '</th><td><select name="' + name + '" class="regular-text">' + opts + '</select></td></tr>';
  }
  function escAttr(s) {
    return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }
  function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }
});
