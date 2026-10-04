<?php
/** @var \OCP\IL10N $l Markup only; js/search.js does the rest. */
?>
<div id="app-content" class="fs">
    <form id="fs-form" class="fs-bar" role="search">
        <input id="fs-q" type="search" autocomplete="off" enterkeyhint="search"
               aria-label="<?php p($l->t('Search term')); ?>"
               placeholder="<?php p($l->t('What is in the picture? e.g. woman on the beach')); ?>">
        <select id="fs-person" aria-label="<?php p($l->t('Person')); ?>">
            <option value=""><?php p($l->t('All people')); ?></option>
        </select>
        <select id="fs-type" aria-label="<?php p($l->t('Type')); ?>">
            <option value=""><?php p($l->t('Photos and videos')); ?></option>
            <option value="IMAGE"><?php p($l->t('Photos only')); ?></option>
            <option value="VIDEO"><?php p($l->t('Videos only')); ?></option>
        </select>
        <button type="submit" class="primary"><?php p($l->t('Search')); ?></button>
    </form>
    <p id="fs-status" class="fs-status" aria-live="polite"></p>
    <div id="fs-grid" class="fs-grid"></div>
    <div class="fs-more"><button id="fs-more" type="button" hidden><?php p($l->t('Load more')); ?></button></div>
</div>
