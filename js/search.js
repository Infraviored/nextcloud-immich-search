/**
 * Photo search page. Plain JS on purpose: no build step, nothing to break on updates.
 * Hits open as the real Nextcloud file in Nextcloud's viewer (videos play there).
 */
(function () {
    'use strict';

    const APP = 'immich_search';
    const url = (p) => OC.generateUrl('/apps/' + APP + p);
    const $ = (id) => document.getElementById(id);
    const tr = (s, v) => t(APP, s, v);
    const page = OCP.InitialState.loadState(APP, 'page', { configured: false });

    const state = { q: '', person: '', type: '', page: 1, items: [], next: null, ctrl: null };

    function fmtDate(s) {
        const d = s ? new Date(s) : null;
        return d && !isNaN(d) ? d.toLocaleDateString(OC.getLanguage()) : '';
    }

    function status(text, link) {
        const el = $('fs-status');
        el.replaceChildren(text);
        if (link) {
            const a = document.createElement('a');
            a.href = link.href;
            a.textContent = link.text;
            el.append(' ', a);
        }
    }

    async function api(path, signal) {
        const r = await fetch(url(path), { headers: { requesttoken: OC.requestToken }, signal });
        const data = await r.json().catch(() => ({}));
        if (!r.ok) throw new Error(data.error || 'HTTP ' + r.status);
        return data;
    }

    async function loadPeople() {
        try {
            const sel = $('fs-person');
            for (const p of await api('/api/people')) {
                sel.add(new Option(p.name, p.id));
            }
            sel.value = state.person;
        } catch (e) { /* the person filter is optional */ }
    }

    function open(item) {
        if (item.path && window.OCA && OCA.Viewer) {
            OCA.Viewer.open({ path: item.path });
        } else if (item.fileId) {
            window.location.href = OC.generateUrl('/f/' + item.fileId);
        } else {
            window.open(url('/api/assets/' + item.id + '/thumbnail?size=preview'), '_blank', 'noopener');
        }
    }

    function tile(item) {
        const el = document.createElement('div');
        el.className = 'fs-tile';

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'fs-open';
        btn.title = [fmtDate(item.date), item.path || item.name].filter(Boolean).join('\n');
        btn.setAttribute('aria-label', tr('Open {name}', { name: item.name }));
        const img = new Image();
        img.loading = 'lazy';
        img.decoding = 'async';
        img.alt = '';
        img.src = url('/api/assets/' + item.id + '/thumbnail');
        btn.append(img);
        if (item.type === 'VIDEO') {
            const b = document.createElement('span');
            b.className = 'fs-badge';
            b.textContent = '▶';
            btn.append(b);
        }
        btn.addEventListener('click', () => open(item));
        el.append(btn);

        if (item.fileId) {
            const a = document.createElement('a');
            a.className = 'fs-folder icon-folder';
            a.href = OC.generateUrl('/f/' + item.fileId);
            a.title = tr('Show in folder');
            a.setAttribute('aria-label', a.title);
            el.append(a);
        }
        return el;
    }

    function syncUrl() {
        const p = new URLSearchParams();
        if (state.q) p.set('q', state.q);
        if (state.person) p.set('person', state.person);
        if (state.type) p.set('type', state.type);
        const qs = p.toString();
        history.replaceState(null, '', window.location.pathname + (qs ? '?' + qs : ''));
    }

    async function run(reset) {
        if (reset) {
            state.q = $('fs-q').value.trim();
            state.person = $('fs-person').value;
            state.type = $('fs-type').value;
            state.page = 1;
            state.items = [];
            state.next = null;
            $('fs-grid').replaceChildren();
            syncUrl();
        } else if (state.ctrl) {
            return; // a page is already loading
        }
        $('fs-more').hidden = true;
        if (!state.q && !state.person) {
            status(tr('Describe what should be in the picture, or pick a person.'));
            return;
        }

        if (state.ctrl) state.ctrl.abort();
        const ctrl = state.ctrl = new AbortController();
        status(state.items.length ? tr('Loading more …') : tr('Searching …'));
        try {
            const p = new URLSearchParams({ q: state.q, page: state.page, person: state.person, type: state.type });
            const data = await api('/api/search?' + p, ctrl.signal);
            const frag = document.createDocumentFragment();
            for (const item of data.items) {
                state.items.push(item);
                frag.append(tile(item));
            }
            $('fs-grid').append(frag);
            state.next = data.nextPage;
            if (state.next) state.page = state.next;
            $('fs-more').hidden = !state.next;
            const count = state.items.length + (state.next ? '+' : '');
            status(state.items.length
                ? (state.q ? tr('{count} results, best first', { count }) : tr('{count} results, newest first', { count }))
                : (state.next ? tr('Nothing yet, searching further …') : tr('Nothing found.')));
        } catch (e) {
            if (e.name !== 'AbortError') status(tr('Error: {message}', { message: e.message }));
        } finally {
            if (state.ctrl === ctrl) state.ctrl = null;
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        if (!page.configured) {
            $('fs-form').hidden = true;
            status(tr('Photo search is not set up yet. An admin can do that under Administration settings → Additional settings.'), { href: page.settingsUrl, text: tr('Open') });
            return;
        }

        const params = new URLSearchParams(window.location.search);
        $('fs-q').value = params.get('q') || '';
        $('fs-type').value = params.get('type') || '';
        state.person = params.get('person') || '';

        $('fs-form').addEventListener('submit', (e) => { e.preventDefault(); $('fs-q').blur(); run(true); });
        $('fs-person').addEventListener('change', () => run(true));
        $('fs-type').addEventListener('change', () => run(true));
        $('fs-more').addEventListener('click', () => run(false));

        // Load the next page automatically when the end of the grid comes into view.
        new IntersectionObserver((entries) => {
            if (entries[0].isIntersecting && state.next) run(false);
        }, { rootMargin: '600px' }).observe($('fs-more').parentElement);

        loadPeople().then(() => {
            $('fs-person').value = state.person;
            if ($('fs-q').value || state.person) run(true);
            else { $('fs-q').focus(); run(true); }
        });
    });
})();
