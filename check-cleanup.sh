#!/bin/bash

# Katalysis Neuron AI - Parent Project Cleanup Verification
# Run this from the parent project root

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  Katalysis Neuron AI - Cleanup Verification"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""

ERRORS=0
WARNINGS=0

# Check parent composer.lock
echo "📋 Checking parent composer.lock..."
if grep -q "neuron-core" composer.lock 2>/dev/null; then
    echo "   ⚠️  Warning: neuron-core still in composer.lock"
    echo "      (This is OK if another package needs it)"
    WARNINGS=$((WARNINGS + 1))
else
    echo "   ✅ Parent composer.lock is clean"
fi

# Check parent vendor directory
echo ""
echo "📦 Checking parent vendor directory..."
if [ -d "vendor/neuron-core" ]; then
    echo "   ❌ neuron-core found in parent vendor/ (should not be there)"
    ERRORS=$((ERRORS + 1))
else
    echo "   ✅ Parent vendor is clean"
fi

# Check package bundled vendor
echo ""
echo "📦 Checking package bundled vendor..."
PACKAGE_VENDOR="httpdocs/packages/katalysis_neuron_ai/vendor/neuron-core/neuron-ai"
if [ -d "$PACKAGE_VENDOR" ]; then
    echo "   ✅ Package has bundled neuron-ai"
    
    # Check if autoloader exists
    if [ -f "httpdocs/packages/katalysis_neuron_ai/vendor/autoload.php" ]; then
        echo "   ✅ Package autoloader exists"
    else
        echo "   ❌ Package autoloader missing"
        ERRORS=$((ERRORS + 1))
    fi
else
    echo "   ❌ Package missing bundled neuron-ai"
    echo "      Run: cd httpdocs/packages/katalysis_neuron_ai && ./build.sh"
    ERRORS=$((ERRORS + 1))
fi

# Check package composer.json
echo ""
echo "⚙️  Checking package composer.json..."
PKG_COMPOSER="httpdocs/packages/katalysis_neuron_ai/composer.json"
if [ -f "$PKG_COMPOSER" ]; then
    if grep -q '"neuron-core/neuron-ai"' "$PKG_COMPOSER" | grep -v "bundled-dependencies"; then
        echo "   ❌ Package composer.json should not have neuron-ai in require"
        ERRORS=$((ERRORS + 1))
    else
        echo "   ✅ Package composer.json is correct"
    fi
else
    echo "   ⚠️  Warning: Cannot find package composer.json"
    WARNINGS=$((WARNINGS + 1))
fi

# Summary
echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  Summary"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""
echo "Errors:   $ERRORS"
echo "Warnings: $WARNINGS"
echo ""

if [ $ERRORS -eq 0 ] && [ $WARNINGS -eq 0 ]; then
    echo "✅ All checks passed! Setup is correct."
    exit 0
elif [ $ERRORS -eq 0 ]; then
    echo "⚠️  Setup is functional but has warnings."
    echo "   See CLEANUP.md for details."
    exit 0
else
    echo "❌ Setup has errors that need fixing."
    echo "   See CLEANUP.md for troubleshooting."
    exit 1
fi
