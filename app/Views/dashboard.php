<div class="wrap">
    <?php include 'header.php'?>
    <form id="fp_options_form" method="post" action="options.php">
        <?php
        settings_fields('fp_settings_group');
        do_settings_sections('flare-press-settings');
        submit_button();
        ?>
    </form>
    <div>
        <?php
        settings_fields('fp_variant_settings_group');
        do_settings_sections('flare-press-variant-settings');
        ?>
    </div>

</div>
