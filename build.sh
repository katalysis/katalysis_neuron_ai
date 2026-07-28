#!/bin/bash

# Katalysis Neuron AI Package Build Script
# This script updates the bundled vendor dependencies

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  Katalysis Neuron AI Package - Build Script"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""

# Check if composer is available
if ! command -v composer &> /dev/null; then
    echo "❌ Error: Composer is not installed or not in PATH"
    exit 1
fi

# Check if composer.build.json exists
if [ ! -f "composer.build.json" ]; then
    echo "❌ Error: composer.build.json not found"
    exit 1
fi

echo "📦 Installing bundled dependencies using composer.build.json..."
echo ""

# Backup composer.json and use build version
if [ -f "composer.json" ]; then
    mv composer.json composer.json.dist
fi

cp composer.build.json composer.json

# Install dependencies
composer install --no-dev --optimize-autoloader --prefer-dist --no-interaction

# Restore original composer.json
rm composer.json
if [ -f "composer.json.dist" ]; then
    mv composer.json.dist composer.json
fi

echo ""
echo "✅ Build complete!"
echo ""
echo "📁 Bundled dependencies are now in vendor/ directory"
echo "📝 Make sure to commit the vendor/ directory to git"
echo ""
echo "ℹ️  Note: The main composer.json does NOT include runtime dependencies"
echo "         to prevent parent projects from installing them separately."
echo ""
