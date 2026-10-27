const clock = document.getElementById('clock');
const menuBtn = document.getElementById('menuBtn');
const sidebar = document.getElementById('sidebar');
const accountMenu = document.getElementById('accountMenu');
const accountMenuToggle = document.getElementById('accountMenuToggle');
const accountMenuPanel = document.getElementById('accountMenuPanel');

function setAccountMenuOpen(open) {
    if (!accountMenuToggle || !accountMenuPanel) return;
    accountMenuPanel.hidden = !open;
    accountMenuToggle.setAttribute('aria-expanded', String(open));
}

if (accountMenu && accountMenuToggle && accountMenuPanel) {
    accountMenuToggle.addEventListener('click', () => {
        setAccountMenuOpen(accountMenuPanel.hidden);
    });

    document.addEventListener('click', event => {
        if (!accountMenu.contains(event.target)) setAccountMenuOpen(false);
    });

    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && !accountMenuPanel.hidden) {
            setAccountMenuOpen(false);
            accountMenuToggle.focus();
        }
    });
}

function updateClock() {
    if (!clock) return;
    clock.textContent = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

updateClock();
setInterval(updateClock, 30000);

const sidebarOverlay = document.getElementById('sidebarOverlay');
const sidebarCloseBtn = document.getElementById('sidebarCloseBtn');

function openSidebar() {
    if (!sidebar) return;
    sidebar.classList.add('show');
    if (sidebarOverlay) sidebarOverlay.classList.add('show');
    if (menuBtn) menuBtn.setAttribute('aria-expanded', 'true');
    if (window.innerWidth <= 1024) {
        document.body.style.overflow = 'hidden';
    }
    setTimeout(() => {
        if (sidebar && sidebar.classList.contains('show') && sidebarCloseBtn && window.innerWidth <= 1024) {
            sidebarCloseBtn.focus();
        }
    }, 50);
}

function closeSidebar() {
    if (!sidebar) return;
    const wasOpen = sidebar.classList.contains('show');
    sidebar.classList.remove('show');
    if (sidebarOverlay) sidebarOverlay.classList.remove('show');
    if (menuBtn) {
        menuBtn.setAttribute('aria-expanded', 'false');
        if (wasOpen && window.innerWidth <= 1024) {
            setTimeout(() => {
                if (document.activeElement === sidebarCloseBtn || !sidebar.contains(document.activeElement)) {
                    menuBtn.focus();
                }
            }, 80);
        }
    }
    document.body.style.overflow = '';
}

function toggleSidebar() {
    if (!sidebar) return;
    if (sidebar.classList.contains('show')) {
        closeSidebar();
    } else {
        openSidebar();
    }
}

if (menuBtn) {
    menuBtn.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        toggleSidebar();
    });
}

if (sidebarCloseBtn) {
    const handleCloseBtn = (e) => {
        if (e) {
            e.preventDefault();
            e.stopPropagation();
        }
        closeSidebar();
    };
    sidebarCloseBtn.addEventListener('click', handleCloseBtn);
    sidebarCloseBtn.addEventListener('touchend', handleCloseBtn);
}

if (sidebarOverlay) {
    const handleOverlayClose = (e) => {
        if (e) {
            e.preventDefault();
            e.stopPropagation();
        }
        closeSidebar();
    };
    sidebarOverlay.addEventListener('click', handleOverlayClose);
    sidebarOverlay.addEventListener('touchend', handleOverlayClose);
}

if (sidebar) {
    sidebar.addEventListener('click', (e) => {
        const link = e.target.closest('a');
        if (link && window.innerWidth <= 1024) {
            closeSidebar();
        }
    });
}

const handleEscapeKey = (e) => {
    if ((e.key === 'Escape' || e.key === 'Esc') && sidebar && sidebar.classList.contains('show')) {
        e.preventDefault();
        e.stopPropagation();
        closeSidebar();
    }
};
window.addEventListener('keydown', handleEscapeKey, true);
document.addEventListener('keydown', handleEscapeKey, true);

let touchStartX = 0;
let touchStartY = 0;

document.addEventListener('touchstart', (e) => {
    touchStartX = e.touches[0].clientX;
    touchStartY = e.touches[0].clientY;
}, { passive: true });

document.addEventListener('touchend', (e) => {
    const touchEndX = e.changedTouches[0].clientX;
    const touchEndY = e.changedTouches[0].clientY;

    const deltaX = touchEndX - touchStartX;
    const deltaY = touchEndY - touchStartY;

    if (Math.abs(deltaX) > 60 && Math.abs(deltaY) < 50) {
        if (deltaX < 0 && sidebar && sidebar.classList.contains('show')) {
            closeSidebar();
        }
        if (deltaX > 0 && touchStartX < 35 && sidebar && !sidebar.classList.contains('show')) {
            openSidebar();
        }
    }
}, { passive: true });

document.querySelectorAll('[data-confirm]').forEach(btn => {
    btn.addEventListener('click', event => {
        if (!confirm(btn.dataset.confirm)) {
            event.preventDefault();
        }
    });
});
