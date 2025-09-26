# Neuron AI Fork Update Strategy

## Overview
This document outlines the strategy for maintaining our Neuron AI fork that's customized for Concrete CMS integration.

## Key Customizations

### 1. PSR Log Dependency Removal
- **Reason**: Concrete CMS provides its own PSR log implementation
- **Impact**: Removed `"psr/log": "^1.0|^2.0|^3.0"` from composer.json
- **Solution**: Use Concrete CMS Log facade instead

### 2. Custom LogObserver
- **Location**: `/src/Integrations/ConcreteCMS/ConcreteCmsLogObserver.php`
- **Purpose**: Drop-in replacement that uses Concrete CMS logging
- **Fallback**: Safe class_exists() check for compatibility

## Update Process

### Manual Update (Current Method)
```bash
# 1. Create backup
git checkout -b backup-$(date +%Y%m%d)
git push origin backup-$(date +%Y%m%d)

# 2. Create update branch  
git checkout main
git checkout -b update-to-vX.X.X

# 3. Fetch upstream
git fetch upstream --tags

# 4. Merge with strategy
git merge vX.X.X

# 5. Resolve conflicts:
#    - Keep our custom composer.json (no psr/log)
#    - Keep our LogObserver modifications
#    - Update other files as needed

# 6. Test and push
composer install
composer test
git push origin update-to-vX.X.X
```

### Automated Update (Recommended)
```bash
./scripts/maintain-fork.sh
```

## File Management Strategy

### Always Keep Our Version
- `composer.json` - Missing psr/log dependency
- `LogObserver.php` (root) - Concrete CMS integration
- `/src/Integrations/ConcreteCMS/` - Our custom integration layer

### Review and Merge
- All other source files
- Documentation updates
- Test files

### Special Attention Required
- Any new PSR log usage in upstream code
- New observability features that might need logging integration
- Breaking changes in event system

## Version Compatibility Matrix

| Our Version | Upstream Version | Status | Notes |
|-------------|------------------|--------|-------|
| 1.15.4      | 1.15.4          | ✓ Current | Base version |
| -           | 2.0.0           | 🔄 Target | Major version upgrade |
| -           | 2.2.14          | 📋 Latest | Latest available |

## Testing Checklist

After each update:

- [ ] `composer install` runs without errors
- [ ] `composer test` passes
- [ ] LogObserver integrates correctly with Concrete CMS
- [ ] No new psr/log dependencies introduced
- [ ] All Neuron AI features work as expected
- [ ] Concrete CMS package compatibility maintained

## Rollback Plan

If update fails:
```bash
git checkout main
git reset --hard backup-YYYYMMDD
git push origin main --force-with-lease
```

## Future Improvements

1. **Automated Testing**: Set up CI to test against Concrete CMS
2. **Dependency Monitoring**: Watch for new psr/log usage upstream
3. **Integration Tests**: Specific tests for Concrete CMS integration
4. **Documentation**: Keep this strategy updated with each release

## Contacts & Resources

- **Upstream Repository**: https://github.com/inspector-apm/neuron-ai
- **Our Fork**: https://github.com/katalysis/neuron-ai  
- **Concrete CMS Logging**: https://documentation.concretecms.org/developers/framework/logging
