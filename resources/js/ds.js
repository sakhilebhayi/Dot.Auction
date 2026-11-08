/*
 * Dot.Auction design-system behaviour. Plain JavaScript, no dependencies, progressive enhancement only:
 * every page is readable and every form submits with this file missing. Behaviour is attached by data attributes
 * (data-theme-toggle, data-ds-countdown, data-ds-dialog, data-ds-dropdown, data-ds-tabset, data-ds-rail, data-ds-rung, data-ds-toasts),
 * through one delegated listener each, so markup that Livewire swaps in keeps working without re-binding.
 */

const $ = (selector, root = document) => root.querySelector(selector);
const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));
const reduceMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/* ---------- Theme ---------- */
const THEME_KEY = 'dot-theme';
export const theme = {
    stored() {
        try { return localStorage.getItem(THEME_KEY); } catch (e) { return null; }
    },
    current() {
        const stored = this.stored();
        if (stored === 'dark' || stored === 'light') { return stored; }
        return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    },
    apply(mode) {
        const root = document.documentElement;
        root.dataset.theme = mode;
        root.classList.toggle('dark', mode === 'dark');
        document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
            button.setAttribute('aria-label', mode === 'dark' ? 'Switch to light theme' : 'Switch to dark theme');
            button.setAttribute('title', mode === 'dark' ? 'Switch to light theme' : 'Switch to dark theme');
        });
    },
    set(mode) {
        try { localStorage.setItem(THEME_KEY, mode); } catch (e) { /* storage blocked: the choice lasts for this page only */ }
        this.apply(mode);
    },
};
window.dsTheme = theme;

document.addEventListener('click', (event) => {
    const toggle = event.target.closest('[data-theme-toggle]');
    if (toggle) {
        theme.set(document.documentElement.classList.contains('dark') ? 'light' : 'dark');
    }
});
theme.apply(theme.current());
window.matchMedia('(prefers-color-scheme: dark)').addEventListener?.('change', () => {
    if (!theme.stored()) { theme.apply(theme.current()); }
});

/* ---------- Countdown and rail: server time first, this file only keeps it ticking ---------- */
const unitsFor = (seconds) => {
    const s = Math.max(0, Math.floor(seconds));
    const d = Math.floor(s / 86400);
    const h = Math.floor((s % 86400) / 3600);
    const m = Math.floor((s % 3600) / 60);
    const sec = s % 60;
    const two = (n) => String(n).padStart(2, '0');
    if (d > 0) { return [[String(d), 'd'], [String(h), 'h']]; }
    if (h > 0) { return [[String(h), 'h'], [two(m), 'm']]; }
    if (m > 0) { return [[String(m), 'm'], [two(sec), 's']]; }
    return [[String(sec), 's']];
};
const unitsKey = (units) => units.map((u) => u.join('')).join(' ');

let liveRegion = null;
const announce = (text) => {
    if (!liveRegion) {
        liveRegion = document.createElement('p');
        liveRegion.className = 'sr-only';
        liveRegion.setAttribute('role', 'status');
        liveRegion.setAttribute('aria-live', 'polite');
        document.body.appendChild(liveRegion);
    }
    liveRegion.textContent = text;
};

const tickCountdown = (el, now) => {
    const target = Number(el.dataset.target);
    const offset = Number(el.dataset.offset);
    const left = (target - (now + offset)) / 1000;
    const value = el.querySelector('[data-cd-value]');
    const label = el.querySelector('[data-cd-label]');
    if (!value) { return; }

    if (left <= 0) {
        if (!el.classList.contains('is-over')) {
            el.classList.add('is-over');
            el.classList.remove('is-urgent');
            const opening = el.dataset.mode === 'opening';
            value.innerHTML = '<span class="ds-cd-word">' + (opening ? 'Open now' : 'Closed') + '</span>';
            if (label) { label.textContent = ''; }
            el.dispatchEvent(new CustomEvent('ds:countdown-end', { bubbles: true, detail: { mode: el.dataset.mode } }));
        }
        return;
    }

    // The clock itself is never announced. The lot page's own clock says so politely twice, at five minutes and at one, so a screen reader
    // user hears that the lot is about to close without hearing every second.
    if (el.closest('.lot-clock') && el.dataset.mode !== 'opening') {
        const before = el.dataset.prev === undefined ? null : Number(el.dataset.prev);
        [[300, '5 minutes'], [60, '1 minute']].forEach(([at, words]) => {
            if (before !== null && before > at && left <= at) { announce('This lot closes in ' + words + '.'); }
        });
        el.dataset.prev = String(left);
    }

    const units = unitsFor(left);
    const key = unitsKey(units);
    if (el.dataset.shown !== key) {
        el.dataset.shown = key;
        value.innerHTML = units.map(([n, u]) => '<span class="ds-cd-n">' + n + '</span><span class="ds-cd-u">' + u + '</span>').join(' ');
    }
    if (el.dataset.mode !== 'opening') {
        el.classList.toggle('is-urgent', left <= Number(el.dataset.urgent || 0));
    }
};

