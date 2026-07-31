(function ($) {
  'use strict';

  var stripe, card, data, attendeeIndex = 1;

  function init() {
    data = window.hmwV3Booking || {};
    if (!data.eventId) return;

    if (data.stripeKey && data.price > 0) {
      stripe = Stripe(data.stripeKey);
      var elements = stripe.elements();
      card = elements.create('card', {
        style: {
          base: { fontSize: '16px', color: '#32325d', '::placeholder': { color: '#aab7c4' } }
        }
      });
      var cardEl = document.getElementById('hmwevents-card-element');
      if (cardEl) card.mount(cardEl);
    }

    bindEvents();
  }

  function bindEvents() {
    $('.hmw-registration-form--v3').on('submit', function (e) {
      e.preventDefault();
      handleSubmit();
    });

    $('#hmw-v3-add-attendee-btn').on('click', function () {
      addAttendee();
    });

    $(document).on('click', '.hmw-v3-remove-attendee', function () {
      removeAttendee($(this).closest('.hmw-v3-attendee-block'));
    });
  }

  function addAttendee() {
    if (attendeeIndex >= data.maxAttendees) return;

    var template = document.getElementById('hmw-v3-attendee-template');
    if (!template) return;

    var clone = template.content.cloneNode(true);
    var block = $(clone).find('.hmw-v3-attendee-block');

    block.attr('data-attendee-index', attendeeIndex);
    block.find('.hmw-reg-attendee-title').text('Attendee ' + (attendeeIndex + 1));

    block.find('input, select, textarea').each(function () {
      var name = $(this).attr('name');
      if (name) {
        $(this).attr('name', name.replace('__INDEX__', attendeeIndex));
      }
      var id = $(this).attr('id');
      if (id) {
        $(this).attr('id', id.replace('__INDEX__', attendeeIndex));
      }
    });

    block.find('label').each(function () {
      var fr = $(this).attr('for');
      if (fr) {
        $(this).attr('for', fr.replace('__INDEX__', attendeeIndex));
      }
    });

    $('#hmw-v3-attendee-blocks').append(block);

    attendeeIndex++;
    updateAttendeeCount();

    $('#hmw-v3-add-attendee-btn').toggle(attendeeIndex <= data.maxAttendees);
  }

  function removeAttendee(block) {
    block.remove();
    reindexAttendees();
  }

  function reindexAttendees() {
    var blocks = $('#hmw-v3-attendee-blocks .hmw-v3-attendee-block');
    var newIndex = 1;
    blocks.each(function () {
      var oldIndex = $(this).data('attendee-index');
      $(this).attr('data-attendee-index', newIndex);

      $(this).find('.hmw-reg-attendee-title').text('Attendee ' + (newIndex + 1));

      $(this).find('input, select, textarea').each(function () {
        var name = $(this).attr('name');
        if (name) {
          $(this).attr('name', name.replace('attendees[' + oldIndex + ']', 'attendees[' + newIndex + ']'));
        }
        var id = $(this).attr('id');
        if (id) {
          $(this).attr('id', id.replace('attendees' + oldIndex, 'attendees' + newIndex));
        }
      });

      $(this).find('label').each(function () {
        var fr = $(this).attr('for');
        if (fr) {
          $(this).attr('for', fr.replace('attendees' + oldIndex, 'attendees' + newIndex));
        }
      });

      newIndex++;
    });
    attendeeIndex = newIndex;
    updateAttendeeCount();
    $('#hmw-v3-add-attendee-btn').toggle(attendeeIndex <= data.maxAttendees);
  }

  function updateAttendeeCount() {
    $('#hmw_attendee_count_hidden').val(attendeeIndex);
    updatePriceDisplay();
  }

  function updatePriceDisplay() {
    var total = (data.price * attendeeIndex).toFixed(2);
    $('.hmw-reg-payment-summary strong').text('$' + total);
    $('.hmw-v3-submit-btn').text('Pay $' + total + ' — Submit Registration');
  }

  function handleSubmit() {
    var $form = $('.hmw-registration-form--v3');
    var $btn = $('.hmw-v3-submit-btn');
    var $spinner = $('#hmwevents-submit-spinner');
    var paymentType = $('input[name="payment_type"]:checked').val();
    var isNetTerms = paymentType === 'net_terms';
    var requiresPayment = data.price > 0 && !!card && !isNetTerms;

    $btn.prop('disabled', true);
    $spinner.show();

    var invalidFields = $form.find(':invalid');
    if (invalidFields.length > 0) {
      invalidFields.first().focus();
      $('.hmw-v3-error').remove();
      var $alert = $('<div class="hmw-v3-error" style="color:#b32d2e; margin-bottom:12px;">Please fill in all required fields highlighted above.</div>');
      $('.hmw-v3-submit-btn').before($alert);
      $alert.delay(3000).fadeOut(function () { $(this).remove(); });
      $btn.prop('disabled', false).text(data.submitText || 'Submit Registration');
      $spinner.hide();
      return;
    }

    $('.hmw-v3-error').remove();

    if (requiresPayment) {
      stripe.createPaymentMethod({ type: 'card', card: card }).then(function (result) {
        if (result.error) {
          $('#hmwevents-card-errors').text(result.error.message);
          $btn.prop('disabled', false);
          $spinner.hide();
          return;
        }
        submitForm(result.paymentMethod.id);
      });
    } else {
      submitForm(null);
    }
  }

  function submitForm(paymentMethodId) {
    var formData = collectFormData();
    formData.action = 'hmwevents_submit_registration';
    formData._hmwevents_nonce = data.nonce;

    $.ajax({
      url: data.ajaxUrl,
      method: 'POST',
      data: formData,
      dataType: 'json',
      success: function (response) {
        if (response.success) {
          if (response.data && response.data.redirect) {
            window.location.href = response.data.redirect;
          } else {
            showSuccess(response.data);
          }
        } else {
          showError(response.data && response.data.message ? response.data.message : 'Submission failed.');
        }
      },
      error: function () {
        showError('An error occurred. Please try again.');
      },
      complete: function () {
        $('.hmw-v3-submit-btn').prop('disabled', false);
        $('#hmwevents-submit-spinner').hide();
      }
    });
  }

  function collectFormData() {
    var formData = {};
    $('.hmw-registration-form--v3').find('input, select, textarea').each(function () {
      var name = $(this).attr('name');
      if (!name) return;
      var val = $(this).is(':checkbox') ? ($(this).prop('checked') ? '1' : '') : $(this).val();
      formData[name] = val;
    });
    return formData;
  }

  function showSuccess(result) {
    var msg = result && result.message ? result.message : 'Registration submitted successfully!';
    $('.hmw-registration-form--v3').replaceWith(
      '<div style="padding:24px;background:#f0fdf4;border:1px solid #22c55e;border-radius:6px;">' +
      '<h3 style="color:#166534;margin:0 0 8px;">Thank you!</h3>' +
      '<p style="color:#166534;">' + msg + '</p></div>'
    );
  }

  function showError(msg) {
    var $form = $('.hmw-registration-form--v3');
    var errEl = $form.find('.hmw-form-error');
    if (!errEl.length) {
      errEl = $('<div class="hmw-form-error" style="color:#b32d2e;margin-bottom:12px;"></div>').prependTo($form);
    }
    errEl.text(msg).fadeIn();
    setTimeout(function () { errEl.fadeOut(); }, 5000);
  }

  $(function () {
    if ($('.hmw-registration-form--v3').length) {
      init();
    }
  });
})(jQuery);
