<?php

namespace FP\Controllers;

use FP\Utils\Template;

class FPDashboard {
    public function __construct() {
        add_action( 'admin_menu', [$this, 'initFPSettings'] );
    }

    public function initFPSettings(): void {
        $this->addSettingsPage();
        $this->addSection();
        $this->registerAccountIDField();
        $this->registerAccountHashField();
        $this->registerAPITokenField();
    }

    private function addSettingsPage(): void {
        add_options_page(
            FPConstants::DASHBOARD_PAGE_TITLE,
            FPConstants::DASHBOARD_MENU_TITLE,
            'manage_options',
            FPConstants::DASHBOARD_MENU_SLUG,
            [$this, 'fpAdminDashboardView'],
            5
        );
    }

    public function fpAdminDashboardView(): void {
        Template::render(FPConstants::DASHBOARD_VIEW);
    }

    private function addSection(): void {
        add_settings_section(
            'fp_settings_section_id',
            '',
            '',
            FPConstants::DASHBOARD_MENU_SLUG
        );
    }

    private function registerAccountIDField(): void {
        register_setting(FPConstants::DASHBOARD_SETTINGS_GROUP_NAME, FPConstants::DASHBOARD_CF_ACCOUNT_ID_FIELD_NAME, [$this, 'sanitizeText']);

        add_settings_field(
            FPConstants::DASHBOARD_CF_ACCOUNT_ID_FIELD_NAME,
            'Cloudflare Account ID',
            function() {
                $this->renderInputField(FPConstants::DASHBOARD_CF_ACCOUNT_ID_FIELD_NAME);
            },
            FPConstants::DASHBOARD_MENU_SLUG,
            FPConstants::DASHBOARD_SECTION_ID,
        );
    }

    private function registerAccountHashField(): void {
        register_setting(FPConstants::DASHBOARD_SETTINGS_GROUP_NAME, FPConstants::DASHBOARD_CF_ACCOUNT_HASH_FIELD_NAME, [$this, 'sanitizeText']);

        add_settings_field(
                FPConstants::DASHBOARD_CF_ACCOUNT_HASH_FIELD_NAME,
                'Cloudflare Account Hash',
                function() {
                    $description = 'You can find it under <i>https://dash.cloudflare.com/<b>your-account-id-here</b>/images/hosted</i>';
                    $this->renderInputField(FPConstants::DASHBOARD_CF_ACCOUNT_HASH_FIELD_NAME, $description);
                },
                FPConstants::DASHBOARD_MENU_SLUG,
                FPConstants::DASHBOARD_SECTION_ID,
        );
    }

    private function registerApiTokenField(): void {
        register_setting(FPConstants::DASHBOARD_SETTINGS_GROUP_NAME, FPConstants::DASHBOARD_CF_API_TOKEN_FIELD_NAME, [$this, 'sanitizeText']);

        add_settings_field(
            FPConstants::DASHBOARD_CF_API_TOKEN_FIELD_NAME,
            'Cloudflare Account API Token',
            function() {
                $description = 'You can find it under <i>https://dash.cloudflare.com/<b>your-account-id-here</b>/api-tokens</i>';
                $this->renderInputField(FPConstants::DASHBOARD_CF_API_TOKEN_FIELD_NAME, $description);
            },
            FPConstants::DASHBOARD_MENU_SLUG,
            FPConstants::DASHBOARD_SECTION_ID,
        );
    }

    private function renderInputField(string $optionName, string $description = '', bool $hideValue = false): void {
        $value = get_option($optionName);

        ?>
        <label>
            <input
                value="<?php echo !$hideValue ? esc_attr(get_option($optionName)) : ''; ?>"
                name="<?php echo $optionName ?>"
                id="<?php echo $optionName ?>"
                type="text"
                class="regular-text" />
        </label>
        <?php
        if(!empty($description)){
            ?>
            <p class="description"><?php echo $description ?></p>
            <?php
        }
    }
}