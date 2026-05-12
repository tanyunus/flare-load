<?php defined('ABSPATH') || exit; ?>
<div class="wrap">
    <?php include 'header.php'?>
    <div class="flarep-log-viewer-content">
        <?php
        do_settings_sections('flare-press-logs');
        ?>
    </div>
</div>