const tickRail = (el, now) => {
    const start = Number(el.dataset.start);
    const end = Number(el.dataset.end);
    const offset = Number(el.dataset.offset);
    const total = Math.max(1, end - start);
    const elapsed = Math.min(total, Math.max(0, now + offset - start));
    const fill = el.querySelector('[data-rail-fill]');
    if (fill) { fill.style.setProperty('--p', (elapsed / total).toFixed(4)); }
    el.classList.toggle('is-urgent', total - elapsed <= Number(el.dataset.urgent || 0) * 1000 && elapsed < total);
};

const startClocks = () => {
    const clock = (el) => { el.dataset.offset = String(Number(el.dataset.server) - Date.now()); };
    const run = () => {
        const now = Date.now();
        $$('[data-ds-countdown]').forEach((el) => { if (!el.dataset.offset) { clock(el); } tickCountdown(el, now); });
        $$('[data-ds-rail]').forEach((el) => { if (!el.dataset.offset) { clock(el); } tickRail(el, now); });
    };
    run();
    window.setInterval(() => { if (!document.hidden) { run(); } }, 1000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) { run(); } });
    // Livewire replaces markup: new clocks get their offset on the next run, which is at most a second away.
};

/* ---------- Dialog ---------- */
const dialogs = {
    open(id) {
        const dialog = document.getElementById(id);
        if (dialog && typeof dialog.showModal === 'function' && !dialog.open) {
            dialog.showModal();
            const auto = dialog.querySelector('[autofocus]');
            if (auto) { auto.focus(); }
        }
    },
    close(id) {
        const dialog = document.getElementById(id);
        if (dialog && dialog.open) { dialog.close(); }
    },
};
window.dsDialog = dialogs;

document.addEventListener('click', (event) => {
    const opener = event.target.closest('[data-dialog-open]');
    if (opener) {
        event.preventDefault();
        dialogs.open(opener.getAttribute('data-dialog-open'));
        return;
    }
    const closer = event.target.closest('[data-dialog-close]');
    if (closer) {
        const dialog = closer.closest('dialog');
        if (dialog) { dialog.close(); }
        return;
    }
    // A click on the backdrop lands on the <dialog> element itself, outside its panel.
    if (event.target instanceof HTMLDialogElement && event.target.hasAttribute('data-ds-dialog')) {
        event.target.close();
    }
});
window.addEventListener('ds-dialog-open', (event) => dialogs.open(event.detail?.id));
window.addEventListener('ds-dialog-close', (event) => dialogs.close(event.detail?.id));

