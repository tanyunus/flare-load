<?php

namespace FP\Utils;

class AdminPage
{
    public static function is(string $pageSlug): bool {
        global $pagenow;

        $currentAdminPage = basename(admin_url($pagenow));

        return $currentAdminPage === $pageSlug;
    }
}