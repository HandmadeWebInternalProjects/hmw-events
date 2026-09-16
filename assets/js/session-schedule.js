(function () {
  'use strict';

  document.addEventListener('click', function (event) {
    var button = event.target.closest('.hmwevents-session-schedule__toggle');

    if (!button) {
      return;
    }

    var schedule = button.closest('.hmwevents-session-schedule');

    if (!schedule) {
      return;
    }

    var expanded = button.getAttribute('aria-expanded') === 'true';

    button.setAttribute('aria-expanded', expanded ? 'false' : 'true');
    button.textContent = expanded
      ? button.dataset.collapsedLabel
      : button.dataset.expandedLabel;

    schedule.querySelectorAll('.hmwevents-session-schedule__extra').forEach(function (row) {
      row.toggleAttribute('hidden', expanded);
    });
  });
})();
