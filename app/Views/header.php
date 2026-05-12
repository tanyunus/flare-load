<?php defined('ABSPATH') || exit; ?>
<div class="flarep-header">
    <img height="120" src="<?php echo esc_url(FLAREP_URL . 'dist/images/flarep_logo.png') ?>">
    <div>
        <p><?php echo wp_kses(__('DIRECT<br>CLOUDFLARE IMAGES<br>INTEGRATION', 'flare-press'), ['br' => []]); ?></p>
        <a target="_blank" title="<?php echo esc_attr(__('FlarePress plugin website', 'flare-press')); ?>" href="https://flare-press.com">Website</a>&nbsp;&nbsp;
        <a target="_blank" title="<?php echo esc_attr(__('FlarePress developer Github profile', 'flare-press')); ?>" href="https://github.com/tanyunus">Github</a>
    </div>
</div>
<hr>
<br>
