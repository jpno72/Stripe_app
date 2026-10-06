(() => {
  const toggle = document.querySelector('[data-sidebar-toggle]');
  const close = document.querySelector('[data-sidebar-close]');
  const sidebar = document.querySelector('.app-sidebar');
  if (!toggle) return;
  const mobile = window.matchMedia('(max-width: 991.98px)');
  const setOpen = (open, returnFocus = true) => {
    document.body.classList.toggle('sidebar-open', open);
    toggle.setAttribute('aria-expanded', String(open));
    toggle.setAttribute('aria-label', open ? 'Close navigation' : 'Open navigation');
    if (sidebar) sidebar.inert = mobile.matches && !open;
    if (open) document.querySelector('.app-sidebar .nav-item')?.focus();
    else if (returnFocus) toggle.focus();
  };
  if (sidebar) sidebar.inert = mobile.matches;
  toggle.addEventListener('click', () => setOpen(!document.body.classList.contains('sidebar-open')));
  close?.addEventListener('click', () => setOpen(false));
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && document.body.classList.contains('sidebar-open')) setOpen(false);
  });
  mobile.addEventListener('change', (event) => {
    if (!event.matches) setOpen(false, false);
    else if (sidebar) sidebar.inert = !document.body.classList.contains('sidebar-open');
  });
})();
