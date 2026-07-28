# Cleanup Stale Packages

## Issue

The bundled vendor directory contains a **stale package** that's inflating the size:

- `vendor/katalysis/neuron-ai`: **12 MB** (OLD - should not be there)
- `vendor/neuron-core/neuron-ai`: 2.5 MB (CORRECT - should be there)

The `katalysis/neuron-ai` package is:
- Not in `composer.json` or `composer.build.json`
- Not in `composer.lock`
- Leftover from an old installation or manual copy
- Contains 9.1 MB of documentation files

## Impact

- Current vendor size: **16 MB**
- After cleanup: **~4 MB**
- **Savings: 12 MB (75% reduction!)**

## Solution

### Option 1: Rebuild (Recommended)

Clean rebuild will automatically remove stale packages:

```bash
cd httpdocs/packages/katalysis_neuron_ai

# Remove entire vendor directory
rm -rf vendor/

# Rebuild from composer.build.json
./build.sh
```

### Option 2: Manual Removal

If you want to selectively remove just the stale package:

```bash
cd httpdocs/packages/katalysis_neuron_ai

# Remove stale package
rm -rf vendor/katalysis/

# Verify removal
du -sh vendor/
# Should now show ~4 MB instead of 16 MB
```

### Option 3: Verify First (Safest)

Check dependencies before removing:

```bash
cd httpdocs/packages/katalysis_neuron_ai

# Check what requires katalysis/neuron-ai (should be nothing)
grep -r "katalysis/neuron-ai" composer.json composer.build.json composer.lock

# If no results, safe to remove
rm -rf vendor/katalysis/
```

## Verification

After cleanup, verify the package still works:

```bash
# Check vendor size
du -sh vendor/
# Expected: ~4 MB

# Check neuron-core is still there
ls -la vendor/neuron-core/neuron-ai/
# Should exist

# Check katalysis is gone
ls -la vendor/katalysis/ 2>&1
# Should show "No such file or directory"

# Test package
cd ../../../
php concrete/bin/concrete5 c5:package:update katalysis_neuron_ai
```

## How Did This Happen?

Possible causes:
1. **Old dependency**: An older version of the package required `katalysis/neuron-ai`
2. **Manual copy**: Someone manually copied files into vendor/
3. **Composer cache**: Old cached dependency was copied over
4. **Fork confusion**: `katalysis/neuron-ai` might be a fork of `neuron-core/neuron-ai`

## Prevention

To prevent stale packages in the future:

1. **Always use `./build.sh`** instead of manual `composer install`
2. **Commit clean vendor**: Only commit vendor after a clean build
3. **Git ignore**: Consider adding to `.gitattributes`:
   ```
   vendor/**/docs/ export-ignore
   vendor/**/tests/ export-ignore
   ```

## Impact on Git

After cleanup, commit the changes:

```bash
cd httpdocs/packages/katalysis_neuron_ai

# Check what will be removed
git status vendor/

# Remove stale package from git
git rm -r vendor/katalysis/

# Commit
git add vendor/
git commit -m "Remove stale katalysis/neuron-ai package (12 MB cleanup)"
git push
```

## Documentation Size Optimization

Even the correct `neuron-core/neuron-ai` package might have docs/tests. Check:

```bash
cd vendor/neuron-core/neuron-ai
du -sh */
```

If it has large docs/tests directories, you can exclude them in the build:

### Create `.gitattributes` in package root:

```gitattributes
# Exclude docs and tests from vendor packages
vendor/*/docs/ export-ignore
vendor/*/tests/ export-ignore
vendor/*/*/docs/ export-ignore
vendor/*/*/tests/ export-ignore
```

### Or use `--no-dev` in build (already doing this):

```bash
composer install --no-dev  # This excludes require-dev packages but not docs
```

## Summary

**Action Required:** Run `./build.sh` to clean rebuild the vendor directory.

**Expected Result:** Vendor size reduced from 16 MB to ~4 MB.

**Risk:** None if done correctly - `composer.lock` will ensure correct versions are installed.

**Timeline:** 2-3 minutes for the rebuild.
