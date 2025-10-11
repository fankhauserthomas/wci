#!/bin/bash
# Watch PHP errors in real-time

echo "🔍 Watching for PHP errors..."
echo "Press Ctrl+C to stop"
echo ""

# Try different log locations
if [ -f /var/log/php-fpm/error.log ]; then
    tail -f /var/log/php-fpm/error.log | grep --line-buffered "hrs_write_quota"
elif [ -f /var/log/php/error.log ]; then
    tail -f /var/log/php/error.log | grep --line-buffered "hrs_write_quota"
elif [ -f /var/log/nginx/error.log ]; then
    tail -f /var/log/nginx/error.log | grep --line-buffered "hrs_write_quota"
else
    echo "❌ Error log not found. Trying journalctl..."
    journalctl -f | grep --line-buffered "hrs_write_quota"
fi
