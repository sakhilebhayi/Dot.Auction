/*
 * seller: behaviour for the seller screens, as progressive enhancement. Every part works without it (the form posts, the photo input is a plain
 * file input, the quick picks and the suggestion stay hidden). Nothing here changes a rule: the server validates everything again.
 */
const $ = (selector, root = document) => root.querySelector(selector);
const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));
const reduceMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/* ---------- Jump links in the error summary go to the field and focus it ---------- */
document.addEventListener('click', (event) => {
    const link = event.target.closest('a[data-jump]');
    if (!link) { return; }
    const target = document.getElementById(link.getAttribute('href').slice(1));
    if (!target) { return; }
    event.preventDefault();
    target.scrollIntoView({ block: 'center', behavior: reduceMotion() ? 'auto' : 'smooth' });
    target.focus({ preventScroll: true });
});

/* ---------- A lot's menu: opening a confirm dialog from a menu item closes the menu and gives focus back to its button ---------- */
document.addEventListener('click', (event) => {
    const item = event.target.closest('.ds-menu [data-dialog-open]');
    if (!item) { return; }
    const root = item.closest('[data-ds-dropdown]');
    const trigger = root && $('[data-dropdown-trigger]', root);
    const menu = item.closest('.ds-menu');
    if (!trigger || !menu) { return; }
    menu.hidden = true;
    trigger.setAttribute('aria-expanded', 'false');
    const dialog = document.getElementById(item.getAttribute('data-dialog-open'));
    dialog?.addEventListener('close', () => trigger.focus(), { once: true });
});

/* ---------- The listing form ---------- */
const form = $('[data-listing-form]');
if (form) {
    // Character counters on fields that have a limit. The limit itself is the field's maxlength, so the server and the counter agree.
    $$('[data-count]', form).forEach((field) => {
        const max = Number(field.getAttribute('maxlength'));
        if (!max) { return; }
        const counter = document.createElement('p');
        counter.className = 'sl-count';
        counter.setAttribute('aria-hidden', 'true');
        (field.closest('.ds-field') || field.parentElement).appendChild(counter);
        const update = () => {
            const used = field.value.length;
            counter.textContent = used.toLocaleString('en-US') + ' of ' + max.toLocaleString('en-US');
            counter.classList.toggle('is-near', used >= max * 0.9);
        };
        field.addEventListener('input', update);
        update();
    });

    // "You have unsaved changes" beside Publish: publishing uses what is saved.
    const unsaved = $('[data-unsaved]');
    if (unsaved) {
        const mark = () => { unsaved.hidden = false; };
        form.addEventListener('input', mark);
        form.addEventListener('change', mark);
        form.addEventListener('submit', () => { unsaved.hidden = true; });
    }

    // Quick picks for the closing time: N days after the opening time, or after now (the page gives now in the display zone).
    const picks = $('[data-picks]');
    const closes = $('[data-closes] input, input[data-closes]', form) || document.getElementById('ends_at');
    const opens = document.getElementById('starts_at');
    const pad = (n) => String(n).padStart(2, '0');
    const toParts = (value) => {
        const m = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/.exec(value || '');
        return m ? { y: +m[1], mo: +m[2], d: +m[3], h: +m[4], mi: +m[5] } : null;
    };
    if (picks && closes) {
        picks.hidden = false;
        picks.addEventListener('click', (event) => {
            const button = event.target.closest('[data-pick-days]');
            if (!button) { return; }
            const base = toParts(opens?.value) || toParts(picks.dataset.now);
            if (!base) { return; }
            // Calendar arithmetic in UTC so the browser's own zone never enters: the value is wall-clock time in the display zone.
            const at = new Date(Date.UTC(base.y, base.mo - 1, base.d + Number(button.dataset.pickDays), base.h, base.mi));
            closes.value = `${at.getUTCFullYear()}-${pad(at.getUTCMonth() + 1)}-${pad(at.getUTCDate())}T${pad(at.getUTCHours())}:${pad(at.getUTCMinutes())}`;
            closes.dispatchEvent(new Event('input', { bubbles: true }));
            closes.focus();
        });
    }

    // Suggest a bid step: about 5% of the starting price, rounded to a tidy 1, 2 or 5 times a power of ten.
    const start = document.getElementById('starting_price');
    const step = document.getElementById('bid_increment');
    const hint = $('[data-suggest]');
    const apply = hint && $('[data-suggest-apply]', hint);
    const symbol = (start?.closest('.ds-control')?.querySelector('.ds-affix')?.textContent || '').trim();
    const suggestion = (value) => {
        const amount = parseFloat(String(value).replace(/[^0-9.]/g, ''));
        if (!isFinite(amount) || amount <= 0) { return null; }
        const raw = amount * 0.05;
        if (raw < 1) { return 1; }
        const power = Math.pow(10, Math.floor(Math.log10(raw)));
        const scaled = raw / power;
        const nice = scaled < 1.5 ? 1 : scaled < 3.5 ? 2 : scaled < 7.5 ? 5 : 10;
        return nice * power;
    };
    const money = (n) => n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const refreshSuggestion = () => {
        if (!hint || !start || !step) { return; }
        const n = suggestion(start.value);
        const show = n !== null && step.value.trim() === '';
        hint.hidden = !show;
        if (show) { apply.textContent = 'Use ' + symbol + money(n); apply.dataset.value = n.toFixed(2); }
    };
    if (hint && apply) {
        start?.addEventListener('input', refreshSuggestion);
        step?.addEventListener('input', refreshSuggestion);
        apply.addEventListener('click', () => {
            step.value = apply.dataset.value;
            step.dispatchEvent(new Event('input', { bubbles: true }));
            step.focus();
        });
        refreshSuggestion();
    }

    // The preview card follows the title and the starting price.
    const preview = $('[data-preview]');
    if (preview) {
        const title = document.getElementById('title');
        const link = $('.ds-lot__link', preview);
        if (title && link) {
            const fallback = link.textContent;
            const updateTitle = () => { link.textContent = title.value.trim() || fallback; };
            title.addEventListener('input', updateTitle);
        }
        const whole = $('.ds-lot__price .ds-money__whole', preview);
        const cents = $('.ds-lot__price .ds-money__cents', preview);
        if (start && whole && cents && !preview.classList.contains('is-saved')) {
            start.addEventListener('input', () => {
                const n = parseFloat(String(start.value).replace(/[^0-9.]/g, ''));
                const safe = isFinite(n) ? n : 0;
                const rounded = Math.round(safe * 100);
                whole.textContent = Math.floor(rounded / 100).toLocaleString('en-US');
                cents.textContent = '.' + pad(rounded % 100);
            });
        }
    }
}

