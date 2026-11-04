"use strict";
document.querySelectorAll(".group-confirm-form").forEach(function (form) {
  form.addEventListener("submit", function (event) {
    if (!window.confirm("Apply this reviewed identity decision to the displayed candidates? Every decision will be audited.")) event.preventDefault();
  });
});
