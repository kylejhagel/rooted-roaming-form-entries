/* Reset only the configured newsletter form after successful CF7 submission. */
(function () {
  'use strict';
  document.addEventListener('wpcf7mailsent', function (event) {
    var formId = Number(window.rrfeNewsletterFormId || 0);
    if (!formId || Number(event.detail && event.detail.contactFormId) !== formId) {
      return;
    }
    var form = event.target.querySelector('form');
    if (!form) {
      return;
    }
    // CF7 normally resets successful forms itself; explicitly reset for themes
    // or integrations that leave custom input values behind.
    form.reset();
  });
}());
