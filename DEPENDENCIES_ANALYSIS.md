# Katalysis Neuron AI - Dependency Overlap Analysis

## Summary

After reviewing the dependencies of `neuron-core/neuron-ai` and comparing them with what Concrete CMS provides, I found significant overlap. However, there are important considerations before switching to use Concrete CMS's packages.

## Neuron AI Dependencies

The `neuron-core/neuron-ai` package requires:
- `php`: ^8.1
- `guzzlehttp/guzzle`: ^7.0
- `inspector-apm/inspector-php`: ^3.18
- `psr/http-message`: ^1.0|^2.0

## Dependency Overlap Analysis

### Packages Available in Both

| Package | Parent (Concrete CMS) | Package (Bundled) | Status |
|---------|----------------------|-------------------|---------|
| guzzlehttp/guzzle | 7.15.x-dev | 7.15.1 | ✅ Compatible |
| guzzlehttp/promises | 2.5.x-dev | 2.5.1 | ✅ Compatible |
| guzzlehttp/psr7 | 2.13.x-dev | 2.13.0 | ✅ Compatible |
| psr/http-message | dev-master | 2.0 | ⚠️ Version mismatch |
| psr/http-client | dev-master | 1.0.3 | ⚠️ Version mismatch |
| psr/http-factory | 1.1.0 | 1.1.0 | ✅ **Exact match** |
| ralouphie/getallheaders | 3.0.3 | 3.0.3 | ✅ **Exact match** |
| symfony/deprecation-contracts | dev-main | v3.7.1 | ✅ Compatible |
| symfony/polyfill-php80 | 1.x-dev | v1.37.0 | ✅ Compatible |

### Packages UNIQUE to Neuron AI

| Package | Version | Notes |
|---------|---------|-------|
| inspector-apm/inspector-php | 3.18.0 | ⚠️ **MUST remain bundled** - Not in Concrete CMS |
| neuron-core/neuron-ai | - | ⚠️ **MUST remain bundled** - Core package |

## Can We Use Concrete CMS's Packages?

### ✅ Good News
The versions are mostly compatible! Concrete CMS uses dev versions (x-dev) which are typically ahead of or equal to release versions.

### ⚠️ Important Considerations

#### 1. Version Instability Risk
**Parent uses dev versions:**
```json
"guzzlehttp/guzzle": "7.15.x-dev"
"guzzlehttp/promises": "2.5.x-dev"
"psr/http-message": "dev-master"
```

**Risks:**
- Dev versions can change without notice
- Breaking changes could occur between Concrete CMS updates
- Your package would lose version control over critical dependencies

#### 2. Autoloader Conflicts
Currently, your package uses `setupAutoloader()` to load its own `vendor/autoload.php`. If you switch to Concrete CMS's packages, you'd need to:
- Rely on Concrete CMS's autoloader (already loaded)
- Remove the custom autoloader from `controller.php`
- Accept whatever versions Concrete CMS provides

#### 3. Inspector APM Must Stay Bundled
The `inspector-apm/inspector-php` package is:
- Required by neuron-ai
- NOT provided by Concrete CMS
- Must remain bundled regardless

This means you can't eliminate bundling entirely.

## Recommended Approach

### Option 1: Keep Current Setup (RECOMMENDED)
**Keep all dependencies bundled** as they are now.

**Pros:**
- ✅ Complete version control
- ✅ Predictable behavior across installations
- ✅ No dependency on Concrete CMS package updates
- ✅ Package is fully self-contained
- ✅ Works even if Concrete CMS changes their dependencies

**Cons:**
- ❌ ~2-3 MB of duplicate Guzzle/PSR packages
- ❌ Slightly larger package size

**Verdict:** The duplication is minimal compared to the stability benefits.

### Option 2: Hybrid Approach (NOT RECOMMENDED)
Use Concrete CMS packages for common dependencies, bundle unique ones.

**Pros:**
- ✅ Smaller package size (~2-3 MB savings)
- ✅ Inspector APM still bundled

**Cons:**
- ❌ Loss of version control over critical HTTP client
- ❌ Vulnerable to Concrete CMS dependency changes
- ❌ Harder to debug issues (mixed autoloaders)
- ❌ Composer configuration becomes complex
- ❌ Testing nightmare (must test against multiple Concrete versions)

