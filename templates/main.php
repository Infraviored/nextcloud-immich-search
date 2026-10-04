<?php /** Markup only; js/search.js does the rest. */ ?>
<div id="app-content" class="fs">
    <form id="fs-form" class="fs-bar" role="search">
        <input id="fs-q" type="search" autocomplete="off" enterkeyhint="search"
               aria-label="Suchbegriff" placeholder="Was ist auf dem Bild? z. B. Frau am Strand">
        <select id="fs-person" aria-label="Person"><option value="">Alle Personen</option></select>
        <select id="fs-type" aria-label="Art">
            <option value="">Fotos und Videos</option>
            <option value="IMAGE">Nur Fotos</option>
            <option value="VIDEO">Nur Videos</option>
        </select>
        <button type="submit" class="primary">Suchen</button>
    </form>
    <p id="fs-status" class="fs-status" aria-live="polite"></p>
    <div id="fs-grid" class="fs-grid"></div>
    <div class="fs-more"><button id="fs-more" type="button" hidden>Mehr laden</button></div>
</div>
