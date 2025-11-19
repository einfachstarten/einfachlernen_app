#!/bin/bash
# 🧪 Beta Feature: Last-Minute Checker Wrapper (FÜR HOSTER OHNE CLI-PDO)
# This script is designed to be called by cron

# Set timezone
export TZ="Europe/Vienna"

# Try to find PHP with PDO support
PHP_BINARIES=(
    "/usr/bin/php"
    "/usr/local/bin/php"
    "/usr/bin/php8.1"
    "/usr/bin/php8.2"
    "/usr/bin/php8.3"
    "/opt/php81/bin/php"
    "/opt/php82/bin/php"
)

PHP_FOUND=""
for PHP_BIN in "${PHP_BINARIES[@]}"; do
    if [ -x "$PHP_BIN" ]; then
        # Check if this PHP has PDO
        if $PHP_BIN -m 2>/dev/null | grep -q "^PDO$"; then
            PHP_FOUND="$PHP_BIN"
            break
        fi
    fi
done

# Change to script directory
cd "$(dirname "$0")/.."

if [ -n "$PHP_FOUND" ]; then
    echo "Using PHP: $PHP_FOUND"
    $PHP_FOUND admin/last_minute_checker.php
    exit $?
else
    echo "❌ No PHP with PDO found. Using HTTP fallback..."

    # Fallback: Call via HTTP (works because web-PHP has PDO)
    # Replace with your actual domain
    DOMAIN="einfachstarten.jetzt"
    BASE_PATH="/einfachlernen"

    # Try curl first, then wget
    if command -v curl &> /dev/null; then
        curl -s "https://${DOMAIN}${BASE_PATH}/admin/last_minute_checker_cron.php" 2>&1
    elif command -v wget &> /dev/null; then
        wget -q -O - "https://${DOMAIN}${BASE_PATH}/admin/last_minute_checker_cron.php" 2>&1
    else
        echo "❌ Neither curl nor wget available. Cannot run checker."
        exit 1
    fi
fi
