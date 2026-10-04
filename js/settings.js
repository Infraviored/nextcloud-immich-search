(function () {
    'use strict';

    const APP = 'immich_search';
    const $ = (id) => document.getElementById(id);

    async function send(method, path) {
        const r = await fetch(OC.generateUrl('/apps/' + APP + path), {
            method,
            headers: { requesttoken: OC.requestToken, 'Content-Type': 'application/json' },
            body: JSON.stringify({ url: $('fs-url').value.trim(), apiKey: $('fs-key').value.trim() }),
        });
        const data = await r.json().catch(() => ({}));
        if (!r.ok) throw new Error(data.error || data.message || 'HTTP ' + r.status);
        return data;
    }

    function msg(text, ok) {
        $('fs-msg').textContent = text;
        $('fs-msg').style.color = ok ? 'var(--color-success-text)' : 'var(--color-error-text)';
    }

    document.addEventListener('DOMContentLoaded', () => {
        const s = OCP.InitialState.loadState(APP, 'settings', { url: '', hasKey: false });
        $('fs-url').value = s.url;
        $('fs-key').placeholder = s.hasKey ? 'gespeichert, leer lassen zum Behalten' : '';

        $('fs-test').addEventListener('click', async () => {
            msg('Teste …', true);
            try { msg('Verbunden als ' + (await send('POST', '/api/settings/test')).user, true); }
            catch (e) { msg(e.message, false); }
        });

        $('fs-save').addEventListener('click', async () => {
            msg('Speichere …', true);
            try {
                const r = await send('PUT', '/api/settings');
                $('fs-key').value = '';
                $('fs-key').placeholder = 'gespeichert, leer lassen zum Behalten';
                msg('Gespeichert, verbunden als ' + r.user, true);
            } catch (e) { msg(e.message, false); }
        });
    });
})();
