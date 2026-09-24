<?php
/**
 * Loaded before cron.php (auto_prepend_file in scripts/cron.sh).
 *
 * OpenCart 4.1.0.4's cron.php doesn't load the Composer autoloader from
 * storage/vendor (system/framework.php does), so every job fails with
 * 'Class "Twig\Loader\FilesystemLoader" not found'. Loading it here fixes the
 * cron without changing OpenCart's files.
 */

if (is_file('/var/www/storage/vendor/autoload.php')) {
    require_once '/var/www/storage/vendor/autoload.php';
}
