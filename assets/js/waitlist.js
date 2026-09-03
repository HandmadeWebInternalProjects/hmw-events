(function () {
  'use strict';

  function showMessage(form, message, isError) {
    var box = form.querySelector('.hmwevents-waitlist-message');
    if (!box) return;
    box.textContent = message;
    box.classList.toggle('hmwevents-waitlist-message--error', Boolean(isError));
    box.style.display = 'block';
  }

  function setPending(form, pending) {
    var button = form.querySelector('[type="submit"]');
    if (!button) return;
    button.disabled = pending;
    var text = button.querySelector('.btn-text');
    var spinner = button.querySelector('.btn-spinner');
    if (text) text.style.display = pending ? 'none' : '';
    if (spinner) spinner.style.display = pending ? '' : 'none';
  }

  document.addEventListener('submit', function (event) {
    var form = event.target.closest('#hmwevents-waitlist-form');
    if (!form) return;

    event.preventDefault();

    if (typeof hmwWaitlistData === 'undefined' || !hmwWaitlistData.restUrl) return;

    var wrapper = form.closest('.hmwevents-waitlist-wrapper');
    var eventId = wrapper ? parseInt(wrapper.getAttribute('data-event-id'), 10) : 0;
    var emailField = form.querySelector('[name="email"]');

    var email = emailField ? emailField.value.trim() : '';

    if (!email || email.indexOf('@') === -1) {
      showMessage(form, 'Please enter a valid email address.', true);
      return;
    }

    setPending(form, true);

    fetch(hmwWaitlistData.restUrl + 'waitlist/join', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': hmwWaitlistData.nonce
      },
      body: JSON.stringify({
        event_id: eventId,
        first_name: (form.querySelector('[name="first_name"]') || {}).value || '',
        last_name: (form.querySelector('[name="last_name"]') || {}).value || '',
        email: email
      })
    })
      .then(function (response) {
        return response.json().then(function (data) {
          return { ok: response.ok, data: data };
        });
      })
      .then(function (result) {
        if (result.ok && result.data.success) {
          form.querySelectorAll('.hmwevents-form-section input').forEach(function (input) {
            input.value = '';
          });
          showMessage(form, result.data.message, false);
          return;
        }

        var code = result.data.code || '';
        var message = result.data.message || 'Something went wrong. Please try again.';
        if (code === 'not_full') {
          message = 'Spots are now available — please reload the page to book.';
        }
        showMessage(form, message, true);
      })
      .catch(function () {
        showMessage(form, 'Something went wrong. Please try again.', true);
      })
      .finally(function () {
        setPending(form, false);
      });
  });
})();
