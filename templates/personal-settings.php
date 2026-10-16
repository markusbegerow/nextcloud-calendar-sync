<?php
/** @var \OCP\IL10N $l */
/** @var \OCP\IURLGenerator $urlGenerator */
?>
<div class="section" id="caldav-sync-personal-settings">
    <h2><?php p($l->t('Calendar Sync')) ?></h2>
    <p>
        <?php p($l->t('Manage your CalDAV sync configurations in the')) ?>
        <a href="<?php p($urlGenerator->linkToRoute('calendar-sync.page.index')) ?>">
            <?php p($l->t('Calendar Sync app')) ?>
        </a>.
    </p>
</div>
