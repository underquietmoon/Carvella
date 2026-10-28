document.addEventListener('DOMContentLoaded', function() {
    // Keep the collapse state exposed to assistive technology while CSS animates it.
    const sidebar = document.getElementById('sideNavBar');
    const sidebarToggle = document.getElementById('sidebar-toggle');
    if (sidebar && sidebarToggle) {
        const syncSidebarState = () => {
            const collapsed = sidebar.classList.contains('collapsed');
            sidebarToggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        };
        syncSidebarState();
        sidebarToggle.addEventListener('click', syncSidebarState);
    }
    // The old [onclick*="confirm"] block was removed: no element in the
    // project carries that attribute any more (destructive admin actions
    // are POST forms using data-confirm), and on a <button> — which has no
    // href — it would have assigned window.location.href = null. The
    // delegated confirm + submit lock in main.js now covers every form.
});
