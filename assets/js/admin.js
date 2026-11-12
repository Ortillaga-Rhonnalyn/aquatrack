/** AquaTrack: small-screen sidebar interaction only. */
'use strict';

document.addEventListener('DOMContentLoaded', () => {
    const menu = document.getElementById('menuToggle');
    const sidebar = document.getElementById('adminSidebar');
    const backdrop = document.getElementById('sidebarBackdrop');
    if (!menu || !sidebar || !backdrop) return;

    function setOpen(open) {
        sidebar.classList.toggle('is-open', open);
        backdrop.classList.toggle('is-visible', open);
        document.body.classList.toggle('nav-open', open);
        menu.setAttribute('aria-expanded', String(open));
        menu.setAttribute('aria-label', open ? 'Close navigation' : 'Open navigation');
    }

    menu.addEventListener('click', () => setOpen(!sidebar.classList.contains('is-open')));
    backdrop.addEventListener('click', () => setOpen(false));
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && sidebar.classList.contains('is-open')) {
            setOpen(false);
            menu.focus();
        }
    });
    window.addEventListener('resize', () => {
        if (window.innerWidth > 860) setOpen(false);
    });
});
