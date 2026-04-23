<?php

namespace FlarePress\Controllers;

use Exception;
use FlarePress\Api\CloudflareImagesApi;
use FlarePress\Data\Constants;
use FlarePress\Util\Logger;
use FlarePress\Util\Utils;

class OptionController
{
    private array $variantNames;

    /**
     * Class itself serves as an initializer for setting up
     * option pages of plugin. All the stuff necessary to set up pages
     * run inside constructor itself.
     *
     * Static methods provide option related data operations.
     */
    public function __construct()
    {
        $this->variantNames = self::getVariantNamesAsArray();

        // Add page
        $this->addSettingsPage();

        // Add sections and fields
        $this->addUploadSettingsSection();
        $this->addApiSettingsSection();
        $this->addVariantSettingsSection();
        $this->registerAccountIDField();
        $this->registerAccountHashField();
        $this->registerAPITokenField();
        $this->registerFileManagementField();
        $this->registerVariantListField();
        $this->registerDefaultVariantField();

        // Add Log Page
        $this->addLogPage();
        $this->addLogViewerSection();
        $this->registerLogViewer();
    }

    private function addSettingsPage(): void
    {
        add_menu_page(
                Utils::localize(Constants::UI_PAGE_TITLE),
                Utils::localize(Constants::DASHBOARD_MENU_TITLE),
                'manage_options',
                Constants::DASHBOARD_MENU_SLUG,
                [$this, 'fpAdminDashboardView'],
                WP_PLUGIN_DIR . '/flare-press/includes/dist/images/fp_dashboard_icon.svg',
                5
        );
    }

    private function addLogPage(): void
    {
        add_submenu_page(
                Constants::DASHBOARD_MENU_SLUG,
                Utils::localize(Constants::UI_LOG_PAGE_TITLE),
                Utils::localize(Constants::LOG_MENU_TITLE),
                'manage_options',
                Constants::DASHBOARD_LOG_PAGE_SLUG,
                [$this, 'fpAdminLogView'],
                5
        );
    }

    public function fpAdminDashboardView(): void
    {
        Utils::renderTemplate(Constants::DASHBOARD_VIEW);
    }

    public function fpAdminLogView(): void
    {
        Utils::renderTemplate(Constants::LOG_VIEW);
    }

    private function addApiSettingsSection(): void
    {
        add_settings_section(
                Constants::DASHBOARD_API_SETTINGS_SECTION_ID,
                Utils::localize(Constants::UI_API_SETTINGS_SECTION_TITLE),
                '',
                Constants::DASHBOARD_MENU_SLUG
        );
    }

    private function addVariantSettingsSection(): void
    {
        add_settings_section(
                Constants::DASHBOARD_VARIANT_SETTINGS_SECTION_ID,
                Utils::localize(Constants::UI_VARIANT_SETTINGS_SECTION_TITLE),
                '',
                Constants::DASHBOARD_MENU_SLUG
        );
    }

    private function addUploadSettingsSection(): void
    {
        add_settings_section(
                Constants::DASHBOARD_UPLOAD_SETTINGS_SECTION_ID,
                Utils::localize(Constants::UI_UPLOAD_SETTINGS_SECTION_TITLE),
                '',
                Constants::DASHBOARD_MENU_SLUG
        );
    }

    private function addLogViewerSection(): void
    {
        add_settings_section(
                Constants::LOG_VIEWER_SECTION_ID,
                Utils::localize(Constants::UI_LOG_VIEWER_SECTION_TITLE),
                null,
                Constants::DASHBOARD_LOG_PAGE_SLUG
        );
    }

    private function renderLogViewer(): void
    {
        ?>
        <label>
            <textarea
                    class="fp-log-viewer-textarea"
                    id="<?php echo Constants::LOG_VIEWER_FIELD_NAME ?>"
                    name="<?php echo Constants::LOG_VIEWER_FIELD_NAME ?>"
                    rows="20"
                    disabled
            ><?php echo trim(Logger::getLogFile()) ?></textarea>
        </label>
        <p class="description"></p>
        <?php
    }

