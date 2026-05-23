<?php defined('ABSPATH') || exit; ?>
<div class="wrap">
    <?php include 'header.php'?>
    <form id="flareload_options_form" method="post" action="options.php">
        <?php
        settings_fields('flareload_settings_group');
        do_settings_sections('flare-load-settings');
        submit_button();
        ?>
    </form>
    <div>
        <?php
        settings_fields('flareload_variant_settings_group');
        do_settings_sections('flare-load-variant-settings');
        ?>
    </div>

</div>
