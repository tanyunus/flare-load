<?php defined('ABSPATH') || exit; use FlarePress\Data\Constants; use FlarePress\Util\Utils; ?>
<div class="fp-header">
    <img height="120" src="<?php echo esc_url(FLARE_PRESS_URL . 'dist/images/fp_logo.png') ?>">
    <div>
        <p><?php echo wp_kses(Utils::localize(Constants::UI_HEADER_TAGLINE), ['br' => []]); ?></p>
        <a target="_blank" title="<?php echo esc_attr(Utils::localize(Constants::UI_HEADER_WEBSITE_LINK_TITLE)); ?>" href="https://flare-press.com">Website</a>&nbsp;&nbsp;
        <a target="_blank" title="<?php echo esc_attr(Utils::localize(Constants::UI_HEADER_GITHUB_LINK_TITLE)); ?>" href="https://github.com/tanyunus">Github</a>
    </div>
</div>
<hr>
<br>
