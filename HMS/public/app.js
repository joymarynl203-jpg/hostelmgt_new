(function () {
    'use strict';

    function targetsPaymentStart(url) {
        if (!url || typeof url !== 'string') {
            return false;
        }
        return url.indexOf('payment_start.php') !== -1;
    }

    document.addEventListener(
        'click',
        function (e) {
            var el = e.target && e.target.closest ? e.target.closest('a[data-hms-confirm]') : null;
            if (!el || el.tagName !== 'A') {
                return;
            }
            var msg = el.getAttribute('data-hms-confirm');
            if (!msg) {
                return;
            }
            if (targetsPaymentStart(el.getAttribute('href'))) {
                return;
            }
            if (!window.confirm(msg)) {
                e.preventDefault();
                e.stopPropagation();
            }
        },
        true
    );

    document.addEventListener(
        'submit',
        function (e) {
            var form = e.target;
            if (!form || form.tagName !== 'FORM') {
                return;
            }
            var msg = form.getAttribute('data-hms-confirm');
            if (!msg) {
                return;
            }
            if (targetsPaymentStart(form.getAttribute('action'))) {
                return;
            }
            if (!window.confirm(msg)) {
                e.preventDefault();
            }
        },
        true
    );

    function hmsLightboxCollectGroup(groupId) {
        var nodes = document.querySelectorAll('[data-hms-gallery-group="' + groupId + '"]');
        return Array.prototype.slice.call(nodes).sort(function (a, b) {
            return parseInt(a.getAttribute('data-hms-gallery-index') || '0', 10) - parseInt(b.getAttribute('data-hms-gallery-index') || '0', 10);
        });
    }

    var hmsLightboxState = { items: [], index: 0, modal: null };

    function hmsLightboxShowAt(index) {
        if (!hmsLightboxState.items.length) {
            return;
        }
        var total = hmsLightboxState.items.length;
        hmsLightboxState.index = ((index % total) + total) % total;
        var item = hmsLightboxState.items[hmsLightboxState.index];
        var img = document.getElementById('hmsPhotoLightboxImg');
        var caption = document.getElementById('hmsPhotoLightboxCaption');
        var prevBtn = document.querySelector('.hms-lightbox-prev');
        var nextBtn = document.querySelector('.hms-lightbox-next');
        if (!img || !item) {
            return;
        }
        img.src = item.getAttribute('data-hms-gallery-src') || '';
        img.alt = item.getAttribute('data-hms-gallery-alt') || '';
        if (caption) {
            caption.textContent = total > 1 ? (hmsLightboxState.index + 1) + ' / ' + total + ' — ' + (img.alt || '') : (img.alt || '');
        }
        var showNav = total > 1;
        if (prevBtn) {
            prevBtn.classList.toggle('d-none', !showNav);
        }
        if (nextBtn) {
            nextBtn.classList.toggle('d-none', !showNav);
        }
    }

    function hmsLightboxOpenFromButton(button) {
        var modalEl = document.getElementById('hmsPhotoLightbox');
        if (!modalEl || !button) {
            return;
        }
        var groupId = button.getAttribute('data-hms-gallery-group') || '';
        hmsLightboxState.items = hmsLightboxCollectGroup(groupId);
        var startIndex = parseInt(button.getAttribute('data-hms-gallery-index') || '0', 10);
        if (!hmsLightboxState.items.length) {
            return;
        }
        if (!hmsLightboxState.modal && window.bootstrap && bootstrap.Modal) {
            hmsLightboxState.modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        }
        hmsLightboxShowAt(startIndex);
        if (hmsLightboxState.modal) {
            hmsLightboxState.modal.show();
        }
    }

    document.addEventListener('click', function (e) {
        var openBtn = e.target && e.target.closest ? e.target.closest('.hms-gallery-open') : null;
        if (openBtn) {
            e.preventDefault();
            hmsLightboxOpenFromButton(openBtn);
            return;
        }
        if (e.target && e.target.closest && e.target.closest('.hms-lightbox-prev')) {
            e.preventDefault();
            hmsLightboxShowAt(hmsLightboxState.index - 1);
            return;
        }
        if (e.target && e.target.closest && e.target.closest('.hms-lightbox-next')) {
            e.preventDefault();
            hmsLightboxShowAt(hmsLightboxState.index + 1);
        }
    });

    document.addEventListener('keydown', function (e) {
        var modalEl = document.getElementById('hmsPhotoLightbox');
        if (!modalEl || !modalEl.classList.contains('show')) {
            return;
        }
        if (e.key === 'ArrowLeft') {
            e.preventDefault();
            hmsLightboxShowAt(hmsLightboxState.index - 1);
        } else if (e.key === 'ArrowRight') {
            e.preventDefault();
            hmsLightboxShowAt(hmsLightboxState.index + 1);
        }
    });

    document.addEventListener('click', function (e) {
        var btn = e.target && e.target.closest ? e.target.closest('.hms-password-toggle-btn') : null;
        if (!btn) {
            return;
        }
        e.preventDefault();
        var group = btn.closest('.hms-password-toggle');
        if (!group) {
            return;
        }
        var input = group.querySelector('input');
        if (!input) {
            return;
        }
        var show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        btn.setAttribute('aria-pressed', show ? 'true' : 'false');
        btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        var openIcon = btn.querySelector('.hms-eye-open');
        var closedIcon = btn.querySelector('.hms-eye-closed');
        if (openIcon) {
            openIcon.classList.toggle('d-none', show);
        }
        if (closedIcon) {
            closedIcon.classList.toggle('d-none', !show);
        }
    });

    function hmsInitNotifications() {
        var root = document.querySelector('[data-hms-notif-page]');
        if (!root) {
            return;
        }

        var filtersWrap = document.querySelector('.hms-notif-filters');
        var filterBtns = filtersWrap
            ? Array.prototype.slice.call(filtersWrap.querySelectorAll('[data-hms-notif-filter]'))
            : [];
        var sections = Array.prototype.slice.call(root.querySelectorAll('[data-hms-notif-section]'));
        var rows = Array.prototype.slice.call(root.querySelectorAll('.hms-notif-row'));
        var subheads = Array.prototype.slice.call(root.querySelectorAll('.hms-notif-subhead'));
        var emptyEl = document.getElementById('hmsNotifFilterEmpty');

        function listHasVisibleRows(listEl) {
            if (!listEl) {
                return false;
            }
            var listRows = listEl.querySelectorAll('.hms-notif-row');
            for (var i = 0; i < listRows.length; i++) {
                if (!listRows[i].classList.contains('d-none')) {
                    return true;
                }
            }
            return false;
        }

        function refreshSubheads() {
            subheads.forEach(function (head) {
                var list = head.nextElementSibling;
                while (list && !list.classList.contains('hms-notif-list')) {
                    list = list.nextElementSibling;
                }
                head.classList.toggle('d-none', !listHasVisibleRows(list));
            });
        }

        function sectionVisible(section) {
            var sectionRows = section.querySelectorAll('.hms-notif-row');
            for (var i = 0; i < sectionRows.length; i++) {
                if (!sectionRows[i].classList.contains('d-none')) {
                    return true;
                }
            }
            return false;
        }

        function applyFilter(mode, scrollTarget) {
            filterBtns.forEach(function (btn) {
                var active = btn.getAttribute('data-hms-notif-filter') === mode;
                btn.classList.toggle('hms-notif-filter--active', active);
                btn.setAttribute('aria-pressed', active ? 'true' : 'false');
            });

            rows.forEach(function (row) {
                var isRead = row.getAttribute('data-hms-notif-read') === '1';
                var section = row.closest('[data-hms-notif-section]');
                var sectionKey = section ? section.getAttribute('data-hms-notif-section') : '';
                var show = true;

                if (mode === 'unread') {
                    show = !isRead;
                } else if (mode === 'general') {
                    show = sectionKey === 'general';
                } else if (mode === 'maintenance') {
                    show = sectionKey === 'maintenance';
                }

                row.classList.toggle('d-none', !show);
            });

            sections.forEach(function (section) {
                var key = section.getAttribute('data-hms-notif-section');
                var showSection = true;
                if (mode === 'general') {
                    showSection = key === 'general';
                } else if (mode === 'maintenance') {
                    showSection = key === 'maintenance';
                } else if (mode === 'unread') {
                    showSection = sectionVisible(section);
                }
                section.classList.toggle('d-none', !showSection);
            });

            refreshSubheads();

            var anyVisible = false;
            sections.forEach(function (section) {
                if (!section.classList.contains('d-none')) {
                    anyVisible = true;
                }
            });
            if (emptyEl) {
                emptyEl.classList.toggle('d-none', anyVisible);
            }

            if (scrollTarget) {
                var target =
                    scrollTarget === 'unread'
                        ? root.querySelector('.hms-notif-row[data-hms-notif-read="0"]:not(.d-none)')
                        : document.getElementById('notif-' + scrollTarget);
                if (target && typeof target.scrollIntoView === 'function') {
                    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            }

            if (history.replaceState) {
                history.replaceState(null, '', '#' + mode);
            } else {
                location.hash = mode;
            }
        }

        filterBtns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                applyFilter(btn.getAttribute('data-hms-notif-filter') || 'unread', btn.getAttribute('data-hms-notif-filter'));
            });
        });

        var unreadTotal = parseInt(root.getAttribute('data-hms-unread-total') || '0', 10);
        var hash = (location.hash || '').replace(/^#/, '');
        var initial = 'general';
        if (hash === 'general' || hash === 'maintenance' || hash === 'unread') {
            initial = hash;
        } else if (unreadTotal > 0) {
            initial = 'unread';
        }
        if (initial === 'unread' && unreadTotal === 0) {
            initial = 'general';
        }
        applyFilter(initial, initial);

        var modalEl = document.getElementById('notifDetailModal');
        if (modalEl) {
            modalEl.addEventListener('show.bs.modal', function (event) {
                var trigger = event.relatedTarget;
                if (!trigger) {
                    return;
                }
                var raw = trigger.getAttribute('data-hms-notif');
                var d = {};
                if (raw) {
                    try {
                        d = JSON.parse(raw);
                    } catch (err) {
                        d = {};
                    }
                }
                var badgeEl = document.getElementById('notifModalBadge');
                var whenEl = document.getElementById('notifModalWhen');
                var msgEl = document.getElementById('notifModalMsg');
                var formEl = document.getElementById('notifModalMarkReadForm');
                var idInput = document.getElementById('notifModalMarkReadId');

                if (badgeEl) {
                    badgeEl.className = 'badge ' + (d.badgeClass || 'bg-secondary');
                    badgeEl.textContent = d.typeLabel || 'Update';
                }
                if (whenEl) {
                    whenEl.textContent = d.when || '';
                }
                if (msgEl) {
                    msgEl.textContent = d.msg || '';
                }
                if (formEl && idInput) {
                    idInput.value = d.id != null ? String(d.id) : '';
                    formEl.style.display = d.unread ? 'block' : 'none';
                }
            });
        }

        rows.forEach(function (row) {
            row.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    row.click();
                }
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', hmsInitNotifications);
    } else {
        hmsInitNotifications();
    }
})();