    private function registerLogViewer(): void
    {
        register_setting(Constants::LOG_SETTINGS_GROUP_NAME, Constants::LOG_VIEWER_FIELD_NAME);

        add_settings_field(
                Constants::LOG_VIEWER_FIELD_NAME,
                Utils::localize(Constants::UI_LOGS_FIELD_LABEL),
                function () {
                    $this->renderLogViewer();
                },
                Constants::DASHBOARD_LOG_PAGE_SLUG,
                Constants::LOG_VIEWER_SECTION_ID,
        );
    }

    private function registerAccountIDField(): void
    {
        register_setting(
                Constants::DASHBOARD_SETTINGS_GROUP_NAME,
                Constants::DASHBOARD_CF_ACCOUNT_ID_FIELD_NAME,
                [
                        'sanitize_callback' => 'sanitize_text_field',
                        'default' => ''
                ]
        );

        add_settings_field(
                Constants::DASHBOARD_CF_ACCOUNT_ID_FIELD_NAME,
                Utils::localize(Constants::UI_CF_ACCOUNT_ID_FIELD_LABEL),
                function () {
                    $this->renderTextField(Constants::DASHBOARD_CF_ACCOUNT_ID_FIELD_NAME);
                },
                Constants::DASHBOARD_MENU_SLUG,
                Constants::DASHBOARD_API_SETTINGS_SECTION_ID,
        );
    }

    private function registerAccountHashField(): void
    {
        register_setting(
                Constants::DASHBOARD_SETTINGS_GROUP_NAME,
                Constants::DASHBOARD_CF_ACCOUNT_HASH_FIELD_NAME,
                [
                        'sanitize_callback' => 'sanitize_text_field',
                        'default' => ''
                ]
        );

        add_settings_field(
                Constants::DASHBOARD_CF_ACCOUNT_HASH_FIELD_NAME,
                Utils::localize(Constants::UI_CF_ACCOUNT_HASH_FIELD_LABEL),
                function () {
                    $this->renderTextField(Constants::DASHBOARD_CF_ACCOUNT_HASH_FIELD_NAME, Utils::localize(Constants::UI_CF_ACCOUNT_HASH_DESCRIPTION));
                },
                Constants::DASHBOARD_MENU_SLUG,
                Constants::DASHBOARD_API_SETTINGS_SECTION_ID,
        );
    }

    private function registerApiTokenField(): void
    {
        register_setting(
                Constants::DASHBOARD_SETTINGS_GROUP_NAME,
                Constants::DASHBOARD_CF_API_TOKEN_FIELD_NAME,
                [
                        'sanitize_callback' => 'sanitize_text_field',
                        'default' => ''
                ]
        );

        add_settings_field(
                Constants::DASHBOARD_CF_API_TOKEN_FIELD_NAME,
                Utils::localize(Constants::UI_CF_API_TOKEN_FIELD_LABEL),
                function () {
                    $this->renderApiTokenField();
                },
                Constants::DASHBOARD_MENU_SLUG,
                Constants::DASHBOARD_API_SETTINGS_SECTION_ID,
        );
    }

    private function registerVariantListField(): void
    {
        $variantNames = $this->getVariantNamesAsArray();

        register_setting(
                Constants::DASHBOARD_VARIANT_SETTINGS_GROUP_NAME,
                Constants::DASHBOARD_VARIANT_LIST_FIELD_NAME,
                [
                        'sanitize_callback' => function ($value) use ($variantNames) {
                            $sanitized = sanitize_text_field($value);

                            if (in_array($sanitized, $variantNames, true)) {
                                return $sanitized;
                            }

                            return $variantNames[0];
                        },
                        'default' => $variantNames[0],
                ]
        );

        add_settings_field(
                Constants::DASHBOARD_VARIANT_LIST_FIELD_NAME,
                Utils::localize(Constants::UI_VARIANTS_FIELD_LABEL),
                function () {
                    $this->renderVariantListField();
                },
                Constants::DASHBOARD_MENU_SLUG,
                Constants::DASHBOARD_VARIANT_SETTINGS_SECTION_ID,
        );
    }

