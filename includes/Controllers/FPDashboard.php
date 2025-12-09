<?php

namespace FP\Controllers;

use FP\Utils\Template;

class FPDashboard {
    const DASHBOARD_VIEW = 'admin/dashboard';
    public function __construct() {
        add_action( 'admin_menu', [$this, 'fpAdminMenu'] );
    }

    public function fpAdminMenu(): void {

        add_menu_page(
            'FlarePress',     // Page title
            'FlarePress',          // Menu title
            'manage_options',       // Capability required
            'flare-press',     // Menu slug
            [$this, 'fpAdminDashboardView'],     // Function to display content
            'dashicons-cloud-upload', // Icon (optional)
            20                      // Position (optional)
        );
    }

    public function fpAdminDashboardView(): void {
        Template::render(self::DASHBOARD_VIEW);
    }
}