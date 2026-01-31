<div class="wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
    <div class="fp-log-viewer-content">
        <?php
        do_settings_sections('flare-press-logs');
        ?>
    </div>
</div>