    private function renderVariantListField(): void
    {
        $variantsArray = $this->getVariantNamesAsArray();

        if (empty($variantsArray)) {
            $variantsArray = array_keys(self::syncVariants());
        }

        ?>
        <div id="fp_variant_list_field" class="fp-variant-list-field">
            <?php
            if (!empty($variantsArray)) {
                foreach ($variantsArray as $variant) {
                    ?>
                    <code><?php echo $variant ?></code>
                    <?php
                }
            } else {
                ?> <p><?php echo esc_html(Utils::localize(Constants::UI_NO_VARIANTS_SYNCED)); ?></p><?php
            }
            ?>

        </div>
        <div class="fp-sync-button-and-spinner">
            <button id="fp_variant_sync_button" type="button" role="button"
                    class="fp-variant-sync-button button button-secondary">
                <span class="dashicons dashicons-update-alt"></span>
                <?php echo esc_html(Utils::localize(Constants::UI_SYNC_VARIANTS_BUTTON)); ?>
            </button>
            <span id="fp_sync_variant_spinner" class="spinner"></span>
        </div>
        <?php
    }

    private function registerDefaultVariantField(): void
    {
        register_setting(
                Constants::DASHBOARD_SETTINGS_GROUP_NAME,
                Constants::DASHBOARD_DEFAULT_VARIANT_FIELD_NAME,
                array(
                        'type' => 'string',
                        'sanitize_callback' => function ($input) {
                            return $this->sanitizeDefaultVariantField($input);
                        },
                        'default' => $this->getVariantNamesAsArray()[0]
                )
        );

        add_settings_field(
                Constants::DASHBOARD_DEFAULT_VARIANT_FIELD_NAME,
                Utils::localize(Constants::UI_DEFAULT_VARIANT_FIELD_LABEL),
                function () {
                    $this->renderDefaultVariantField();
                },
                Constants::DASHBOARD_MENU_SLUG,
                Constants::DASHBOARD_VARIANT_SETTINGS_SECTION_ID,
        );
    }

    private function renderDefaultVariantField(): void
    {
        $currentValue = get_option(Constants::DASHBOARD_DEFAULT_VARIANT_FIELD_NAME, $this->getVariantNamesAsArray()[0]);
        $options = $this->getVariantNamesAsArray();

        ?>
        <select name="<?php echo Constants::DASHBOARD_DEFAULT_VARIANT_FIELD_NAME ?>"
                id="<?php echo Constants::DASHBOARD_DEFAULT_VARIANT_FIELD_NAME ?>">
            <?php foreach ($options as $option) : ?>
                <option value="<?php echo esc_attr($option); ?>" <?php selected($currentValue, $option); ?>>
                    <?php echo esc_html($option); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description"><?php echo Utils::localize(Constants::UI_DEFAULT_VARIANT_DESCRIPTION); ?></p>
        <?php
    }

    private function sanitizeDefaultVariantField($input): string
    {
        $allowedValues = $this->getVariantNamesAsArray();

        if (in_array($input, $allowedValues, true)) {
            return $input;
        }

        return $allowedValues[0];
    }

    private function registerFileManagementField(): void
    {
        register_setting(
                Constants::DASHBOARD_SETTINGS_GROUP_NAME,
                Constants::DASHBOARD_UPLOAD_SETTINGS_NAME,
                [
                        'type' => 'array',
                        'sanitize_callback' => function ($input) {
                            $sanitized = [];
                            $sanitized[Constants::DASHBOARD_KEEP_AFTER_UPLOAD_FIELD_NAME] = isset($input[Constants::DASHBOARD_KEEP_AFTER_UPLOAD_FIELD_NAME]) ? 1 : 0;
                            $sanitized[Constants::DASHBOARD_KEEP_ON_CF_AFTER_DELETE_FIELD_NAME] = isset($input[Constants::DASHBOARD_KEEP_ON_CF_AFTER_DELETE_FIELD_NAME]) ? 1 : 0;
                            return $sanitized;
                        }
                ]
        );

        add_settings_field(
                Constants::DASHBOARD_FILE_MANAGEMENT_FIELD_NAME,
                Utils::localize(Constants::UI_FILE_MANAGEMENT_FIELD_TITLE),
                [$this, 'renderFileManagementCheckboxFields'],
                Constants::DASHBOARD_MENU_SLUG,
                Constants::DASHBOARD_UPLOAD_SETTINGS_SECTION_ID,
        );
    }

