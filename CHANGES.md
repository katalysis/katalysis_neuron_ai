# Katalysis Neuron AI Package - Changes Summary

## Problem

When installing the `katalysis_neuron_ai` package via Composer in a parent project, Composer was installing the package's dependencies (specifically `neuron-core/neuron-ai`) into the **parent project's vendor directory**, causing:

- Duplicate dependencies
- Version conflicts
- Unnecessary bloat in parent projects
- Dependency management complications

## Root Cause

The package's `composer.json` declared runtime dependencies in the `require` section. When Composer installs a package, it automatically resolves and installs ALL transitive dependencies into the parent project's vendor directory.

## Solution

Implemented a **bundled dependencies architecture**:

1. ✅ Dependencies are pre-installed in the package's `vendor/` directory
2. ✅ The `vendor/` directory is committed to git
3. ✅ The package loads its own vendors via `setupAutoloader()` in controller.php
4. ✅ Main `composer.json` does NOT declare runtime dependencies
5. ✅ Build process managed via `build.sh` and `composer.build.json`

## Changes Made

### 1. Modified `composer.json`

**Before:**
```json
{
    "require": {
        "php": "^8.1",
        "neuron-core/neuron-ai": "^3.15"
    }
}
```

**After:**
```json
{
    "require": {
        "php": "^8.1"
    },
    "extra": {
        "bundled-dependencies": {
            "neuron-core/neuron-ai": "^3.15"
        }
    }
}
```

### 2. Created `composer.build.json`

Build configuration file that includes all dependencies for building the package:

```json
{
    "require": {
        "php": "^8.1",
        "neuron-core/neuron-ai": "^3.15"
    }
}
```

### 3. Created `build.sh`

Automated build script that:
- Temporarily swaps `composer.build.json` → `composer.json`
- Runs `composer install --no-dev`
- Restores the original `composer.json`
- Updates the bundled `vendor/` directory

Usage:
```bash
cd httpdocs/packages/katalysis_neuron_ai
./build.sh
```

### 4. Updated `.gitignore`

Added clarification that `vendor/` must NOT be ignored:

```gitignore
# Do NOT ignore vendor/ - it contains bundled dependencies
# that must be committed with the package
```

### 5. Created `.gitattributes`

Ensures build files are excluded from exports while vendor is included:

```
composer.build.json export-ignore
build.sh export-ignore
BUILD.md export-ignore

# Ensure vendor is included
vendor/ -export-ignore
```

### 6. Created `BUILD.md`

Comprehensive build documentation covering:
- Architecture overview
- Build process
- Development workflow
- Troubleshooting
- CI/CD integration

### 7. Updated `README.md`

- Added note about bundled dependencies
- Linked to BUILD.md for developers
- Clarified that no `composer install` is needed for normal use

### 8. Updated `SETUP.md`

- Removed `composer install` step
- Added reference to BUILD.md
- Simplified installation instructions

## How It Works Now

### For End Users (Installing the Package)

When someone installs the package via Composer:

```bash
composer require katalysis/katalysis_neuron_ai
```

**What happens:**
1. ✅ Package is installed to `httpdocs/packages/katalysis_neuron_ai/`
2. ✅ Bundled `vendor/` directory comes with it
3. ❌ NO additional dependencies installed in parent vendor/
4. ✅ Package uses its own bundled vendors via `setupAutoloader()`

### For Developers (Updating Dependencies)

When updating dependencies:

```bash
# 1. Edit composer.build.json with new version
# 2. Run build script
./build.sh

# 3. Commit updated vendor
git add vendor/ composer.lock composer.build.json
git commit -m "Update neuron-ai to ^3.16"
```

## Verification

To verify the changes are working correctly:

### 1. Check Package composer.json

```bash
cd httpdocs/packages/katalysis_neuron_ai
cat composer.json | grep -A 5 "require"
```

Should show ONLY:
```json
"require": {
    "php": "^8.1"
}
```

### 2. Check Bundled Vendor

```bash
ls -la vendor/neuron-core/
```

Should show the neuron-ai package.

### 3. Test Parent Installation

In a clean parent project:

```bash
# Install package
composer require katalysis/katalysis_neuron_ai

# Check parent vendor - should NOT contain neuron-core
ls vendor/ | grep neuron-core
# (should return nothing)

# Check package vendor - SHOULD contain neuron-core
ls vendor/katalysis/katalysis_neuron_ai/vendor/ | grep neuron-core
# neuron-core
```

### 4. Test Package Functionality

- Install package in Concrete CMS
- Verify chat panel appears
- Send test messages
- Check for errors in logs

## Benefits

1. ✅ **No duplicate dependencies** - Parent projects stay clean
2. ✅ **Version isolation** - Package controls its own dependency versions
3. ✅ **Self-contained** - Package is fully portable
4. ✅ **Predictable** - Same vendor state across all installations
5. ✅ **CI/CD friendly** - Build process is automated

## Migration Path

For existing installations:

1. Pull latest package code with these changes
2. The bundled vendor directory is already included
3. No action needed - package continues working
4. Next `composer update` in parent won't reinstall neuron-core

For developers:

1. Use `./build.sh` instead of `composer install` in package directory
2. Edit `composer.build.json` (not `composer.json`) for dependency changes
3. Always commit `vendor/` after rebuilding

## Files Changed/Created

**Modified:**
- `composer.json` - Removed runtime dependencies
- `.gitignore` - Added vendor clarification
- `README.md` - Added bundling notes
- `SETUP.md` - Simplified installation

**Created:**
- `composer.build.json` - Build configuration
- `build.sh` - Build automation script
- `BUILD.md` - Comprehensive build documentation
- `.gitattributes` - Export configuration
- `CHANGES.md` - This file

## Next Steps

1. ✅ Commit all changes to the package repository
2. ⏳ Test installation in parent project
3. ⏳ Verify no neuron-core in parent vendor/
4. ⏳ Update documentation if needed
5. ⏳ Tag a new release

## Questions?

See [BUILD.md](BUILD.md) for detailed documentation on:
- Build architecture
- Development workflow
- Adding new dependencies
- Troubleshooting
- CI/CD integration