/* ---------- Dropdown ---------- */
const closeMenus = (except = null, returnFocus = false) => {
    $$('[data-ds-dropdown]').forEach((root) => {
        if (root === except) { return; }
        const trigger = $('[data-dropdown-trigger]', root);
        const menu = trigger && document.getElementById(trigger.getAttribute('aria-controls'));
        if (menu && !menu.hidden) {
            menu.hidden = true;
            trigger.setAttribute('aria-expanded', 'false');
            if (returnFocus) { trigger.focus(); }
        }
    });
};
const openMenu = (root, focusFirst = false) => {
    const trigger = $('[data-dropdown-trigger]', root);
    const menu = document.getElementById(trigger.getAttribute('aria-controls'));
    closeMenus(root);
    menu.hidden = false;
    trigger.setAttribute('aria-expanded', 'true');
    if (focusFirst) { $('[role="menuitem"]', menu)?.focus(); }
};
document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-dropdown-trigger]');
    if (trigger) {
        const root = trigger.closest('[data-ds-dropdown]');
        trigger.getAttribute('aria-expanded') === 'true' ? closeMenus(null, false) : openMenu(root);
        return;
    }
    if (!event.target.closest('[data-ds-dropdown]')) { closeMenus(); }
});
document.addEventListener('keydown', (event) => {
    const root = event.target.closest?.('[data-ds-dropdown]');
    if (event.key === 'Escape') { closeMenus(null, true); return; }
    if (!root) { return; }
    const trigger = $('[data-dropdown-trigger]', root);
    const menu = document.getElementById(trigger.getAttribute('aria-controls'));
    const items = $$('[role="menuitem"]', menu);
    if (event.target === trigger && (event.key === 'ArrowDown' || event.key === 'ArrowUp')) {
        event.preventDefault();
        openMenu(root);
        (event.key === 'ArrowDown' ? items[0] : items[items.length - 1])?.focus();
        return;
    }
    if (menu.hidden || !items.length) { return; }
    const index = items.indexOf(document.activeElement);
    const go = (i) => { event.preventDefault(); items[(i + items.length) % items.length].focus(); };
    if (event.key === 'ArrowDown') { go(index + 1); }
    if (event.key === 'ArrowUp') { go(index - 1); }
    if (event.key === 'Home') { go(0); }
    if (event.key === 'End') { go(items.length - 1); }
    if (event.key === 'Tab') { closeMenus(); }
});

/* ---------- Tabset ---------- */
const selectTab = (set, key, focus = false) => {
    $$('[role="tab"]', set).forEach((tab) => {
        const on = tab.dataset.tab === key;
        tab.setAttribute('aria-selected', on ? 'true' : 'false');
        tab.setAttribute('tabindex', on ? '0' : '-1');
        tab.classList.toggle('is-current', on);
        if (on && focus) { tab.focus(); }
    });
    $$('[role="tabpanel"]', set).forEach((panel) => { panel.hidden = panel.dataset.panel !== key; });
};
const initTabsets = () => {
    $$('[data-ds-tabset]').forEach((set) => {
        if (set.dataset.ready) { return; }
        set.dataset.ready = '1';
        set.classList.add('is-ready');
        const sid = $('[data-sid]', set)?.dataset.sid;
        $$('[role="tabpanel"]', set).forEach((panel) => {
            panel.id = sid + '-panel-' + panel.dataset.panel;
            panel.setAttribute('aria-labelledby', sid + '-tab-' + panel.dataset.panel);
        });
        selectTab(set, $('[role="tab"][aria-selected="true"]', set)?.dataset.tab);
    });
};
document.addEventListener('click', (event) => {
    const tab = event.target.closest('[role="tab"][data-tab]');
    if (tab) { selectTab(tab.closest('[data-ds-tabset]'), tab.dataset.tab); }
});
document.addEventListener('keydown', (event) => {
    const tab = event.target.closest?.('[role="tab"][data-tab]');
    if (!tab) { return; }
    const set = tab.closest('[data-ds-tabset]');
    const tabs = $$('[role="tab"]', set);
    const i = tabs.indexOf(tab);
    const move = { ArrowRight: i + 1, ArrowLeft: i - 1, Home: 0, End: tabs.length - 1 }[event.key];
    if (move !== undefined) {
        event.preventDefault();
        selectTab(set, tabs[(move + tabs.length) % tabs.length].dataset.tab, true);
    }
});