    private function renderApiTokenField(): void
    {
        $optionVal = trim(esc_attr(get_option(Constants::DASHBOARD_CF_API_TOKEN_FIELD_NAME)));
        ?>
        <label class="fp-api-token-field-label">
            <input
                    type="password"
                    value="<?php echo !empty($optionVal) ? '••••••••••••••••••••••' : '' ?>"
                    data-field-name="<?php echo Constants::DASHBOARD_CF_API_TOKEN_FIELD_NAME ?>"
                    <?php echo empty($optionVal) ? 'name="' . Constants::DASHBOARD_CF_API_TOKEN_FIELD_NAME . '"' : '' ?>
                    <?php echo empty($optionVal) ? 'id="' . Constants::DASHBOARD_CF_API_TOKEN_FIELD_NAME . '"' : '' ?>
                    <?php echo !empty($optionVal) ? 'disabled' : '' ?>
                    <?php echo empty($optionVal) ? 'required' : '' ?>
                    class="regular-text"/>

            <?php
            if (!empty($optionVal)) {
                ?>
                <button id="fp_change_api_token_button" class="button button-secondary fp-change-api-token-button"
                        type="button" role="button">
                    <span class="dashicons dashicons-edit"></span>
                </button>
                <?php
            }
            ?>
        </label>
        <?php if (!empty($optionVal)) { ?>
        <div class="fp-test-connection-wrap" style="margin-top:8px;">
            <button id="fp_test_connection_button" class="button button-secondary" type="button">
                <?php echo Utils::localize(Constants::UI_TEST_CONNECTION_BUTTON); ?>
            </button>
            <span id="fp_test_connection_result" style="margin-left:8px;"></span>
        </div>
        <?php } ?>
        <p class="description"><?php echo Utils::localize(Constants::UI_CF_API_TOKEN_DESCRIPTION); ?></p>
        <?php
    }

    private function renderTextField(string $optionName, string $description = ''): void
    {
        $optionVal = trim(esc_attr(get_option($optionName)));

        ?>
        <label>
            <input
                    value="<?php echo $optionVal ?>"
                    name="<?php echo $optionName ?>"
                    id="<?php echo $optionName ?>"
                    type="text"
                    class="regular-text"/>
        </label>
        <?php
        if (!empty($description)) {
            ?>
            <p class="description"><?php echo $description ?></p>
            <?php
        }
    }

    public function renderFileManagementCheckboxFields(): void
    {
        $options = get_option(Constants::DASHBOARD_UPLOAD_SETTINGS_NAME, []);
        ?>
        <fieldset>
            <label>
                <input type="checkbox"
                       name="<?php echo Constants::DASHBOARD_UPLOAD_SETTINGS_NAME ?>[<?php echo Constants::DASHBOARD_KEEP_AFTER_UPLOAD_FIELD_NAME ?>]"
                       value="1"
                        <?php checked(!empty($options[Constants::DASHBOARD_KEEP_AFTER_UPLOAD_FIELD_NAME])); ?> />
                <?php echo Utils::localize(Constants::UI_KEEP_FILES_AFTER_UPLOAD_FIELD_LABEL); ?>
            </label>
            <p class="description"><?php echo Utils::localize(Constants::UI_KEEP_FILES_AFTER_UPLOAD_DESCRIPTION); ?></p>
            <br/>
            <label>
                <input type="checkbox"
                       name="<?php echo Constants::DASHBOARD_UPLOAD_SETTINGS_NAME ?>[<?php echo Constants::DASHBOARD_KEEP_ON_CF_AFTER_DELETE_FIELD_NAME ?>]"
                       value="1"
                        <?php checked(!empty($options[Constants::DASHBOARD_KEEP_ON_CF_AFTER_DELETE_FIELD_NAME])); ?> />
                <?php echo Utils::localize(Constants::UI_KEEP_FILES_ON_CF_AFTER_DELETE_FIELD_LABEL); ?>
            </label>
            <p class="description"><?php echo Utils::localize(Constants::UI_KEEP_ON_CF_AFTER_DELETE_DESCRIPTION); ?></p>
        </fieldset>

        <?php
    }

