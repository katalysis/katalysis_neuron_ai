# Parent Project Cleanup Guide

After updating the Katalysis Neuron AI package to use bundled dependencies, you may need to clean up the parent project to remove references to packages that are no longer needed.

## Step 1: Update Parent Project

Since `katalysis_neuron_ai` no longer declares `neuron-core/neuron-ai` as a dependency, update the parent project:

```bash
cd /Users/studio4/Herd/katalysis_epra_theme

# Update composer dependencies
composer update katalysis/katalysis_neuron_ai

# This should remove neuron-core/neuron-ai from composer.lock
# since it's no longer required by any package
```

## Step 2: Verify Cleanup

Check that unwanted packages were removed:

```bash
# Check composer.lock for neuron-core references
grep -n "neuron-core" composer.lock

# If found, it means another package still requires it
# If not found (no output), cleanup was successful
```

## Step 3: Check Vendor Directory

Verify the parent vendor directory doesn't have neuron-core:

```bash
ls -la vendor/ | grep neuron
# Should return nothing

# Verify package has its own bundled vendor
ls -la httpdocs/packages/katalysis_neuron_ai/vendor/neuron-core/
# Should show neuron-ai directory
```

## Step 4: Test Package Functionality  

1. Install or update the package in Concrete CMS:
```bash
cd httpdocs
php concrete/bin/concrete5 c5:package:update katalysis_neuron_ai
```

2. Test the chat panel:
- Open any dashboard page
- Verify chat panel appears
- Send a test message
- Check for errors in logs

## Full Cleanup (Nuclear Option)

If you want to ensure a completely clean state:

```bash
cd /Users/studio4/Herd/katalysis_epra_theme

# Remove vendor and composer.lock
rm -rf vendor/
rm composer.lock

# Reinstall everything fresh
composer install

# The package's bundled vendor will be included automatically
# But neuron-core won't be in the parent vendor/
```

## Expected Results

After cleanup, your project structure should be:

```
katalysis_epra_theme/
├── vendor/                          # Parent vendor (no neuron-core)
│   ├── katalysis/
│   │   └── neuron-ai/              # The SDK package (if used)
│   ├── guzzlehttp/
│   └── ...
├── httpdocs/
│   └── packages/
│       └── katalysis_neuron_ai/
│           ├── vendor/              # Package's bundled vendor
│           │   ├── neuron-core/
│           │   │   └── neuron-ai/  # ✅ Bundled here
│           │   ├── guzzlehttp/
│           │   └── ...
│           └── controller.php       # Loads its own vendor/autoload.php
```

## Verification Commands

Quick verification script:

```bash
#!/bin/bash

echo "Checking parent composer.lock..."
if grep -q "neuron-core" composer.lock; then
    echo "⚠️  Warning: neuron-core still in composer.lock"
    echo "    (This is OK if another package needs it)"
else
    echo "✅ Parent composer.lock is clean"
fi

echo ""
echo "Checking parent vendor directory..."
if [ -d "vendor/neuron-core" ]; then
    echo "❌ neuron-core found in parent vendor/ (should be removed)"
else
    echo "✅ Parent vendor is clean"
fi

echo ""
echo "Checking package bundled vendor..."
if [ -d "httpdocs/packages/katalysis_neuron_ai/vendor/neuron-core/neuron-ai" ]; then
    echo "✅ Package has bundled neuron-ai"
else
    echo "❌ Package missing bundled neuron-ai (run ./build.sh)"
fi
```

Save as `check-cleanup.sh` and run:

```bash
chmod +x check-cleanup.sh
./check-cleanup.sh
```

## Troubleshooting

### Parent still installs neuron-core

Check if another package requires it:

```bash
composer why neuron-core/neuron-ai
```

If output shows `katalysis/katalysis_neuron_ai`, the package wasn't updated properly:
- Verify package's `composer.json` only requires `php: ^8.1`
- Run `composer update katalysis/katalysis_neuron_ai` again

### Package missing bundled vendor

The bundled vendor wasn't committed or copied:

```bash
cd httpdocs/packages/katalysis_neuron_ai
./build.sh
git add vendor/
git commit -m "Add bundled vendor directory"
git push
```

Then update in parent:

```bash
cd /Users/studio4/Herd/katalysis_epra_theme
composer update katalysis/katalysis_neuron_ai
```

### Permission errors

The vendor directory may have wrong permissions:

```bash
cd httpdocs/packages/katalysis_neuron_ai
chmod -R 755 vendor/
```

## Success Checklist

- [ ] Parent `composer.lock` doesn't contain neuron-core (or only from other packages)
- [ ] Parent `vendor/` doesn't contain neuron-core directory
- [ ] Package `vendor/` contains neuron-core/neuron-ai
- [ ] Package works correctly in Concrete CMS
- [ ] Chat panel appears and responds to messages
- [ ] No dependency errors in logs

## Need Help?

If you encounter issues:

1. Check [BUILD.md](BUILD.md) for build instructions
2. Review [CHANGES.md](CHANGES.md) for implementation details
3. Verify package's `composer.json` has no runtime dependencies
4. Ensure `vendor/` is committed in package repository
