(function ($) {
  'use strict';

  var stripe, card, data, attendeeIndex = 1, couponData = null, isParentChildren = false;

  function init() {
    data = window.hmwV3Booking || {};
    if (!data.eventId) return;

    isParentChildren = data.multiBookingMode === 'parent_children';

    attendeeIndex = parseInt($('#hmw_attendee_count_hidden').val(), 10) || 1;

    if (data.stripeKey && data.requiresPayment) {
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
    updateAttendanceFields();
    var minimumAttendees = currentMinAttendees();
    while (attendeeIndex < minimumAttendees) {
      addAttendee();
    }
    updateDobFieldState();
    updateAttendeeDetailsVisibility();
    updatePaymentFields();
    updateSessionPickerRowPrices();
    updatePriceDisplay();
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

    $('#hmw-v3-coupon-apply').on('click', function () {
      applyCoupon();
    });

    $('#hmw-v3-coupon-code').on('keypress', function (e) {
      if (e.which === 13) {
        e.preventDefault();
        applyCoupon();
      }
    });

    $(document).on('click', '#hmw-v3-coupon-remove', function () {
      removeCoupon();
    });

  $(document).on('change', '.hmw-v3-attendance-option input[type="radio"]', function () {
    updateAttendanceFields();
    syncAttendeeBlocks();
    updateDobFieldState();
    updateAttendeeDetailsVisibility();
    updateSessionPickerRowPrices();
    updatePriceDisplay();
  });

    $(document).on('change', 'input[name="payment_type"]', function () {
      updatePaymentFields();
      updatePriceDisplay();
    });

    $(document).on('change', 'select[name$="[attendee_role]"], input[name$="[date_of_birth]"]', function () {
      updatePriceDisplay();
    });
  }

  function selectedAttendanceType() {
    var option = selectedOption();
    if (option && option.option_type) {
      return option.option_type;
    }
    var $checked = $('input[name="attendance_type"]');
    if ($checked.attr('data-option-type')) {
      return $checked.attr('data-option-type');
    }
    return data.attendanceDefaultType || '';
  }

  function updateAttendanceFields() {
    var selected = selectedAttendanceType();

    $('.hmw-reg-field[data-attendance-types]').each(function () {
      var $field = $(this);
      var types = [];
      try {
        types = JSON.parse($field.attr('data-attendance-types') || '[]');
      } catch (e) {
        types = [];
      }

      var visible = types.length === 0 || types.indexOf(selected) !== -1;
      $field.toggle(visible);
      $field.find('input, select, textarea').prop('disabled', !visible);
    });
  }

  function updateDobFieldState() {
    var option = selectedOption();
    var isAgeBand = !!(option && option.price_mode === 'age_band');

    $('.hmw-v3-age-dob-field').each(function () {
      var $field = $(this);
      $field.toggle(isAgeBand);
      $field.find('input, select, textarea').prop('disabled', !isAgeBand);
      $field.find('input[name$="[date_of_birth]"]').prop('required', isAgeBand);
    });
  }

  function updateAttendeeDetailsVisibility() {
    var $section = $('.hmw-reg-section--attendee-details');
    if (!$section.length) return;

    var hasVisible = false;
    $section.find('.hmw-reg-field').each(function () {
      if ($(this).is(':visible')) {
        hasVisible = true;
        return false;
      }
    });

    $section.toggle(hasVisible);
  }

  function isNetTermsSelected() {
    return $('input[name="payment_type"]:checked').val() === 'net_terms';
  }

  function updatePaymentFields() {
    var netTerms = isNetTermsSelected();
    $('.hmw-v3-card-fields').toggle(!netTerms);
  }

  function selectedOption() {
    var selected = $('input[name="attendance_type"]').val();
    var $checked = $('input[name="attendance_type"]:checked');
    if ($checked.length) {
      selected = $checked.val();
    }
    if (!selected && data.attendanceOptions && data.attendanceOptions.length === 1) {
      selected = data.attendanceOptions[0].option_key || data.attendanceOptions[0].option_type;
    }
    if (data.attendanceOptions && selected) {
      for (var i = 0; i < data.attendanceOptions.length; i++) {
        var option = data.attendanceOptions[i];
        if (option.option_key ? option.option_key === selected : option.option_type === selected) {
          return option;
        }
      }
    }
    return null;
  }

  function currentComposition() {
    var option = selectedOption();
    if (option && option.composition) {
      return option.composition;
    }
    return { min_attendees: data.minAttendees || 1, max_attendees: data.maxAttendees || 1 };
  }

  function currentMinAttendees() {
    return Math.max(1, parseInt(currentComposition().min_attendees, 10) || 1);
  }

  function currentMaxAttendees() {
    var min = currentMinAttendees();
    var max = parseInt(currentComposition().max_attendees, 10);
    return Math.max(min, max || min);
  }

  function syncAttendeeBlocks() {
    var min = currentMinAttendees();
    var max = currentMaxAttendees();

    while (attendeeIndex < min && attendeeIndex < max) {
      addAttendee();
    }

    while (attendeeIndex > max) {
      var $last = $('#hmw-v3-attendee-blocks .hmw-v3-attendee-block').last();
      if (!$last.length) break;
      removeAttendee($last);
    }

    $('#hmw-v3-add-attendee-btn').toggle(attendeeIndex < max);
  }

  function ruleRolePrice(option, role) {
    if (!option || !option.pricing_rules) return null;
    var rules = option.pricing_rules;
    var fallback = null;
    for (var i = 0; i < rules.length; i++) {
      var rule = rules[i];
      var ruleRole = rule.role || 'any';
      if (ruleRole === role) return parseFloat(rule.price) || 0;
      if (ruleRole === 'any') fallback = parseFloat(rule.price) || 0;
    }
    return fallback;
  }

  function ageForDate(dob, eventDate) {
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

  function ruleAgeBandPrice(option, role, dob) {
    if (!option || !option.pricing_rules) return null;
    var age = ageForDate(dob, data.eventDate);
    var rules = option.pricing_rules;
    for (var i = 0; i < rules.length; i++) {
      var rule = rules[i];
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

  function collectAttendeeRoles() {
    var roles = [];
    $('select[name$="[attendee_role]"]').each(function () { roles.push($(this).val()); });
    $('input[type="hidden"][name$="[attendee_role]"]').each(function () { roles.push($(this).val()); });
    return roles;
  }

  function collectAttendeeDobs() {
    var dobs = [];
    $('input[name$="[date_of_birth]"]').each(function () { dobs.push($(this).val()); });
    return dobs;
  }

  function computeBase() {
    var option = selectedOption();
    if (!option) return parseFloat(data.basePrice) || 0;
    var mode = option.price_mode || 'flat';

    if (mode === 'per_attendee') {
      var total = 0;
      collectAttendeeRoles().forEach(function (role) {
        var p = ruleRolePrice(option, role);
        total += (p === null ? 0 : p);
      });
      return total;
    }

    if (mode === 'age_band') {
      var sum = 0;
      var roles = collectAttendeeRoles();
      var dobs = collectAttendeeDobs();
      for (var i = 0; i < roles.length; i++) {
        var price = ruleAgeBandPrice(option, roles[i], dobs[i] || '');
        sum += (price === null ? 0 : price);
      }
      return sum;
    }

    return parseFloat(option.display_price !== undefined ? option.display_price : option.price) || 0;
  }

  function attendeeTitle(index) {
    return (isParentChildren ? 'Child ' : 'Attendee ') + (isParentChildren ? index : index + 1);
  }

  function addAttendee() {
    if (attendeeIndex >= currentMaxAttendees()) return;

    var template = document.getElementById('hmw-v3-attendee-template');
    if (!template) return;

    var clone = template.content.cloneNode(true);
    var block = $(clone).find('.hmw-v3-attendee-block');

    block.attr('data-attendee-index', attendeeIndex);
    block.find('.hmw-reg-attendee-title').text(attendeeTitle(attendeeIndex));

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

    $('#hmw-v3-add-attendee-btn').toggle(attendeeIndex < currentMaxAttendees());
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

      $(this).find('.hmw-reg-attendee-title').text(attendeeTitle(newIndex));

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
    $('#hmw-v3-add-attendee-btn').toggle(attendeeIndex < currentMaxAttendees());
  }

  function updateAttendeeCount() {
    $('#hmw_attendee_count_hidden').val(attendeeIndex);
    updatePriceDisplay();
  }

  function sessionPickerTotal() {
    var option = selectedOption();
    if (option && parseFloat(option.display_price) > 0) {
      return parseFloat(option.display_price) * attendeeIndex;
    }
    var sum = 0;
    $('.hmw-session-picker input[data-price]:checked').each(function () {
      sum += parseFloat($(this).data('price')) || 0;
    });
    return sum * attendeeIndex;
  }

  function updateSessionPickerRowPrices() {
    var option = selectedOption();
    var optionDrivesPricing = !!(option && parseFloat(option.display_price) > 0);
    $('.hmw-session-picker-price').toggle(!optionDrivesPricing);
  }

  function hasSessionPicker() {
    return $('.hmw-session-picker').length > 0;
  }

  function updateSessionPickerTotal() {
    $('[data-session-total-for]').each(function () {
      $(this).text('Sessions total: $' + sessionPickerTotal().toFixed(2));
    });
  }

  function computeSurcharge(base) {
    var mode = data.surchargeMode || 'flat';
    var rate = parseFloat(data.surchargeRate);
    if (isNaN(rate)) rate = parseFloat(data.surcharge) || 0;
    if (mode === 'percent') {
      return Math.round(base * rate) / 100;
    }
    return rate;
  }

  function updatePriceDisplay() {
    var base = hasSessionPicker() ? sessionPickerTotal() : computeBase();
    var surcharge = computeSurcharge(base);
    var subTotal = base + surcharge;
    var total = subTotal;
    var discountAmount = 0;

    var option = selectedOption();
    var optionLabel = option && option.label ? option.label : (data.attendanceDefaultType || '');
    $('.hmw-v3-summary-option').text(attendeeIndex + ' x ' + optionLabel);

    if (couponData && couponData.coupon) {
      var dcType = couponData.coupon.discount_type;
      var dcValue = parseFloat(couponData.coupon.discount_value) || 0;
      if (dcType === 'percent' || dcType === 'percentage') {
        discountAmount = subTotal * (Math.min(100, dcValue) / 100);
      } else {
        discountAmount = Math.min(dcValue, subTotal);
      }
      total = Math.max(0, subTotal - discountAmount);
    }

    $('.hmw-v3-course-fee').text('$' + base.toFixed(2));
    $('.hmw-v3-surcharge').text('$' + surcharge.toFixed(2));
    $('#hmw-v3-total').text('$' + total.toFixed(2));

    var discountEl = $('.hmw-v3-coupon-discount-line');
    if (discountAmount > 0) {
      var discountText = couponData.coupon.discount_type === 'percent' || couponData.coupon.discount_type === 'percentage'
        ? couponData.coupon.discount_value + '% off'
        : '-$' + discountAmount.toFixed(2);
      if (!discountEl.length) {
        $('.hmw-v3-summary-total-row').before(
          '<p class="hmw-v3-coupon-discount-line" style="color:#16a34a;">' +
          'Coupon discount: <span>' + discountText + '</span></p>'
        );
      } else {
        discountEl.find('span').text(discountText);
        discountEl.show();
      }
    } else if (discountEl.length) {
      discountEl.hide();
    }

    var btnText = isNetTermsSelected() || !data.requiresPayment || total <= 0
      ? 'Submit Registration'
      : 'Pay $' + total.toFixed(2) + ' — Submit Registration';
    $('.hmw-v3-submit-btn').text(btnText);

    updateSessionPickerTotal();
  }

  function applyCoupon() {
    var code = $('#hmw-v3-coupon-code').val().trim();
    if (!code) {
      showCouponMessage('Please enter a coupon code.', 'error');
      return;
    }

    var $applyBtn = $('#hmw-v3-coupon-apply');
    $applyBtn.prop('disabled', true).text('Checking...');

    var email = '';
    var emailInput = $('input[name="email"], input[name="attendees[0][email]"]').first();
    if (emailInput.length) {
      email = emailInput.val().trim();
    }

    $.ajax({
      url: data.restUrl + '/coupon/validate',
      method: 'POST',
      contentType: 'application/json',
      data: JSON.stringify({
        coupon_code: code,
        event_id: data.eventId,
        organizer_id: data.organizerId || 0,
        registrant_email: email,
        payment_type: 'full',
        amount: computeBase() + computeSurcharge(computeBase())
      }),
      success: function (response) {
        $applyBtn.prop('disabled', false).text('Apply');
        if (response.success) {
          couponData = response;
          showCouponDetails(response);
          updatePriceDisplay();
        } else {
          showCouponMessage(response.message || 'Invalid coupon code.', 'error');
        }
      },
      error: function () {
        $applyBtn.prop('disabled', false).text('Apply');
        showCouponMessage('Unable to validate code. Please try again.', 'error');
      }
    });
  }

  function removeCoupon() {
    couponData = null;
    $('#hmw-v3-coupon-code').val('');
    $('#hmw-v3-coupon-data').val('');
    $('#hmw-v3-coupon-details').hide();
    $('#hmw-v3-coupon-message').hide();
    updatePriceDisplay();
  }

  function showCouponMessage(msg, type) {
    var $msg = $('#hmw-v3-coupon-message');
    $msg.text(msg)
       .removeClass('hmw-v3-coupon-message--success hmw-v3-coupon-message--error')
       .addClass('hmw-v3-coupon-message--' + type)
       .show();
    $('#hmw-v3-coupon-details').hide();
  }

  function showCouponDetails(response) {
    var $msg = $('#hmw-v3-coupon-message').hide();
    var $details = $('#hmw-v3-coupon-details');
    var $desc = $('#hmw-v3-coupon-description');

    if (response.coupon) {
      var coupon = response.coupon;
      var discountText = '';
      if (coupon.discount_type === 'percent' || coupon.discount_type === 'percentage') {
        discountText = coupon.discount_value + '% off';
      } else {
        discountText = '$' + parseFloat(coupon.discount_value).toFixed(2) + ' off';
      }
      $desc.text(coupon.code + ' — ' + discountText + (coupon.description ? ' — ' + coupon.description : ''));
      $('#hmw-v3-coupon-data').val(JSON.stringify(response));
      $details.show();
    }
  }

  function handleSubmit() {
    var $form = $('.hmw-registration-form--v3');
    var $btn = $('.hmw-v3-submit-btn');
    var $spinner = $('#hmwevents-submit-spinner');
    var paymentType = $('input[name="payment_type"]:checked').val();
    var isNetTerms = paymentType === 'net_terms';
    var sessionTotal = hasSessionPicker() ? sessionPickerTotal() : 0;
    var paymentDue = (computeBase() > 0 || sessionTotal > 0) && !isNetTerms;
    var requiresPayment = data.requiresPayment && paymentDue && !!card;

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

    if (data.requiresPayment && paymentDue && !card) {
      showError('Payment is required but the card field failed to load. Please reload the page and try again.');
      $btn.prop('disabled', false);
      $spinner.hide();
      return;
    }

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
    if (paymentMethodId) {
      formData.payment_method_id = paymentMethodId;
    }

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

      if ($(this).is(':checkbox') || $(this).is(':radio')) {
        if (!$(this).prop('checked')) return;

        if (name.slice(-2) === '[]') {
          var baseName = name.slice(0, -2);
          if (!Array.isArray(formData[baseName])) {
            formData[baseName] = [];
          }
          formData[baseName].push($(this).val());
          return;
        }

        formData[name] = $(this).val();
        return;
      }

      formData[name] = $(this).val();
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
      $(document).on('change', '.hmw-session-picker input[data-price]', updatePriceDisplay);
      init();
    }
  });
})(jQuery);
