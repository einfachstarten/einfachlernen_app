#!/bin/bash
# 🧪 Beta Feature: Last-Minute Checker Wrapper
# This script is designed to be called by cron

# Change to script directory
cd "$(dirname "$0")/.."

# Set timezone
export TZ="Europe/Vienna"

# Optional: Set CALENDLY_TOKEN if not set system-wide
# export CALENDLY_TOKEN="your_token_here"

# Run the checker
/usr/bin/php admin/last_minute_checker.php

# Exit with the PHP script's exit code
exit $?
