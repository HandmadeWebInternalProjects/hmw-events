(function () {
  'use strict';

  function parseColour(value) {
    value = (value || '').trim();

    if (/^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(value)) {
      if (value.length === 4) {
        return '#' + value[1] + value[1] + value[2] + value[2] + value[3] + value[3];
      }
      return value.toLowerCase();
    }

    var m = value.match(/^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*(?:,\s*(?:0|1|0?\.\d+)\s*)?\)$/i);
    if (!m) {
      return null;
    }

    return '#' + [m[1], m[2], m[3]].map(function (part) {
      return Math.min(255, parseInt(part, 10)).toString(16).padStart(2, '0');
    }).join('');
  }

  function enhance(input) {
    var swatch = document.createElement('input');
    swatch.type = 'color';
    swatch.className = 'hmw-theme-color-swatch';
    swatch.tabIndex = -1;
    swatch.value = parseColour(input.value) || '#ffffff';

    swatch.addEventListener('input', function () {
      input.value = swatch.value;
      input.dispatchEvent(new Event('change', { bubbles: true }));
    });

    input.addEventListener('change', function () {
      var parsed = parseColour(input.value);
      if (parsed) {
        swatch.value = parsed;
      }
    });

    input.classList.add('hmw-has-swatch');
    input.insertAdjacentElement('afterend', swatch);
  }

  function init() {
    document.querySelectorAll('input.hmw-theme-color-input').forEach(enhance);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
