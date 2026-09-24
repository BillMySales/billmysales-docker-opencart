#!/bin/sh
# Installs OpenCart and applies the stack's environment.
#
# Runs as root on every `docker compose up` and is safe to repeat:
# - Copies OpenCart from the image to the code volume if it is empty.
# - Installs the store with OpenCart's CLI installer if the database is empty,
#   then removes the installer and moves storage outside the web root.
# - Renames the back office directory to OC_ADMIN_DIR.
# - Writes config.php and <admin>/config.php from the environment
#   (scripts/conf.php) and applies settings (scripts/configure.php).
set -eu

CODE=/var/www/html
STORAGE=/var/www/storage
STACK=/usr/local/share/stack/scripts
cd "${CODE}"

as_www() { su-exec www-data "$@"; }
changed=0

# Back office directory: the one with the admin dashboard controller.
find_admin_dir() {
    for dir in */; do
        if [ -f "${dir}controller/common/dashboard.php" ]; then
            echo "${dir%/}"
            return
        fi
    done
}

# The mount points may be root-owned (e.g. new bind mounts).
chown www-data:www-data "${CODE}" "${STORAGE}"

if [ ! -f index.php ]; then
    echo "==> Copying OpenCart ${OC_VERSION} files"
    cp -a /usr/src/opencart/. "${CODE}/"
fi

if [ -z "$(as_www php "${STACK}/db.php" installed)" ]; then
    echo "==> Installing OpenCart ${OC_VERSION} at ${OC_URL}"
    if [ ! -d install ]; then
        cp -a /usr/src/opencart/install "${CODE}/"
    fi
    # The installer needs writable config files and storage inside system/.
    admin_dir="$(find_admin_dir)"
    for dir in . "${admin_dir}"; do
        cp "${dir}/config-dist.php" "${dir}/config.php"
        chown www-data:www-data "${dir}/config.php"
    done
    if [ ! -d system/storage ]; then
        cp -a /usr/src/opencart/system/storage system/storage
    fi
    output="$(as_www php install/cli_install.php install \
        --username "${OC_ADMIN_USER}" --password "${OC_ADMIN_PASSWORD}" \
        --email "${OC_ADMIN_EMAIL}" --http_server "${OC_URL%/}/" \
        --db_driver mysqli --db_hostname "${DB_HOST}" --db_port "${DB_PORT}" \
        --db_username "${DB_USER}" --db_password "${DB_PASSWORD}" \
        --db_database "${DB_NAME}" --db_prefix oc_ 2>&1)" || true
    if [ -z "$(as_www php "${STACK}/db.php" installed)" ]; then
        echo "Install failed, installer output:" >&2
        echo "${output}" | tail -30 >&2
        exit 1
    fi
    changed=1
fi
if [ -d install ]; then
    rm -rf install
fi

# Storage outside the web root.
if [ -d system/storage ]; then
    if [ -z "$(ls -A "${STORAGE}")" ]; then
        echo "==> Moving storage outside the web root"
        cp -a system/storage/. "${STORAGE}/"
    fi
    rm -rf system/storage
fi

current_admin="$(find_admin_dir)"
if [ -n "${current_admin}" ] && [ "${current_admin}" != "${OC_ADMIN_DIR}" ]; then
    echo "==> Renaming back office directory ${current_admin} -> ${OC_ADMIN_DIR}"
    mv "${current_admin}" "${OC_ADMIN_DIR}"
    changed=1
fi

echo "==> Applying environment (config.php, settings)"
before="$(cat config.php "${OC_ADMIN_DIR}/config.php" 2>/dev/null | cksum)"
php "${STACK}/conf.php" "${CODE}" "${OC_ADMIN_DIR}"
chown www-data:www-data config.php "${OC_ADMIN_DIR}/config.php"
if [ "${before}" != "$(cat config.php "${OC_ADMIN_DIR}/config.php" | cksum)" ]; then
    changed=1
fi
set +e
as_www php "${STACK}/configure.php"
status=$?
set -e
case "${status}" in
    0) ;;
    3) changed=1 ;;
    *) exit "${status}" ;;
esac

if [ "${changed}" = 1 ]; then
    echo "==> Clearing cache"
    find "${STORAGE}/cache" -mindepth 1 ! -name index.html -exec rm -rf {} + 2>/dev/null || true
fi

echo "==> Done: OpenCart ${OC_VERSION}"
echo "    Store: ${OC_URL}"
echo "    Admin: ${OC_URL%/}/${OC_ADMIN_DIR}/ (${OC_ADMIN_USER})"
