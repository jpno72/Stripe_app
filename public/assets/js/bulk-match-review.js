// UX confirmation only. Authorization, CSRF, counts and eligibility are revalidated server-side.
document.querySelectorAll('form[data-confirm-bulk]').forEach(function (form) {
  form.addEventListener('submit', function (event) {
    if (!window.confirm(form.getAttribute('data-confirm-bulk'))) event.preventDefault();
  });
});
