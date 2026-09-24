<?php
/**
 * Applies the stack's environment to OpenCart's settings (oc_setting). Run by
 * setup.sh on every `docker compose up`.
 *
 * - Error display follows OC_DEBUG; SMTP follows SMTP_* when SMTP_HOST is set.
 * - Initial settings (country, zone, currency, timezone, SEO URLs, store name)
 *   are applied once (marker docker_stack_initialized); later changes in the
 *   back office are kept.
 *
 * Exit code: 0 = nothing changed, 3 = something changed (clear the cache).
 */

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli(getenv('DB_HOST'), getenv('DB_USER'), getenv('DB_PASSWORD'), getenv('DB_NAME'), (int) getenv('DB_PORT'));
$db->set_charset('utf8mb4');

$changed = false;
$value = static function (string $key) use ($db): ?string {
    $stmt = $db->prepare("SELECT `value` FROM oc_setting WHERE store_id = 0 AND `key` = ?");
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    return $row ? $row[0] : null;
};
$set = static function (string $key, $new, string $code = 'config') use ($db, $value, &$changed): void {
    $new = (string) $new;
    $current = $value($key);
    if ($current === $new) {
        return;
    }
    if ($current === null) {
        $stmt = $db->prepare("INSERT INTO oc_setting (store_id, `code`, `key`, `value`, serialized) VALUES (0, ?, ?, ?, 0)");
        $stmt->bind_param('sss', $code, $key, $new);
    } else {
        $stmt = $db->prepare("UPDATE oc_setting SET `value` = ? WHERE store_id = 0 AND `key` = ?");
        $stmt->bind_param('ss', $new, $key);
    }
    $stmt->execute();
    echo "    {$key} updated\n";
    $changed = true;
};

// Errors: logged always, displayed only in debug mode.
$set('config_error_display', filter_var(getenv('OC_DEBUG'), FILTER_VALIDATE_BOOLEAN) ? 1 : 0);
$set('config_error_log', 1);

// SMTP. SMTP_SECURE: tls (STARTTLS), ssl (implicit TLS), none or empty.
if (getenv('SMTP_HOST')) {
    $secure = strtolower((string) getenv('SMTP_SECURE'));
    $prefix = in_array($secure, ['tls', 'ssl'], true) ? $secure . '://' : '';
    $set('config_mail_engine', 'smtp');
    $set('config_mail_smtp_hostname', $prefix . getenv('SMTP_HOST'));
    $set('config_mail_smtp_port', getenv('SMTP_PORT') ?: 587);
    $set('config_mail_smtp_username', (string) getenv('SMTP_USER'));
    $set('config_mail_smtp_password', (string) getenv('SMTP_PASSWORD'));
    $set('config_mail_smtp_timeout', 10);
    if (getenv('SMTP_FROM')) {
        $set('config_email', getenv('SMTP_FROM'));
    }
}

// Initial settings, once.
if ($value('docker_stack_initialized') === null) {
    echo "==> Initial settings\n";
    $set('config_name', getenv('OC_STORE_NAME') ?: 'OpenCart');
    // Page title of the home page, per language (serialized JSON).
    $description = json_decode((string) $value('config_description'), true) ?: [];
    foreach ($description as $languageId => $fields) {
        $description[$languageId]['meta_title'] = getenv('OC_STORE_NAME') ?: 'OpenCart';
    }
    if ($description) {
        $set('config_description', json_encode($description));
    }
    $set('config_timezone', getenv('PHP_TIMEZONE') ?: 'UTC');
    $set('config_seo_url', 1);

    $country = $db->execute_query(
        'SELECT country_id FROM oc_country WHERE iso_code_2 = ?',
        [strtoupper(getenv('OC_COUNTRY') ?: 'CL')]
    )->fetch_row();
    if ($country) {
        $set('config_country_id', $country[0]);
        $zone = $db->execute_query(
            'SELECT zone_id FROM oc_zone WHERE country_id = ? AND status = 1 ORDER BY (code = ?) DESC, zone_id LIMIT 1',
            [$country[0], getenv('OC_ZONE') ?: 'RM']
        )->fetch_row();
        if ($zone) {
            $set('config_zone_id', $zone[0]);
        }
    }

    // Default currency: create it if missing and make it the only enabled one
    // (the sample rates of the other currencies are relative to USD).
    $currency = strtoupper(getenv('OC_CURRENCY') ?: 'CLP');
    $exists = $db->execute_query('SELECT currency_id FROM oc_currency WHERE code = ?', [$currency])->fetch_row();
    if (!$exists) {
        $db->execute_query(
            "INSERT INTO oc_currency (title, code, symbol_left, symbol_right, decimal_place, `value`, status, date_modified)
             VALUES (?, ?, '$', '', 0, 1, 1, NOW())",
            [getenv('OC_CURRENCY_TITLE') ?: $currency, $currency]
        );
    }
    $db->execute_query('UPDATE oc_currency SET status = (code = ?), `value` = IF(code = ?, 1, `value`)', [$currency, $currency]);
    $set('config_currency', $currency);
    $set('config_currency_auto', 0);

    $set('docker_stack_initialized', gmdate('Y-m-d\TH:i:s\Z'), 'docker_stack');
}

exit($changed ? 3 : 0);
