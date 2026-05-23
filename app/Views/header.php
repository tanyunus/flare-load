<?php defined('ABSPATH') || exit; ?>
<div class="flareload-header">
    <img height="120" src="<?php echo esc_url(FLARELOAD_URL . 'dist/images/flareload_logo.png') ?>">
    <div>
        <p><?php echo wp_kses(__('DIRECT<br>CLOUDFLARE IMAGES<br>INTEGRATION', 'flare-load'), ['br' => []]); ?></p>
        <a target="_blank" title="<?php echo esc_attr(__('FlareLoad developer Github profile', 'flare-load')); ?>" href="https://github.com/tanyunus">Github</a>
    </div>
</div>
<hr>
<br>
