/*
 * The lot page's behaviour. Plain JavaScript, progressive enhancement: the page reads and the forms work without this file.
 *
 *  1. Live bidding without Reverb. Every [data-auction-live] element is a BidPanel. A timer asks the server for its snapshot ($wire.snapshot(),
 *     renderless and cached for a second, see App\Services\Auctions\AuctionSnapshot) and re-renders the panel (refreshState) only when something
 *     changed. The timer runs only while the tab is visible, slows down when Reverb is pushing events, and speeds up in the last two minutes.
 *     The clock is corrected with the server's own time, so a wrong device clock cannot show a wrong countdown. At zero the panel asks the server
 *     what happened; it never decides the result from the client clock.
 *  2. The photo gallery: previous and next buttons, thumbnails, arrow keys and the "Photo 2 of 4" count on top of a scroll-snap strip.
 *  3. Focus goes to the bid field when the server refuses what was typed.
 *  4. When a Livewire call fails (connection lost, session or page expired, server error) the person is told in words, and the
 *     browser's default (a silent nothing for a lost connection, a full-screen copy of the error page for a 500) is not used.
 */

const reduceMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const live = new WeakMap();

const signature = (s) => [s.status, s.current_price_cents ?? '', s.bid_count ?? '', s.ends_at ?? '', s.leading_bidder_is_me ? '1' : '0'].join('|');

const nextDelay = (s, echo) => {
    if (echo) { return 30000; }
    const base = Math.min(15, Math.max(3, Number(s?.poll_seconds) || 4)) * 1000;
    return s && s.seconds_remaining != null && s.seconds_remaining <= 120 ? Math.min(base, 3000) : base;
};

const startLive = (component) => {
    const root = component.el;
    if (!root || !root.hasAttribute('data-auction-live') || live.has(root)) { return; }

    const $wire = component.$wire;
    const st = { offset: null, failures: 0, timer: null, busy: false, stopped: false, last: null };
    live.set(root, st);

    // A lost connection leaves Livewire's call hanging, never settled. Without this the poll would wait for ever and never resume.
    const onNetworkLost = () => { st.failures = 3; lost(true); st.busy = false; schedule(); };
    window.addEventListener('dot:network-lost', onNetworkLost);

    const typed = () => {
        const field = root.querySelector('[data-ds-bid-input]');
        if (!field) { return null; }
        const v = field.value.trim();
        return v !== '' && v !== field.dataset.prefill ? v : null;
    };
    const applyOffset = () => {
        if (st.offset === null) { return; }
        root.querySelectorAll('[data-ds-countdown], [data-ds-rail]').forEach((el) => { el.dataset.offset = String(st.offset); });
    };
    const lost = (on) => {
        const note = root.querySelector('[data-live-lost]');
        if (note) { note.hidden = !on; }
    };
    const echoOn = () => root.dataset.realtime === '1' && !!window.Echo;

    const schedule = () => {
        window.clearTimeout(st.timer);
        if (st.stopped) { return; }
        st.timer = window.setTimeout(tick, nextDelay(st.last, echoOn()));
    };

    const tick = async () => {
        if (st.stopped || st.busy) { return; }
        if (document.hidden) { return; } // resumes from visibilitychange
        st.busy = true;
        try {
            const s = await $wire.call("snapshot");
            st.last = s;
            st.failures = 0;
            lost(false);
            st.offset = Number(s.server_now_ms) - Date.now();
            applyOffset();
            // Re-render only when the server's picture differs from the one on screen, or while a closed lot's result is still being recorded.
            if (signature(s) !== root.dataset.sig || root.hasAttribute('data-settling')) {
                await $wire.call("refreshState", typed());
            }
        } catch (e) {
            st.failures += 1;
            if (st.failures >= 3) { lost(true); }
        } finally {
            st.busy = false;
            schedule();
        }
    };

    const kick = (ms = 0) => { window.clearTimeout(st.timer); st.timer = window.setTimeout(tick, ms); };

    root.addEventListener('ds:countdown-end', () => kick(500));
    const onVisible = () => { if (!document.hidden) { kick(0); } };
    document.addEventListener('visibilitychange', onVisible);

    st.stop = () => { st.stopped = true; window.clearTimeout(st.timer); document.removeEventListener('visibilitychange', onVisible); window.removeEventListener('dot:network-lost', onNetworkLost); };
    st.applyOffset = applyOffset;
    kick(echoOn() ? 5000 : nextDelay(null, false));
};

