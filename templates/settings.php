<?php /** Personal settings form; js/settings.js handles it. */ ?>
<div id="immich-search-settings" class="section">
    <h2>Fotosuche (Immich)</h2>
    <p class="settings-hint">Verbindung zu Immich für die Inhaltssuche in Fotos. Den API-Schlüssel
        erzeugst du in Immich unter Kontoeinstellungen → API-Schlüssel.</p>
    <p>
        <label for="fs-url">Immich-Adresse</label><br>
        <input id="fs-url" type="url" placeholder="http://192.168.178.2:2283" style="width: min(100%, 420px)">
    </p>
    <p>
        <label for="fs-key">API-Schlüssel</label><br>
        <input id="fs-key" type="password" autocomplete="off" style="width: min(100%, 420px)">
    </p>
    <p>
        <button id="fs-test" type="button">Verbindung testen</button>
        <button id="fs-save" type="button" class="primary">Speichern</button>
        <span id="fs-msg" aria-live="polite"></span>
    </p>
</div>
