const clock = document.getElementById('clock');
const menuBtn = document.getElementById('menuBtn');
const sidebar = document.getElementById('sidebar');

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