**Verdict:** Not worth the complexity and risk for 2-3 MB savings.

### Option 3: Minimal Bundle (HIGH RISK)
Only bundle `inspector-apm` and `neuron-core/neuron-ai`, rely on Concrete for everything else.

**Pros:**
- ✅ Smallest package size

**Cons:**
- ❌ **BREAKING RISK** if Concrete CMS updates Guzzle with breaking changes
- ❌ No guarantee Concrete CMS will always provide these packages
- ❌ Package becomes tightly coupled to Concrete CMS versions
- ❌ Users on older Concrete CMS versions might break
- ❌ Complex composer constraints required

**Verdict:** Too risky for a production package.

## Size Analysis

Current bundled vendor size:
```bash
$ du -sh httpdocs/packages/katalysis_neuron_ai/vendor/
16 MB  (actual measurement)
```

### ⚠️ ISSUE FOUND: Stale Package

Detailed breakdown (measured):
- **katalysis/neuron-ai: 12 MB** ⚠️ **OLD/STALE - SHOULD BE REMOVED**
  - docs/: 9.1 MB (unnecessary documentation)
  - tests/: 1.6 MB (dev files)
  - src/: 1.1 MB
- neuron-core/neuron-ai: 2.5 MB ✅ (correct package)
- guzzlehttp/* (guzzle, promises, psr7): 1.3 MB
- inspector-apm/inspector-php: 168 KB
- symfony/* packages: 64 KB
- PSR packages: 168 KB
- Other (ralouphie, composer, bin): ~130 KB

### After Cleanup (Expected)

After removing `vendor/katalysis/neuron-ai`:
```
Total: ~4 MB (down from 16 MB)
```

**Saving: 12 MB by removing stale package!**

If we also removed Guzzle/PSR duplicates (not recommended), we'd save another ~1.5 MB but introduce significant risk and complexity.

## Final Recommendation

**✅ KEEP THE CURRENT BUNDLED APPROACH**

**But first: Clean up stale packages!**
**Long-term Strategy

**Reasons to keep bundling:**
1. **Stability**: Full control over dependency versions
2. **Reliability**: Package works regardless of Concrete CMS version
3. **Debugging**: Clear dependency tree, no autoloader conflicts
4. **Size**: 4 MB (after cleanup)endor/katalysis/neuron-ai` package (12 MB):

```bash
cd httpdocs/packages/katalysis_neuron_ai
rm -rf vendor/katalysis/
./build.sh  # Rebuild to get clean vendor
```

This will reduce the bundle from 16 MB → 4 MB.

### Long-term Strategy

**Reasons to keep bundling:**
1. **Stability**: Full control over dependency versions
2. **Reliability**: Package works regardless of Concrete CMS version
3. **Debugging**: Clear dependency tree, no autoloader conflicts
4. **Size**: 4.5 MB is reasonable for a full-featured AI package
5. **Simplicity**: Current approach is clean and maintainable
6. **Inspector APM**: Must bundle this anyway, so infrastructure exists

## Implementation Notes

If you want to optimize size without changing the architecture:

### Option A: Use `--no-dev` in build (Already doing this ✅)
```bash
composer install --no-dev --optimize-autoloader
```

### Option B: Optimize autoloader with classmap generation
```bash
composer install --no-dev --optimize-autoloader --classmap-authoritative
```

### Option C: Exclude unnecessary files via `.gitattributes` (Already doing this ✅)
```
vendor/*/tests export-ignore
vendor/*/docs export-ignore
```

## Testing Commands

To verify current setup:
```bash
# Check bundled size
du -sh httpdocs/packages/katalysis_neuron_ai/vendor/

# Check parent packages
ls -la vendor/guzzlehttp/
ls -la vendor/inspector-apm/

# Run verification
./httpdocs/packages/katalysis_neuron_ai/check-cleanup.sh

# Compare dependencies
./check-dependencies.sh
```

## Conclusion

**The current bundled dependency approach is the right architecture.** The 4.5 MB size is acceptable for:
- Guaranteed stability
- Version independence
- Simplified debugging
- Production reliability

The overlap with Concrete CMS packages is expected and not a problem. Modern PHP packages commonly have dependency overlaps - Composer's autoloader handles this efficiently by only loading classes once, even if they exist in multiple vendor directories.

**No changes recommended.**
