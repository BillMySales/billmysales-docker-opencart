<?php
/**
 * Small database helpers for setup.sh.
 *
 *   php db.php installed   # prints 1 if OpenCart's tables exist
 */

mysqli_report(MYSQLI_REPORT_OFF);
$db = @new mysqli(getenv('DB_HOST'), getenv('DB_USER'), getenv('DB_PASSWORD'), getenv('DB_NAME'), (int) getenv('DB_PORT'));
if ($db->connect_errno) {
    fwrite(STDERR, "Database connection failed: {$db->connect_error}\n");
    exit(1);
}
if (($argv[1] ?? '') === 'installed') {
    $result = $db->query("SHOW TABLES LIKE 'oc_setting'");
    echo $result && $result->num_rows ? '1' : '';
}
