#!/bin/sh
# Runs OpenCart's cron (cron.php: currency rates, GDPR, subscriptions...) every
# CRON_INTERVAL seconds. Each job has its own cycle in System > Cron Jobs.
set -u

HEARTBEAT=/tmp/cron-heartbeat
CODE=/var/www/html

case "${1:-run}" in
    health)
        # Healthy if the loop completed a pass within 3 intervals.
        [ -n "$(find "${HEARTBEAT}" -mmin -"$(( (CRON_INTERVAL * 3 + 59) / 60 ))" 2>/dev/null)" ]
        exit
        ;;
esac

echo "==> Running OpenCart cron every ${CRON_INTERVAL}s"
while :; do
    if [ -f "${CODE}/config.php" ]; then
        # cron-autoload.php works around an OpenCart 4.1.0.4 bug (see the file).
        (cd "${CODE}" && php -d auto_prepend_file=/usr/local/share/stack/scripts/cron-autoload.php \
            cron.php > /tmp/cron-last.log 2>&1) \
            || { echo "Cron failed:" >&2; tail -5 /tmp/cron-last.log >&2; }
    fi
    touch "${HEARTBEAT}"
    sleep "${CRON_INTERVAL}"
done
