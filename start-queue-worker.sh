#!/bin/bash
# Start Laravel Queue Worker for WhatsApp Messages
# This script should be run continuously to process queued jobs

cd "$(dirname "$0")"

echo "Starting Laravel Queue Worker..."
echo "Press Ctrl+C to stop"
echo ""

php artisan queue:work --tries=3 --timeout=300 --sleep=3

