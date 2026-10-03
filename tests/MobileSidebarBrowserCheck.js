// Read-only browser assertion. Evaluate after tapping a submenu in the real user/admin shell.
// Exercise first-open, close, reopen and sibling switching at 390px and 767px.
() => {
    const sidebar = document.querySelector('.navbar-static-side');
    if (!sidebar || innerWidth >= 768 || !document.body.classList.contains('mini-navbar')) {
        throw new Error('Open the mobile drawer before checking its submenu layout');
    }
    const bounds = sidebar.getBoundingClientRect();
    const visible = [];
    for (const submenu of document.querySelectorAll('#side-menu .nav-second-level, #side-menu .nav-third-level')) {
        const style = getComputedStyle(submenu);
        if (style.display === 'none') continue;
        if (!submenu.classList.contains('in')) throw new Error('Collapsed submenu exposed by sticky hover');
        const rect = submenu.getBoundingClientRect();
        const trigger = submenu.previousElementSibling;
        if (style.position !== 'static' || rect.left < bounds.left - 1 || rect.right > bounds.right + 1
            || rect.top < trigger.getBoundingClientRect().bottom - 1) {
            throw new Error('Mobile submenu escaped normal drawer flow: ' + trigger.textContent.trim());
        }
        const label = trigger.querySelector('.nav-label');
        if (label && getComputedStyle(label).position === 'absolute') {
            throw new Error('Desktop hover label is active on mobile');
        }
        visible.push(trigger.textContent.trim());
    }
    if (document.documentElement.scrollWidth > innerWidth) throw new Error('Page overflows horizontally');
    return {width: innerWidth, visible};
}
