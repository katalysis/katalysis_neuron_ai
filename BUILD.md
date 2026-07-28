# Katalysis Neuron AI Package - Build Guide

## Overview

This package bundles all its dependencies (including `neuron-core/neuron-ai`) in the `vendor/` directory to avoid conflicts when installed via Composer in parent projects.

## Why Bundle Dependencies?

When a Composer package declares dependencies in `composer.json`, those dependencies are installed into the **parent project's vendor directory**. For a Concrete CMS package that:

1. Needs specific versions of dependencies
2. Uses its own autoloader 
3. Should be self-contained

...this causes conflicts. Our solution: **bundle all dependencies** and remove them from the main `composer.json`.

## Architecture

```
katalysis_neuron_ai/
├── composer.json           # Production (no runtime dependencies)
├── composer.build.json     # Build config (includes dependencies)
├── composer.lock          # Lock file for builds
├── vendor/                # Bundled dependencies (COMMITTED to git)
│   ├── neuron-core/
│   ├── guzzlehttp/
│   └── ...
├── build.sh              # Build script
└── controller.php        # Loads vendor/autoload.php
```

## Building the Package

### When to Build

Run the build process when:

- Adding or updating dependencies
- Updating `neuron-core/neuron-ai` version
- Setting up for development
- Preparing a release

### Build Process

1. **Edit dependencies** in `composer.build.json`:

```bash
# Update the version constraint
{
    "require": {
        "neuron-core/neuron-ai": "^3.16"  # Update version
    }
}
```

2. **Run the build script**:

```bash
cd httpdocs/packages/katalysis_neuron_ai
./build.sh
```

The script will:
- Temporarily swap `composer.build.json` → `composer.json`
- Run `composer install --no-dev`
- Restore the original `composer.json`
- Update `vendor/` with new dependencies

3. **Commit the updated vendor directory**:

```bash
git add vendor/
git add composer.lock
git commit -m "Update bundled dependencies"
```

## Manual Build (Alternative)

If you prefer manual control:

```bash
cd httpdocs/packages/katalysis_neuron_ai

# Backup current composer.json
cp composer.json composer.json.backup

# Use build config
cp composer.build.json composer.json

# Install dependencies
composer install --no-dev --optimize-autoloader

# Restore production composer.json
mv composer.json.backup composer.json

# Commit vendor/
git add vendor/
git commit -m "Update bundled dependencies"
```

## Verifying the Build

After building, verify:

1. **Dependencies are bundled**:
```bash
ls -la vendor/neuron-core/
```

2. **Autoloader exists**:
```bash
ls -la vendor/autoload.php
```

3. **Package loads correctly**:
- Install/upgrade in Concrete CMS
- Check that chat panel appears
- Verify no dependency errors in logs

## Parent Project Installation

When installed via Composer in a parent project:

```json
{
    "require": {
        "katalysis/katalysis_neuron_ai": "dev-main"
    }
}
```

The parent project will:
- ✅ Install the package to `httpdocs/packages/katalysis_neuron_ai/`
- ✅ Copy the bundled `vendor/` directory
- ❌ NOT install `neuron-core/neuron-ai` separately (because it's not in `require`)

The package loads its **own bundled vendors** via `setupAutoloader()` in `controller.php`.

## Troubleshooting

### "Class not found" errors

The package can't find bundled dependencies:

```bash
# Rebuild vendor directory
cd httpdocs/packages/katalysis_neuron_ai
./build.sh
```

### Parent project installs dependencies anyway

Check that `composer.json` (not `composer.build.json`) has only:

```json
{
    "require": {
        "php": "^8.1"
    }
}
```

### Composer.lock conflicts

The `composer.lock` file is generated from `composer.build.json` during builds and should be committed:

```bash
git add composer.lock
git commit -m "Update lock file after dependency changes"
```

## Development Workflow

### Adding New Dependencies

1. Add to `composer.build.json`:
```json
{
    "require": {
        "vendor/package": "^1.0"
    }
}
```

2. Run build:
```bash
./build.sh
```

3. Commit everything:
```bash
git add composer.build.json composer.lock vendor/
git commit -m "Add vendor/package dependency"
```

### Updating Dependencies

```bash
# Edit composer.build.json with new version constraint
./build.sh
git add composer.build.json composer.lock vendor/
git commit -m "Update neuron-ai to ^3.16"
```

## Best Practices

1. ✅ Always commit `vendor/` directory
2. ✅ Use `composer.build.json` for dependency changes
3. ✅ Never edit `composer.json` require section
4. ✅ Run `./build.sh` after dependency changes
5. ✅ Test package installation in a clean parent project
6. ❌ Don't run `composer install` using main `composer.json`
7. ❌ Don't add runtime dependencies to main `composer.json`

## CI/CD Integration

For automated builds:

```yaml
# .github/workflows/build.yml
- name: Build Package
  run: |
    cd httpdocs/packages/katalysis_neuron_ai
    ./build.sh
    
- name: Commit Vendor
  run: |
    git add vendor/ composer.lock
    git commit -m "CI: Update bundled dependencies" || true
```

## Questions?

- Main composer.json = production (no deps)
- composer.build.json = build config (all deps)
- vendor/ = committed to git
- build.sh = updates vendor/ from build config
