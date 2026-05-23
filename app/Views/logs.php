<?php defined('ABSPATH') || exit; ?>
<div class="wrap">
    <?php include 'header.php'?>
    <div class="flareload-log-viewer-content">
        <?php
        do_settings_sections('flare-load-logs');
        ?>
    </div>
</div>