/* ---------- Toasts ---------- */
export const toast = ({ title = '', body = '', tone = 'info', sticky = false } = {}) => {
    const region = $('[data-ds-toasts]');
    const template = $('#ds-toast-template');
    if (!region || !template) { return; }
    const node = template.content.firstElementChild.cloneNode(true);
    node.classList.add('ds-toast--' + tone);
    node.setAttribute('role', tone === 'danger' ? 'alert' : 'status');
    $('.ds-toast__title', node).textContent = title;
    const bodyEl = $('.ds-toast__body', node);
    if (body) { bodyEl.textContent = body; } else { bodyEl.remove(); }
    region.appendChild(node);
    let timer = null;
    const remove = () => { window.clearTimeout(timer); node.classList.add('is-leaving'); window.setTimeout(() => node.remove(), reduceMotion() ? 0 : 160); };
    const arm = () => { if (!sticky && tone !== 'danger') { timer = window.setTimeout(remove, 6000); } };
    arm();
    node.addEventListener('mouseenter', () => window.clearTimeout(timer));
    node.addEventListener('mouseleave', arm);
    node.addEventListener('focusin', () => window.clearTimeout(timer));
    node.addEventListener('focusout', arm);
    $('[data-toast-close]', node).addEventListener('click', remove);
};
window.dsToast = toast;
window.addEventListener('ds-toast', (event) => toast(event.detail || {}));

/* ---------- Small behaviours ---------- */
document.addEventListener('click', (event) => {
    const dismiss = event.target.closest('[data-ds-dismiss]');
    if (dismiss) { dismiss.closest('[data-ds-alert]')?.remove(); }

    // The bid ladder: pressing a rung puts its amount in the bid field.
    const rung = event.target.closest('[data-ds-rung]');
    if (rung) {
        const field = $('[data-ds-bid-input]');
        if (field) {
            field.value = rung.dataset.dsRung;
            field.dispatchEvent(new Event('input', { bubbles: true }));
            field.focus();
        }
    }

    // A navigation item whose page is not built yet (auction_route returned '#') does nothing rather than jumping to the top.
    const pending = event.target.closest('[data-pending-route]');
    if (pending) { event.preventDefault(); }
});

// One submission per form: the submit button is held while the request runs (not for Livewire forms, which have wire:loading).
document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || form.hasAttribute('wire:submit') || event.defaultPrevented) { return; }
    const submitter = event.submitter || $('[data-ds-lock]', form);
    if (submitter && submitter.hasAttribute('data-ds-lock')) {
        window.setTimeout(() => { submitter.setAttribute('aria-busy', 'true'); submitter.classList.add('is-loading'); submitter.disabled = true; }, 0);
    }
});
window.addEventListener('pageshow', () => {
    $$('[data-ds-lock][aria-busy="true"]').forEach((button) => { button.removeAttribute('aria-busy'); button.classList.remove('is-loading'); button.disabled = false; });
});

/* ---------- Declarative replacements for inline handlers (a strict CSP forbids onclick, onchange and onsubmit attributes) ---------- */
// data-confirm="Question?" on a form or a button asks with the browser's own confirm() before it submits or acts. Interim for pages not yet
// moved to ds.confirm (a dialog); new work uses ds.confirm.
document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) { return; }
    if (form.hasAttribute('data-ds-noop')) { event.preventDefault(); return; }
    const message = form.dataset.confirm;
    if (message && !window.confirm(message)) { event.preventDefault(); }
}, true);
document.addEventListener('click', (event) => {
    const control = event.target.closest('button[data-confirm], a[data-confirm], input[data-confirm]');
    if (control && !control.closest('form[data-confirm]') && !window.confirm(control.dataset.confirm)) {
        event.preventDefault();
        event.stopPropagation();
        return;
    }
    const toastTrigger = event.target.closest('[data-ds-toast]');
    if (toastTrigger) {
        try { toast(JSON.parse(toastTrigger.dataset.dsToast)); } catch (e) { /* a malformed attribute shows nothing */ }
    }
}, true);
// data-autosubmit on a select or input sends its form when the value changes.
document.addEventListener('change', (event) => {
    const field = event.target;
    if (field instanceof HTMLElement && field.hasAttribute('data-autosubmit') && field.form) { field.form.requestSubmit(); }
});

/* ---------- Boot ---------- */
const boot = () => {
    // data-autofocus: the element takes focus when the page loads (an error summary after a failed submit).
    document.querySelector('[data-autofocus]')?.focus();
    initTabsets();
    $$('dialog[data-open-on-load]').forEach((dialog) => dialogs.open(dialog.id));
    startClocks();
};
if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
document.addEventListener('livewire:navigated', initTabsets);
