const clock = document.getElementById('clock');
const menuBtn = document.getElementById('menuBtn');
const sidebar = document.getElementById('sidebar');
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

/* ==============================================================================
   REUSABLE KENJI'S KITCHEN NOTIFICATIONS & CONFIRMATION DIALOGS
   ============================================================================== */
(function initKenjiDialogs() {
    function ensureToastContainer() {
        let container = document.getElementById('kkToastContainer');
        if (!container) {
            container = document.createElement('div');
            container.id = 'kkToastContainer';
            container.className = 'kk-toast-container';
            container.setAttribute('role', 'region');
            container.setAttribute('aria-label', 'Notifications');
            container.setAttribute('aria-live', 'polite');
            container.setAttribute('aria-atomic', 'false');
            document.body.appendChild(container);
        }
        return container;
    }

    const typeMeta = {
        success: { icon: 'bi-check-circle-fill', defaultTitle: 'Success' },
        error:   { icon: 'bi-exclamation-octagon-fill', defaultTitle: 'Error' },
        warning: { icon: 'bi-exclamation-triangle-fill', defaultTitle: 'Notice' },
        info:    { icon: 'bi-info-circle-fill', defaultTitle: 'Information' }
    };

    window.kkNotify = function kkNotify(message, type = 'info', options = {}) {
        const cleanType = typeMeta[type] ? type : 'info';
        const meta = typeMeta[cleanType];
        const opts = typeof options === 'string' ? { title: options } : (options || {});
        const title = opts.title || meta.defaultTitle;
        const msgText = String(message ?? '');
        const duration = typeof opts.duration === 'number' ? opts.duration : 4200;
        const dedupeKey = String(opts.dedupeKey || `${cleanType}::${title}::${msgText}`);
        const container = ensureToastContainer();

        const existing = Array.from(container.children).find(el => el.dataset.dedupeKey === dedupeKey);
        if (existing) {
            if (existing._dismissTimer) {
                clearTimeout(existing._dismissTimer);
                existing._dismissTimer = null;
            }
            const titleNode = existing.querySelector('.kk-toast-title');
            const msgNode = existing.querySelector('.kk-toast-msg');
            if (titleNode) titleNode.textContent = String(title);
            if (msgNode) msgNode.textContent = msgText;
            existing.classList.add('show');
            if (duration > 0) {
                existing._dismissTimer = setTimeout(() => existing._dismissFn && existing._dismissFn(), duration);
            }
            return { dismiss: () => existing._dismissFn && existing._dismissFn() };
        }

        while (container.children.length >= 2) {
            const oldest = container.firstElementChild;
            if (oldest && oldest._dismissTimer) clearTimeout(oldest._dismissTimer);
            if (oldest) oldest.remove();
        }

        const toast = document.createElement('div');
        toast.className = `kk-toast kk-toast-${cleanType}`;
        toast.dataset.dedupeKey = dedupeKey;
        toast.setAttribute('role', cleanType === 'error' ? 'alert' : 'status');

        const iconWrap = document.createElement('div');
        iconWrap.className = 'kk-toast-icon';
        const iconEl = document.createElement('i');
        iconEl.className = `bi ${meta.icon}`;
        iconEl.setAttribute('aria-hidden', 'true');
        iconWrap.appendChild(iconEl);

        const bodyWrap = document.createElement('div');
        bodyWrap.className = 'kk-toast-body';
        const titleEl = document.createElement('strong');
        titleEl.className = 'kk-toast-title';
        titleEl.textContent = String(title);
        const msgEl = document.createElement('span');
        msgEl.className = 'kk-toast-msg';
        msgEl.textContent = msgText;
        bodyWrap.appendChild(titleEl);
        bodyWrap.appendChild(msgEl);

        const closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'kk-toast-close';
        closeBtn.setAttribute('aria-label', 'Dismiss notification');
        closeBtn.innerHTML = '<i class="bi bi-x-lg" aria-hidden="true"></i>';

        const dismiss = () => {
            if (toast._dismissTimer) {
                clearTimeout(toast._dismissTimer);
                toast._dismissTimer = null;
            }
            toast.classList.remove('show');
            setTimeout(() => {
                if (toast.parentNode) toast.parentNode.removeChild(toast);
            }, 200);
        };

        toast._dismissFn = dismiss;
        closeBtn.addEventListener('click', dismiss);

        toast.appendChild(iconWrap);
        toast.appendChild(bodyWrap);
        toast.appendChild(closeBtn);
        container.appendChild(toast);

        requestAnimationFrame(() => {
            toast.classList.add('show');
        });

        if (duration > 0) {
            toast._dismissTimer = setTimeout(dismiss, duration);
        }

        return { dismiss };
    };

    function inferConfirmConfig(rawText, triggerEl) {
        if (triggerEl?.dataset.confirmKind === 'approval-reject') {
            return {
                title: 'Reject this request?',
                message: 'The requested changes will not be applied. This decision will be recorded in Approval History.',
                confirmText: 'Reject Request',
                variant: 'danger',
                theme: 'approval-reject',
                details: [
                    { label: 'Request', value: '#' + triggerEl.dataset.requestId + ' · ' + triggerEl.dataset.requestType },
                    { label: 'Item / category', value: triggerEl.dataset.targetName }
                ]
            };
        }
        if (triggerEl?.dataset.confirmKind === 'inventory-archive') {
            return {
                title: 'Request item archive?',
                message: 'Send this request to the admin for review. The item stays active until the archive request is approved.',
                confirmText: 'Submit Request',
                variant: 'primary',
                theme: 'inventory-archive',
                details: [{ label: 'Menu item', value: triggerEl.dataset.itemName || 'Selected item' }]
            };
        }
        const text = String(rawText || '').trim();
        const lower = text.toLowerCase();
        const customTitle = triggerEl?.dataset?.confirmTitle || '';
        const customBtn = triggerEl?.dataset?.confirmBtn || '';
        const isDangerBtn = triggerEl?.classList?.contains('btn-danger') || lower.includes('delete') || lower.includes('archive') || lower.includes('reject');

        if (customTitle) {
            return {
                title: customTitle,
                message: text,
                confirmText: customBtn || 'Confirm',
                variant: isDangerBtn ? 'danger' : 'primary'
            };
        }

        if (lower.includes('archive customer')) {
            return {
                title: 'Archive Customer Account?',
                message: 'This account will be archived. Previous orders and transaction records will be preserved.',
                confirmText: customBtn || 'Archive Account',
                variant: 'danger'
            };
        }
        if (lower.includes('restore') && lower.includes('customer')) {
            return {
                title: 'Restore Customer Account?',
                message: 'This account will be restored and activated. Previous records will remain unchanged.',
                confirmText: customBtn || 'Restore Account',
                variant: 'primary'
            };
        }
        if (lower.includes('deactivate customer')) {
            return {
                title: 'Deactivate Customer Account?',
                message: text + ' The customer will not be able to sign in while inactive, and all historical records will be preserved.',
                confirmText: customBtn || 'Deactivate Account',
                variant: 'primary'
            };
        }
        if (lower.includes('activate customer')) {
            return {
                title: 'Activate Customer Account?',
                message: text + ' The customer will regain online ordering access.',
                confirmText: customBtn || 'Activate Account',
                variant: 'primary'
            };
        }
        if (lower.includes('archive staff')) {
            return {
                title: 'Archive Staff Account?',
                message: 'This staff account will be archived and login access will be disabled. Historical records will be preserved.',
                confirmText: customBtn || 'Archive Staff',
                variant: 'danger'
            };
        }
        if (lower.includes('restore') && lower.includes('staff')) {
            return {
                title: 'Restore Staff Account?',
                message: 'This staff account will be restored to Active status.',
                confirmText: customBtn || 'Restore Staff',
                variant: 'primary'
            };
        }
        if (lower.includes('deactivate staff')) {
            return {
                title: 'Deactivate Staff Account?',
                message: text + ' Login access will be suspended until reactivated.',
                confirmText: customBtn || 'Deactivate Staff',
                variant: 'primary'
            };
        }
        if (lower.includes('activate staff')) {
            return {
                title: 'Activate Staff Account?',
                message: text,
                confirmText: customBtn || 'Activate Staff',
                variant: 'primary'
            };
        }
        if (lower.includes('approve')) {
            return {
                title: 'Approve Request?',
                message: text,
                confirmText: customBtn || 'Approve Request',
                variant: 'primary'
            };
        }
        if (lower.includes('reject')) {
            return {
                title: 'Reject Request?',
                message: text,
                confirmText: customBtn || 'Reject Request',
                variant: 'danger'
            };
        }
        if (lower.includes('archive')) {
            return {
                title: 'Confirm Archive Action?',
                message: text,
                confirmText: customBtn || 'Archive',
                variant: 'danger'
            };
        }
        if (lower.includes('delete')) {
            return {
                title: 'Confirm Delete Action?',
                message: text,
                confirmText: customBtn || 'Delete',
                variant: 'danger'
            };
        }
        return {
            title: 'Please Confirm Action',
            message: text || 'Are you sure you want to proceed with this action?',
            confirmText: customBtn || 'Confirm',
            variant: isDangerBtn ? 'danger' : 'primary'
        };
    }

    let activeDialogResolve = null;

    window.kkConfirm = function kkConfirm(options = {}) {
        const opts = typeof options === 'string' ? { message: options } : (options || {});
        const title = opts.title || 'Please Confirm';
        const message = opts.message || 'Are you sure you want to continue?';
        const confirmText = opts.confirmText || 'Confirm';
        const cancelText = opts.cancelText || 'Cancel';
        const variant = opts.variant === 'danger' ? 'danger' : 'primary';
        const details = Array.isArray(opts.details) ? opts.details : [];
        const previousFocus = document.activeElement;

        if (activeDialogResolve) {
            activeDialogResolve(false);
            activeDialogResolve = null;
        }
        const existing = document.getElementById('kkConfirmBackdrop');
        if (existing) existing.remove();

        return new Promise(resolve => {
            activeDialogResolve = resolve;

            const backdrop = document.createElement('div');
            backdrop.id = 'kkConfirmBackdrop';
            backdrop.className = 'kk-dialog-backdrop';
            backdrop.setAttribute('role', 'dialog');
            backdrop.setAttribute('aria-modal', 'true');

            const card = document.createElement('div');
            card.className = 'kk-dialog-card';
            if (opts.theme === 'inventory-archive') card.classList.add('kk-inventory-archive');
            if (opts.theme === 'approval-reject') card.classList.add('kk-approval-reject');

            const accent = document.createElement('div');
            accent.className = `kk-dialog-accent ${variant === 'danger' ? 'danger' : ''}`;

            const body = document.createElement('div');
            body.className = 'kk-dialog-body';

            const head = document.createElement('div');
            head.className = 'kk-dialog-head';

            const iconWrap = document.createElement('div');
            iconWrap.className = `kk-dialog-icon ${variant === 'danger' ? 'danger' : ''}`;
            const iconEl = document.createElement('i');
            iconEl.className = variant === 'danger' ? 'bi bi-exclamation-triangle-fill' : 'bi bi-shield-check';
            if (opts.theme === 'inventory-archive') iconEl.className = 'bi bi-archive';
            if (opts.theme === 'approval-reject') iconEl.className = 'bi bi-x-circle';
            iconWrap.appendChild(iconEl);

            const titleEl = document.createElement('h3');
            titleEl.className = 'kk-dialog-title';
            titleEl.textContent = String(title);

            head.appendChild(iconWrap);
            head.appendChild(titleEl);
            body.appendChild(head);

            if (details.length > 0) {
                const detailsBox = document.createElement('div');
                detailsBox.className = 'kk-dialog-details';
                details.forEach(row => {
                    if (!row) return;
                    const rowEl = document.createElement('div');
                    rowEl.className = 'kk-dialog-detail-row';
                    const labelEl = document.createElement('span');
                    labelEl.textContent = String(row.label ?? '');
                    const valEl = document.createElement('strong');
                    valEl.textContent = String(row.value ?? '');
                    rowEl.appendChild(labelEl);
                    rowEl.appendChild(valEl);
                    detailsBox.appendChild(rowEl);
                });
                body.appendChild(detailsBox);
            }

            const msgEl = document.createElement('p');
            msgEl.className = 'kk-dialog-message';
            msgEl.textContent = String(message);
            body.appendChild(msgEl);

            const actions = document.createElement('div');
            actions.className = 'kk-dialog-actions';

            const cancelBtn = document.createElement('button');
            cancelBtn.type = 'button';
            cancelBtn.className = 'btn btn-secondary';
            cancelBtn.textContent = String(cancelText);

            const confirmBtn = document.createElement('button');
            confirmBtn.type = 'button';
            confirmBtn.className = `btn ${variant === 'danger' ? 'btn-danger' : 'btn-primary'}`;
            confirmBtn.textContent = String(confirmText);

            actions.appendChild(cancelBtn);
            actions.appendChild(confirmBtn);

            card.appendChild(accent);
            card.appendChild(body);
            card.appendChild(actions);
            backdrop.appendChild(card);
            document.body.appendChild(backdrop);

            let finished = false;
            const finish = (result) => {
                if (finished) return;
                finished = true;
                activeDialogResolve = null;
                document.removeEventListener('keydown', onKeyDown, true);
                backdrop.classList.remove('show');
                setTimeout(() => {
                    if (backdrop.parentNode) backdrop.parentNode.removeChild(backdrop);
                    if (previousFocus && typeof previousFocus.focus === 'function') {
                        try { previousFocus.focus(); } catch (_) {}
                    }
                }, 180);
                resolve(result);
            };

            const onKeyDown = (e) => {
                if (e.key === 'Escape') {
                    e.preventDefault();
                    e.stopPropagation();
                    finish(false);
                } else if (e.key === 'Tab') {
                    const focusables = [cancelBtn, confirmBtn];
                    const idx = focusables.indexOf(document.activeElement);
                    if (e.shiftKey) {
                        if (idx <= 0) {
                            e.preventDefault();
                            confirmBtn.focus();
                        }
                    } else {
                        if (idx === focusables.length - 1) {
                            e.preventDefault();
                            cancelBtn.focus();
                        }
                    }
                }
            };

            document.addEventListener('keydown', onKeyDown, true);
            cancelBtn.addEventListener('click', () => finish(false));
            confirmBtn.addEventListener('click', () => finish(true));
            backdrop.addEventListener('click', (e) => {
                if (e.target === backdrop) finish(false);
            });

            requestAnimationFrame(() => {
                backdrop.classList.add('show');
                confirmBtn.focus();
            });
        });
    };

    // Delegate [data-confirm] clicks across the entire application
    document.addEventListener('click', async (event) => {
        const btn = event.target.closest('[data-confirm]');
        if (!btn) return;

        if (btn.dataset.kkConfirmed === '1') {
            delete btn.dataset.kkConfirmed;
            return;
        }

        event.preventDefault();
        event.stopPropagation();

        if (btn.disabled || btn.dataset.kkDialogOpen === '1') return;
        btn.dataset.kkDialogOpen = '1';

        const config = inferConfirmConfig(btn.dataset.confirm, btn);
        const confirmed = await window.kkConfirm(config);
        delete btn.dataset.kkDialogOpen;

        if (!confirmed) return;

        btn.dataset.kkConfirmed = '1';
        if (btn.form) {
            if (typeof btn.form.requestSubmit === 'function') {
                btn.form.requestSubmit(btn);
            } else {
                if (btn.name) {
                    const hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = btn.name;
                    hidden.value = btn.value;
                    btn.form.appendChild(hidden);
                }
                btn.form.submit();
            }
            btn.disabled = true;
        } else {
            btn.click();
        }
    }, true);
})();

