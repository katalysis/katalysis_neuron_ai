#!/bin/bash

# Neuron AI Fork Maintenance Script
# Maintains PSR Log compatibility with Concrete CMS

set -e

echo "🔄 Maintaining Neuron AI fork for Concrete CMS compatibility..."

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Configuration
UPSTREAM_REMOTE="upstream"
ORIGIN_REMOTE="origin"
MAIN_BRANCH="main"
CUSTOM_FILES=("composer.json" "LogObserver.php")

# Function to print colored output
print_status() {
    echo -e "${GREEN}✓${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}⚠${NC} $1"
}

print_error() {
    echo -e "${RED}✗${NC} $1"
}

# Backup current state
backup_branch="backup-$(date +%Y%m%d-%H%M%S)"
git checkout -b $backup_branch
git push $ORIGIN_REMOTE $backup_branch
print_status "Created backup branch: $backup_branch"

# Return to main
git checkout $MAIN_BRANCH

# Fetch upstream changes
print_status "Fetching upstream changes..."
git fetch $UPSTREAM_REMOTE

# Check for new releases
current_tag=$(git describe --tags --exact-match HEAD 2>/dev/null || echo "unknown")
upstream_latest=$(git ls-remote --tags $UPSTREAM_REMOTE | grep -o 'refs/tags/[^{}]*' | sed 's/refs\/tags\///' | sort -V | tail -1)

echo "Current version: $current_tag"
echo "Upstream latest: $upstream_latest"

if [ "$current_tag" != "$upstream_latest" ]; then
    print_warning "New version available: $upstream_latest"
    
    # Check if this is a major version jump
    current_major=$(echo $current_tag | cut -d. -f1)
    latest_major=$(echo $upstream_latest | cut -d. -f1)
    
    if [ "$current_major" != "$latest_major" ] && [ "$current_tag" != "unknown" ]; then
        print_warning "⚠️  MAJOR VERSION JUMP DETECTED: $current_tag → $upstream_latest"
        print_warning "This requires careful testing. Consider updating incrementally."
        echo ""
        echo "Recommended approach:"
        echo "1. Update to latest 1.x first: git merge 1.17.5"
        echo "2. Test thoroughly with your Concrete CMS package"
        echo "3. Then update to 2.x: git merge 2.2.14"
        echo ""
        read -p "Continue with direct update to $upstream_latest? (y/N): " confirm
        if [ "$confirm" != "y" ] && [ "$confirm" != "Y" ]; then
            echo "Update cancelled. You can run this script again later."
            exit 0
        fi
    fi
    
    # Create update branch
    update_branch="update-to-$upstream_latest"
    git checkout -b $update_branch
    
    # Merge with our strategy
    echo "Attempting merge with custom strategy..."
    
    # Backup our custom files
    for file in "${CUSTOM_FILES[@]}"; do
        if [ -f "$file" ]; then
            cp "$file" "${file}.backup"
            print_status "Backed up $file"
        fi
    done
    
    # Attempt merge
    if git merge $upstream_latest; then
        print_status "Clean merge successful"
    else
        print_warning "Merge conflicts detected, applying our customizations..."
        
        # Restore our custom files
        for file in "${CUSTOM_FILES[@]}"; do
            if [ -f "${file}.backup" ]; then
                cp "${file}.backup" "$file"
                git add "$file"
                print_status "Restored custom $file"
            fi
        done
        
        # Complete merge
        git commit -m "Merge $upstream_latest with Concrete CMS customizations"
    fi
    
    # Clean up backup files
    for file in "${CUSTOM_FILES[@]}"; do
        rm -f "${file}.backup"
    done
    
    print_status "Update to $upstream_latest completed"
    print_warning "Please review changes and test before pushing"
    
else
    print_status "Already on latest version"
fi

echo -e "\n${GREEN}Fork maintenance completed!${NC}"
echo -e "Backup created: ${YELLOW}$backup_branch${NC}"
echo -e "Current branch: ${YELLOW}$(git branch --show-current)${NC}"
