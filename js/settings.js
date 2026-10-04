(function () {
    'use strict';

    const APP = 'immich_search';
    const $ = (id) => document.getElementById(id);
    const tr = (s, v) => t(APP, s, v);

    async function send(method, path) {
        const r = await fetch(OC.generateUrl('/apps/' + APP + path), {
            method,
            headers: { requesttoken: OC.requestToken, 'Content-Type': 'application/json' },
            body: JSON.stringify({
                url: $('fs-url').value.trim(),
                apiKey: $('fs-key').value.trim(),
                pathPattern: $('fs-path').value.trim(),
            }),
        });
        const data = await r.json().catch(() => ({}));
        if (!r.ok) throw new Error(data.error || data.message || 'HTTP ' + r.status);
        return data;
    }

    function msg(text, ok) {
        $('fs-msg').textContent = text;
        $('fs-msg').style.color = ok ? 'var(--color-success-text)' : 'var(--color-error-text)';
    }

    function keyStored() {
        $('fs-key').value = '';
        $('fs-key').placeholder = tr('stored, leave empty to keep');
    }

    document.addEventListener('DOMContentLoaded', () => {
        const s = OCP.InitialState.loadState(APP, 'settings', { url: '', hasKey: false, pathPattern: '' });
        $('fs-url').value = s.url;
        $('fs-path').value = s.pathPattern;
        if (s.hasKey) keyStored();

        $('fs-test').addEventListener('click', async () => {
            msg(tr('Testing …'), true);
            try { msg(tr('Connected as {user}', { user: (await send('POST', '/api/settings/test')).user }), true); }
            catch (e) { msg(e.message, false); }
        });

        $('fs-save').addEventListener('click', async () => {
            msg(tr('Saving …'), true);
            try {
                const r = await send('PUT', '/api/settings');
                keyStored();
                msg(tr('Saved, connected as {user}', { user: r.user }), true);
            } catch (e) { msg(e.message, false); }
        });
    });
})();