// Prevent browser bfcache (Back-Forward Cache) state bleed after logout
window.addEventListener('pageshow', function (event) {
    if (event.persisted) {
        window.location.reload();
    }
});

// Real-time module bell notifications + sidebar badges across all staff modules
(function initRealtimeStaffNotifications() {
    const sidebar = document.getElementById('sidebar');
    const bellWrap = document.getElementById('kkHeaderNotifWrap');
    const bellBtn = document.getElementById('kkHeaderNotifBtn');
    const bellMenu = document.getElementById('kkHeaderNotifMenu');
    const bellCount = document.getElementById('kkHeaderNotifCount');
    const bellTitle = document.getElementById('kkHeaderNotifTitle');
    const bellPill = document.getElementById('kkHeaderNotifPill');
    const bellList = document.getElementById('kkHeaderNotifList');

    const baseUrl = bellWrap?.dataset?.countsUrl || sidebar?.dataset?.sidebarCountsUrl || '';
    const currentModule = bellWrap?.dataset?.module || 'dashboard';
    if (!baseUrl) return;

    function renderBellNotifications(notifData) {
        if (!notifData || !bellCount || !bellList) return;
        const count = Math.max(0, Number(notifData.count) || 0);
        bellCount.textContent = count > 99 ? '99+' : String(count);
        bellCount.hidden = count <= 0;
        if (bellTitle && notifData.label) bellTitle.textContent = String(notifData.label);
        if (bellPill) bellPill.textContent = `${count} pending`;

        bellList.replaceChildren();
        const items = Array.isArray(notifData.items) ? notifData.items : [];
        if (!items.length) {
            const empty = document.createElement('div');
            empty.className = 'kk-notif-empty';
            empty.textContent = 'You’re all caught up. No pending notifications here.';
            bellList.appendChild(empty);
            return;
        }
        items.forEach(item => {
            const link = document.createElement('a');
            link.className = 'kk-notif-item';
            link.setAttribute('role', 'menuitem');
            link.href = typeof item.url === 'string' && item.url ? item.url : '#';

            const top = document.createElement('div');
            top.className = 'kk-notif-item-top';
            const title = document.createElement('strong');
            title.textContent = String(item.title || '');
            const tag = document.createElement('span');
            tag.className = 'kk-notif-tag';
            tag.textContent = String(item.tag || '');
            top.append(title, tag);

            const detail = document.createElement('div');
            detail.className = 'kk-notif-item-detail';
            detail.textContent = String(item.detail || '');

            link.append(top, detail);
            link.addEventListener('click', () => {
                if (bellMenu && bellBtn) {
                    bellMenu.hidden = true;
                    bellBtn.setAttribute('aria-expanded', 'false');
                }
            });
            bellList.appendChild(link);
        });
    }

    if (bellBtn && bellMenu) {
        const closeBellMenu = () => {
            bellMenu.hidden = true;
            bellBtn.setAttribute('aria-expanded', 'false');
        };
        bellBtn.addEventListener('click', event => {
            event.stopPropagation();
            const opening = bellMenu.hidden;
            bellMenu.hidden = !opening;
            bellBtn.setAttribute('aria-expanded', opening ? 'true' : 'false');
        });
        document.addEventListener('click', event => {
            if (!bellMenu.hidden && bellWrap && !bellWrap.contains(event.target)) {
                closeBellMenu();
            }
        });
        document.addEventListener('keydown', event => {
            if (event.key === 'Escape' && !bellMenu.hidden) {
                closeBellMenu();
                bellBtn.focus();
            }
        });
    }

    let inflight = false;
    async function refreshNotifications() {
        if (inflight || document.hidden) return;
        inflight = true;
        try {
            const sep = baseUrl.includes('?') ? '&' : '?';
            const res = await fetch(`${baseUrl}${sep}module=${encodeURIComponent(currentModule)}`, {
                credentials: 'same-origin',
                cache: 'no-store'
            });
            if (!res.ok) return;
            const data = await res.json();
            if (!data || !data.ok) return;

            if (data.counts && sidebar) {
                Object.entries(data.counts).forEach(([key, rawVal]) => {
                    const count = Math.max(0, Number(rawVal) || 0);
                    sidebar.querySelectorAll(`[data-sidebar-badge-count="${key}"]`).forEach(badge => {
                        badge.textContent = String(count);
                        badge.hidden = count <= 0;
                    });
                });
            }
            if (data.notifications) {
                renderBellNotifications(data.notifications);
            }
        } catch (_) {
            // Silently ignore transient polling failures
        } finally {
            inflight = false;
        }
    }

    window.kkRefreshSidebarBadges = refreshNotifications;
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) refreshNotifications();
    });
    setInterval(refreshNotifications, 4000);
})();

