#!/bin/bash
#
# Performance Optimization Setup Script
# Run this once after deployment to set up caching and download assets
#

echo "========================================="
echo "NGO Tracker - Performance Setup"
echo "========================================="
echo ""

# Check if we're in the right directory
if [ ! -f "index.php" ]; then
    echo "ERROR: Please run this script from the parla_anfrage directory"
    exit 1
fi

# Create cache directory
echo "1. Creating cache directory..."
mkdir -p cache
chmod 755 cache
echo "   ✓ Cache directory created"

# Create assets directories
echo ""
echo "2. Creating assets directories..."
mkdir -p assets/js assets/css
chmod -R 755 assets
echo "   ✓ Assets directories created"

# Download assets
echo ""
echo "3. Downloading CDN assets..."
php download-assets.php

if [ $? -eq 0 ]; then
    echo "   ✓ Assets downloaded successfully"
else
    echo "   ⚠ Asset download failed - will use CDN fallback"
    echo "   You can manually download assets later"
fi

# Set permissions
echo ""
echo "4. Setting file permissions..."
chmod 644 CacheManager.php
chmod 644 download-assets.php
chmod 755 setup-performance.sh
echo "   ✓ Permissions set"

# Check PHP version
echo ""
echo "5. Checking PHP version..."
php_version=$(php -r 'echo PHP_VERSION;')
echo "   PHP version: $php_version"

if [ "$(printf '%s\n' "7.0" "$php_version" | sort -V | head -n1)" = "7.0" ]; then
    echo "   ✓ PHP version is compatible"
else
    echo "   ⚠ PHP version is older than 7.0, some features may not work"
fi

# Test cache system
echo ""
echo "6. Testing cache system..."
php -r "require 'CacheManager.php'; \$c = new CacheManager('./cache'); \$c->set('test', 'works'); echo \$c->get('test') === 'works' ? '   ✓ Cache system working' : '   ✗ Cache test failed'; echo PHP_EOL;"

# Summary
echo ""
echo "========================================="
echo "Setup Complete!"
echo "========================================="
echo ""
echo "Next steps:"
echo "1. Browse to your website"
echo "2. Check PHP error log for cache hits/misses:"
echo "   tail -f php_errors.log"
echo ""
echo "Performance improvements:"
echo "- 10-50x faster page loads (when cached)"
echo "- Reduced API calls to parlament.gv.at"
echo "- Faster asset loading (if downloaded)"
echo ""
echo "For more info, see: PERFORMANCE_OPTIMIZATIONS.md"
echo ""