    /**
     * Place a Cloudflare logo to the end of each row that is Cloudflare image
     * in page: /wp-admin/upload.php?mode=list
     *
     * @param string $columnName
     * @param $attachmentId
     *
     * @return void
     */
    public static function addLocationInfoToListViewRow(string $columnName, $attachmentId): void
    {
        if ($columnName === Constants::DASHBOARD_CF_LIST_VIEW_COLUMN_ID && AttachmentController::getCloudflareIdOfAttachment($attachmentId)) {
            echo '<span 
                data-fp-file-name="' . AttachmentController::getAttachmentFileName($attachmentId) . '"
                data-fp-url="' . get_the_guid($attachmentId) . '"
                >
                <img title="' . Utils::localize(Constants::UI_CF_BADGE_TITLE) . '" alt="Cloudflare logo" height="18" src="' . esc_url(FLARE_PRESS_URL . 'includes/assets/images/cf_logo.png') . '"></span>';
        } else {
            echo Utils::localize(Constants::UI_CF_LOCATION_THIS_SERVER);
        }
    }

    /**
     * Get variants recorded in db by plugin options page.
     *
     * These variants are not directly from Cloudflare API itself.
     * They are of course retrieved from Cloudflare API. But manually.
     * So each time this function called. The variants you'll get are
     * the variants that are manually synced recently to WordPress DB by user
     * in plugin options page.
     *
     * @return array
     */
    public static function getVariants(): array
    {
        $variantsAsEncodedString = get_option(Constants::DASHBOARD_VARIANT_LIST_FIELD_NAME);

        if (empty($variantsAsEncodedString)) {
            return [];
        }

        $variantsArray = json_decode($variantsAsEncodedString, true);

        if (JSON_ERROR_NONE !== json_last_error()) {
            Logger::log(0, '[OPTIONS] Error retrieving variants options: ' . json_last_error_msg());

            return [];
        }

        return $variantsArray;
    }

    /**
     * Get all variant names as array from variantNames property.
     *
     * @return array
     */
    private function getVariantNamesAsArray(): array
    {
        if (empty($this->variantNames)) {
            $this->variantNames = self::getVariantNames();
        }

        return $this->variantNames;
    }

    /**
     * Get all variant names as array.
     *
     * @return array
     */
    public static function getVariantNames(): array
    {
        $variantNamesArray = array_keys(self::getVariants());

        asort($variantNamesArray);

        return $variantNamesArray;
    }

    /**
     * Sync variants from Cloudflare to WordPress db.
     *
     * @return array
     */
    public static function syncVariants(): array
    {
        try {
            $variantsFromCloudflare = CloudflareImagesApi::getVariants();
            $jsonEncodedVariants = json_encode($variantsFromCloudflare);

            if (JSON_ERROR_NONE !== json_last_error()) {
                throw new Exception(json_last_error_msg());
            }

            update_option(Constants::DASHBOARD_VARIANT_LIST_FIELD_NAME, $jsonEncodedVariants);
        } catch (Exception $e) {
            Logger::log(0, '[OPTIONS] Unable to update variants option: ' . $e->getMessage());

            return [];
        }

        return $variantsFromCloudflare;
    }

    /**
     * Get account hash from plugin options.
     *
     * @return string
     */
    public static function getAccountHash(): string
    {
        return get_option(Constants::DASHBOARD_CF_ACCOUNT_HASH_FIELD_NAME) ?? '';
    }
}