// Preserve exact viewport scroll position when clicking any .kk-pagination button
(function initInPlaceStaffPagination() {
    let activeController = null;

    function getCleanUrl(rawHref) {
        const u = new URL(rawHref, window.location.href);
        u.hash = '';
        return u.toString();
    }

    async function swapPaginatedSection(url, pagIndex, targetContainer, pushHistory) {
        if (!targetContainer) {
            window.location.href = url;
            return;
        }
        if (activeController) activeController.abort();
        activeController = new AbortController();
        const prevX = window.scrollX;
        const prevY = window.scrollY;
        targetContainer.style.opacity = '0.65';
        try {
            const res = await fetch(url, { signal: activeController.signal, credentials: 'same-origin' });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const html = await res.text();
            const doc = new DOMParser().parseFromString(html, 'text/html');
            const nextPag = doc.querySelectorAll('.kk-pagination')[pagIndex];
            const nextContainer = nextPag ? nextPag.closest('section.card, .card, section, main') : null;
            if (!nextContainer) throw new Error('Paginated container not found');
            targetContainer.innerHTML = nextContainer.innerHTML;
            if (pushHistory) {
                history.pushState({ kkPagination: true, pagIndex }, '', url);
            }
            window.scrollTo(prevX, prevY);
        } catch (err) {
            if (err && err.name === 'AbortError') return;
            window.location.href = url;
        } finally {
            targetContainer.style.opacity = '';
        }
    }

    document.addEventListener('click', function (e) {
        const link = e.target.closest('.kk-pagination a[href]');
        if (!link || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        if (link.getAttribute('aria-disabled') === 'true') {
            e.preventDefault();
            return;
        }
        const pagEl = link.closest('.kk-pagination');
        const container = pagEl ? pagEl.closest('section.card, .card, section, main') : null;
        if (!pagEl || !container) return;

        const allPaginations = Array.from(document.querySelectorAll('.kk-pagination'));
        const pagIndex = allPaginations.indexOf(pagEl);
        if (pagIndex < 0) return;

        e.preventDefault();
        swapPaginatedSection(getCleanUrl(link.href), pagIndex, container, true);
    });

    window.addEventListener('popstate', function (e) {
        if (!e.state || !e.state.kkPagination) return;
        const pagIndex = Number(e.state.pagIndex) || 0;
        const pagEl = document.querySelectorAll('.kk-pagination')[pagIndex];
        const container = pagEl ? pagEl.closest('section.card, .card, section, main') : null;
        if (container) {
            swapPaginatedSection(getCleanUrl(window.location.href), pagIndex, container, false);
        } else {
            window.location.reload();
        }
    });
})();

// Shared staff queue prioritization helper (FIFO, Newest, Scheduled pickup time, Fewest items)
window.kkSortOrders = function kkSortOrders(orders, sortKey, opts = {}) {
    const list = Array.isArray(orders) ? orders.slice() : [];
    const timeField = opts.timeField || 'created_at';
    const toMs = (val) => {
        if (!val) return 0;
        const t = Date.parse(String(val).replace(' ', 'T'));
        return Number.isFinite(t) ? t : 0;
    };
    const pickupMs = (o) => {
        const note = String(o?.notes || '');
        const match = note.match(/\[Pickup:\s*([^\]]+?)(?:\s+Philippine time)?\]/i);
        if (match && match[1]) {
            const t = Date.parse(match[1].trim());
            if (Number.isFinite(t)) return t;
        }
        return Number.POSITIVE_INFINITY;
    };
    const itemCount = (o) => {
        if (Array.isArray(o?.items)) {
            return o.items.reduce((sum, it) => sum + Math.max(1, Number(it?.quantity || 1)), 0);
        }
        return Number(o?.item_count || 0);
    };
    const orderTime = (o) => toMs(o?.[timeField]) || toMs(o?.created_at) || Number(o?.id || 0);

    list.sort((a, b) => {
        if (sortKey === 'newest') {
            return (orderTime(b) - orderTime(a)) || (Number(b.id || 0) - Number(a.id || 0));
        }
        if (sortKey === 'pickup') {
            const pa = pickupMs(a);
            const pb = pickupMs(b);
            if (pa !== pb) return pa - pb;
            return (orderTime(a) - orderTime(b)) || (Number(a.id || 0) - Number(b.id || 0));
        }
        if (sortKey === 'fewest') {
            const ca = itemCount(a);
            const cb = itemCount(b);
            if (ca !== cb) return ca - cb;
            return (orderTime(a) - orderTime(b)) || (Number(a.id || 0) - Number(b.id || 0));
        }
        // Default: fifo (First in, first out)
        return (orderTime(a) - orderTime(b)) || (Number(a.id || 0) - Number(b.id || 0));
    });
    return list;
};
