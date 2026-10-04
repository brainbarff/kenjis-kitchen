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

if (menuBtn && sidebar) {
    menuBtn.addEventListener('click', () => sidebar.classList.toggle('show'));
}

document.querySelectorAll('[data-confirm]').forEach(btn => {
    btn.addEventListener('click', event => {
        if (!confirm(btn.dataset.confirm)) {
            event.preventDefault();
        }
    });
});