/* ---------- Photo upload: drop zone, previews, checks, progress ---------- */
const upload = $('[data-upload]');
if (upload && window.DataTransfer && window.XMLHttpRequest && window.FormData) {
    upload.setAttribute('data-enhanced', '');
    const input = $('input[type="file"]', upload);
    const drop = $('[data-drop]', upload);
    const queue = $('[data-queue]', upload);
    const submit = $('[data-upload-submit]', upload);
    const progress = $('[data-progress]', upload);
    const bar = progress && $('[role="progressbar"]', progress);
    const fill = progress && $('[data-progress-fill]', progress);
    const text = progress && $('[data-progress-text]', progress);
    const problem = $('[data-upload-error]', upload);
    const hintEl = $('[data-drop-hint]', upload);
    const room = Number(upload.dataset.room);
    const maxBytes = Number(upload.dataset.maxBytes);
    const maxMb = upload.dataset.maxMb;
    const types = ['image/jpeg', 'image/png', 'image/webp'];
    let files = [];
    let busy = false;

    const sizeText = (bytes) => bytes >= 1048576 ? (bytes / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(bytes / 1024)) + ' KB';
    const issue = (file, index) => {
        if (!types.includes(file.type)) { return 'Not a JPG, PNG or WebP photo'; }
        if (file.size > maxBytes) { return 'Larger than ' + maxMb + ' MB'; }
        if (index >= room) { return 'Over the limit: only ' + room + ' more ' + (room === 1 ? 'photo fits' : 'photos fit'); }
        return null;
    };
    const showProblem = (message) => {
        problem.hidden = !message;
        $('span', problem).textContent = message || '';
    };
    const sync = () => {
        const dt = new DataTransfer();
        files.forEach((file) => dt.items.add(file));
        input.files = dt.files;
    };
    const render = () => {
        queue.replaceChildren();
        const bad = [];
        files.forEach((file, index) => {
            const why = issue(file, index);
            if (why) { bad.push(file.name + ': ' + why); }
            const item = document.createElement('li');
            item.className = 'sl-queue__item' + (why ? ' is-bad' : '');
            const img = document.createElement('img');
            img.alt = '';
            if (file.type.startsWith('image/')) {
                const url = URL.createObjectURL(file);
                img.src = url;
                img.addEventListener('load', () => URL.revokeObjectURL(url), { once: true });
            }
            const info = document.createElement('span');
            const name = document.createElement('span');
            name.className = 'sl-queue__name';
            name.textContent = file.name;
            const meta = document.createElement('span');
            meta.className = 'sl-queue__meta';
            meta.textContent = why || sizeText(file.size);
            info.append(name, meta);
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'ds-btn ds-btn--quiet ds-btn--sm';
            remove.textContent = 'Remove';
            remove.setAttribute('aria-label', 'Remove ' + file.name + ' from the upload');
            remove.addEventListener('click', () => {
                files = files.filter((f) => f !== file);
                sync();
                render();
                (files.length ? $('.sl-queue__item button', queue) : input)?.focus();
            });
            item.append(img, info, remove);
            queue.appendChild(item);
        });
        showProblem(bad.length ? bad.join('. ') + '.' : '');
        submit.disabled = busy || files.length === 0 || bad.length > 0;
        hintEl.textContent = files.length ? files.length + (files.length === 1 ? ' photo ready' : ' photos ready') + '. Drop more, or choose again.' : 'or drop them here';
        const label = $('.ds-btn__label', submit);
        if (label) { label.textContent = files.length > 1 ? 'Upload ' + files.length + ' photos' : 'Upload photo' + (files.length ? '' : 's'); }
    };
    const add = (list) => {
        const incoming = Array.from(list || []);
        // The same file twice is one file.
        incoming.forEach((file) => { if (!files.some((f) => f.name === file.name && f.size === file.size && f.lastModified === file.lastModified)) { files.push(file); } });
        sync();
        render();
    };

    input.addEventListener('change', () => { files = Array.from(input.files); render(); });
    ['dragenter', 'dragover'].forEach((type) => drop.addEventListener(type, (event) => { event.preventDefault(); drop.classList.add('is-over'); }));
    ['dragleave', 'drop'].forEach((type) => drop.addEventListener(type, (event) => { event.preventDefault(); drop.classList.remove('is-over'); }));
    drop.addEventListener('drop', (event) => add(event.dataTransfer?.files));

    upload.addEventListener('submit', (event) => {
        event.preventDefault();
        if (busy || files.length === 0) { return; }
        if (files.some((file, index) => issue(file, index))) { render(); return; }
        busy = true;
        submit.disabled = true;
        showProblem('');
        progress.hidden = false;
        const set = (pct, message) => {
            bar.setAttribute('aria-valuenow', String(pct));
            fill.style.transform = 'scaleX(' + pct / 100 + ')';
            text.textContent = message;
        };
        set(0, 'Uploading ' + files.length + (files.length === 1 ? ' photo' : ' photos') + '…');
        const body = new FormData(upload);
        body.delete('images[]');
        files.forEach((file) => body.append('images[]', file));
        const xhr = new XMLHttpRequest();
        xhr.open('POST', upload.action);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.upload.addEventListener('progress', (e) => {
            if (e.lengthComputable) {
                const pct = Math.min(99, Math.round((e.loaded / e.total) * 100));
                set(pct, pct < 99 ? 'Uploading… ' + pct + '%' : 'Saving the photos…');
            }
        });
        const fail = (message) => {
            busy = false;
            progress.hidden = true;
            // render() rewrites the problem line from the queue, so it must run first or it wipes the server's reason.
            render();
            showProblem(message);
            submit.disabled = false;
            if (window.dsToast) { window.dsToast({ title: 'Photos were not uploaded', body: message, tone: 'danger' }); }
        };
        xhr.addEventListener('load', () => {
            if (xhr.status >= 200 && xhr.status < 300) {
                set(100, 'Done. Reloading…');
                window.location.reload();
                return;
            }
            let message = 'The photos could not be uploaded. Nothing was saved. Please try again.';
            try {
                const data = JSON.parse(xhr.responseText);
                message = (data.errors && Object.values(data.errors).flat()[0]) || data.message || message;
            } catch (e) { /* keep the generic message */ }
            if (xhr.status === 419) { message = 'This page has expired. Reload it and try again.'; }
            if (xhr.status === 413) { message = 'The photos are too large to send in one go. Upload fewer at a time.'; }
            fail(message);
        });
        xhr.addEventListener('error', () => fail('The connection dropped. Nothing was saved. Check your connection and try again.'));
        xhr.addEventListener('abort', () => fail('The upload was cancelled.'));
        xhr.send(body);
    });
    render();
}