/* ---------- When a Livewire call fails ---------- */
// Polls are quiet (the lot page shows its own "Live updates paused" note); what a person asked for is not.
const QUIET = /"method":"(snapshot|refreshState|refresh|\$refresh)"/;
let lastNotice = 0;
const notify = (title, body) => {
    const now = Date.now();
    if (now - lastNotice < 8000) { return; } // one notice per burst
    lastNotice = now;
    if (typeof window.dsToast === 'function') { window.dsToast({ title, body, tone: 'danger' }); }
};
const setupFailures = () => {
    window.Livewire.hook('request', ({ payload, fail }) => {
        const quiet = QUIET.test(String(payload || ''));
        fail(({ status, content, preventDefault }) => {
            if (content === null) {
                // The browser could not reach the server at all (Livewire reports 503 with no body).
                window.dispatchEvent(new CustomEvent('dot:network-lost'));
                if (!quiet) { notify('No connection', 'Nothing was sent. Check your connection and try again. The page keeps trying by itself.'); }
                return;
            }
            if (quiet) { return; }
            if (status === 419) {
                preventDefault();
                notify('This page has expired', 'Nothing was sent. Reload the page to continue.');
            } else if (status >= 500) {
                preventDefault();
                notify('Something went wrong on our side', 'What you just did may not have gone through. Check the page, then try again.');
            }
        });
    });
};

const setupLivewire = () => {
    setupFailures();
    window.Livewire.hook('component.init', ({ component, cleanup }) => {
        startLive(component);
        cleanup(() => live.get(component.el)?.stop?.());
    });
    window.Livewire.hook('morphed', ({ component }) => { live.get(component.el)?.applyOffset?.(); });
    (window.Livewire.all?.() || []).forEach(startLive);
};
if (window.Livewire?.hook) { setupLivewire(); } else { document.addEventListener('livewire:init', setupLivewire); }

window.addEventListener('lot-bid-focus', () => {
    window.setTimeout(() => {
        const field = document.querySelector('[data-ds-bid-input]');
        if (field) { field.focus(); field.select?.(); }
    }, 0);
});

/* ---------- Gallery ---------- */
const initGallery = (root) => {
    if (root.dataset.ready) { return; }
    const track = root.querySelector('[data-gallery-track]');
    const slides = Array.from(root.querySelectorAll('[data-gallery-slide]'));
    if (!track || slides.length < 2) { return; }
    root.dataset.ready = '1';

    const prev = root.querySelector('[data-gallery-prev]');
    const next = root.querySelector('[data-gallery-next]');
    const count = root.querySelector('[data-gallery-current]');
    const thumbsBox = root.querySelector('[data-gallery-thumbs]');
    const thumbs = Array.from(root.querySelectorAll('[data-gallery-thumb]'));
    const n = slides.length;
    let current = 0;

    if (prev) { prev.hidden = false; }
    if (next) { next.hidden = false; }
    if (thumbsBox) { thumbsBox.hidden = false; }

    const show = (i) => {
        current = i;
        if (count) { count.textContent = String(i + 1); }
        thumbs.forEach((t, k) => { if (k === i) { t.setAttribute('aria-current', 'true'); } else { t.removeAttribute('aria-current'); } });
        if (prev) { prev.setAttribute('aria-disabled', i === 0 ? 'true' : 'false'); }
        if (next) { next.setAttribute('aria-disabled', i === n - 1 ? 'true' : 'false'); }
    };
    const go = (i) => {
        const k = Math.max(0, Math.min(n - 1, i));
        track.scrollTo({ left: k * track.clientWidth, behavior: reduceMotion() ? 'auto' : 'smooth' });
        show(k);
    };
    let frame = 0;
    track.addEventListener('scroll', () => {
        window.cancelAnimationFrame(frame);
        frame = window.requestAnimationFrame(() => {
            const i = Math.round(track.scrollLeft / Math.max(1, track.clientWidth));
            if (i !== current && i >= 0 && i < n) { show(i); }
        });
    }, { passive: true });
    track.addEventListener('keydown', (event) => {
        const to = { ArrowLeft: current - 1, ArrowRight: current + 1, Home: 0, End: n - 1 }[event.key];
        if (to !== undefined) { event.preventDefault(); go(to); }
    });
    prev?.addEventListener('click', () => { if (prev.getAttribute('aria-disabled') !== 'true') { go(current - 1); } });
    next?.addEventListener('click', () => { if (next.getAttribute('aria-disabled') !== 'true') { go(current + 1); } });
    thumbs.forEach((t) => t.addEventListener('click', () => go(Number(t.dataset.galleryThumb))));
    window.addEventListener('resize', () => { track.scrollTo({ left: current * track.clientWidth, behavior: 'auto' }); });
    show(0);
};
const bootGalleries = () => document.querySelectorAll('[data-lot-gallery]').forEach(initGallery);
if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', bootGalleries); } else { bootGalleries(); }
