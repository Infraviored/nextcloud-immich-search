<?php
/** @var \OCP\IL10N $l Admin settings form; js/settings.js handles it. */
?>
<div id="immich-search-settings" class="section">
    <h2><?php p($l->t('Photo search (Immich)')); ?></h2>
    <p class="settings-hint"><?php p($l->t('One connection for everybody. Immich indexes the files of all users; everyone only finds what they can open in Nextcloud themselves (their own files and files shared with them). The API key belongs to the Immich account that owns the libraries.')); ?></p>
    <p>
        <label for="fs-url"><?php p($l->t('Immich address')); ?></label><br>
        <input id="fs-url" type="url" placeholder="http://immich:2283" style="width: min(100%, 420px)">
    </p>
    <p>
        <label for="fs-key"><?php p($l->t('API key')); ?></label><br>
        <input id="fs-key" type="password" autocomplete="off" style="width: min(100%, 420px)">
    </p>
    <p>
        <label for="fs-path"><?php p($l->t('Path of a user\'s files inside Immich')); ?></label><br>
        <input id="fs-path" type="text" placeholder="/mnt/nextcloud/{user}/" style="width: min(100%, 420px)"><br>
        <em><?php p($l->t('{user} stands for the Nextcloud user ID. Mount <data directory>/<user>/files read-only there and add it to Immich as an external library.')); ?></em>
    </p>
    <p>
        <button id="fs-test" type="button"><?php p($l->t('Test connection')); ?></button>
        <button id="fs-save" type="button" class="primary"><?php p($l->t('Save')); ?></button>
        <span id="fs-msg" aria-live="polite"></span>
    </p>
</div>
