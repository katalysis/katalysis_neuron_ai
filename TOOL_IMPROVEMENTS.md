# Katalysis Neuron AI - Tool Improvements (Aug 2026)

## Overview
Enhanced page content tools based on user feedback from real-world usage with improved error handling, granular block access, and robust debugging capabilities. Added image file ID support to People toolkit for batch thumbnail generation.

## Latest Enhancements (Aug 5, 2026)

### People Toolkit - Image File ID Support ✅
**Issue:** Need to retrieve file IDs for people images to use with batch thumbnail generation tasks.

**Solution:** Enhanced `PeopleToolkit` with:
- Added `image` field to `list_people` tool response
- Added `image` field to `get_person_details` tool response  
- Created new `get_people_image_file_ids` tool that returns:
  - `fileIds`: Array of file IDs
  - `commaSeparatedFileIds`: Comma-separated string ready for task input
  - `people`: Array with person ID, name, and image file ID
  - Filtering options: active_only, featured_only, exclude_null

**Usage Example:**
```json
{
  "active_only": true,
  "featured_only": false,
  "exclude_null": true
}
```

**Returns:**
```json
{
  "success": true,
  "totalPeople": 43,
  "fileIds": [3780, 3781, 3782],
  "commaSeparatedFileIds": "3780, 3781, 3782",
  "people": [
    {"sID": 1, "name": "Helen Gordon", "imageFileId": 3780},
    {"sID": 2, "name": "John Smith", "imageFileId": 3781}
  ]
}
```

**Benefits:**
- Direct integration with Generate Thumbnails Task
- Correct file IDs for all people (not placeholder data)
- Flexible filtering to target specific subsets
- Human-readable output with person names for verification

---

## User Feedback Analysis
Original feedback points and how they were addressed:

### 1. Error Details ✅
**Issue:** Function call failures with insufficient context about what went wrong.

**Solution:**
- Added comprehensive try-catch blocks at every operation level
- Enhanced all error messages with specific context (page IDs, version IDs, area handles)
- Added error traces in batch operations for debugging
- Included detailed type information in validation errors

**Examples:**
```
Before: "Page not found"
After:  "Page with ID 1234 not found. The page may not exist or may have been deleted."

Before: "Permission denied"
After:  "Permission denied: Cannot edit page 1234. You need edit permissions for this page."
```

### 2. Block Access (Granular Control) ✅
**Issue:** Need direct access to block data without requiring full page path.

**Solution:** Created new `get_page_blocks` tool with:
- Lists all blocks on a page grouped by area
- Optional filtering by specific area handle
- Detailed block information including:
  - Block ID, type, name, display order
  - Content preview (200 char truncated plaintext)
  - Block count per area
- Version control (active/recent)
- Graceful error handling per block/area

**Usage:**
```json
{
  "pageID": 1234,
  "version": "recent",
  "areaHandle": "Main"  // optional
}
```

**Returns:**
```json
{
  "page": {
    "id": 1234,
    "path": "/page-path",
    "versionID": 5,
    "isActive": false
  },
  "totalBlocks": 15,
  "areaCount": 3,
  "areas": [
    {
      "areaHandle": "Main",
      "blockCount": 8,
      "blocks": [
        {
          "blockID": 567,
          "blockType": "content",
          "blockName": "Main Content",
          "displayOrder": 1,
          "contentPreview": "This is the page content..."
        }
      ]
    }
  ]
}
```

### 3. Versioning ✅
**Issue:** Need to differentiate between "published" and "draft" content states.

**Solution:**
- Added explicit `version` parameter to all content tools
- Two modes: `"active"` (published) and `"recent"` (latest draft)
- Default to "recent" for edit operations, "active" for read operations
- Return version metadata in all responses:
  ```json
  "version": {
    "cvID": 5,
    "isActive": false,
    "versionHandle": "RECENT"
  }
  ```
- Proper permission checks per version type:
  - `canViewPage()` for active versions
  - `canViewPageVersions()` for recent/draft versions

### 4. Robust Error Responses ✅
**Issue:** Need verbose error messages for debugging.

**Solution:**
- **Validation errors** include received type/value:
  - `"blocks[0].blockID must be a positive integer, got: '123' (string)"`
  
- **Operation errors** include full context:
  - `"Block 567 not found in area 'Main' on page version 5"`
  
- **Batch operation results** include per-item status:
  ```json
  {
    "summary": {
      "totalBlocks": 5,
      "successCount": 4,
      "failureCount": 1
    },
    "blocks": [
      {
        "areaHandle": "Main",
        "oldBlockID": 100,
        "newBlockID": 105,
        "ok": true
      },
      {
        "areaHandle": "Sidebar",
        "oldBlockID": 200,
        "newBlockID": 205,
        "ok": false,
        "error": "Block 205 not found...",
        "trace": "..."
      }
    ]
  }
  ```

- **Render method tracking** in `get_page_content`:
  ```json
  {
    "renderMethod": "fallback",  // or "rendered"
    "renderErrors": [
      "Page render failed: ...",
      "Page view setup failed: ..."
    ]
  }
  ```

### 5. Content Extraction Robustness ✅
**Issue:** `extractContentFromBlocks()` failed silently on errors.

**Solution:** Complete rewrite with:
- Try-catch around area enumeration
- Try-catch around each area access
- Try-catch around each block access
- Try-catch around each content extraction method
- Graceful fallback chain:
  1. `getContent()`
  2. `$controller->content` property
  3. `getSearchableContent()`
- Error collection and reporting in HTML comments
- HTML entity escaping for data attributes
- Detailed error messages per failure point

## New Tools Summary

### Tool Count Update
- **Before:** 60 tools (15 page tools)
- **After:** 61 tools (16 page tools)

### New Tool: `get_page_blocks`
**Purpose:** Granular block inspection and debugging

**Parameters:**
- `pageID` (int, required) - Page to inspect
- `version` (string, optional) - "active" or "recent" (default: "recent")
- `areaHandle` (string, optional) - Filter to specific area

**Returns:**
- Page metadata with version info
- Total block count
- Areas array with block details
- Content preview per block
- Errors array if any issues occurred

**Use Cases:**
1. **Pre-update validation:** Check block IDs before batch update
2. **Debugging:** Understand page structure when operations fail
3. **Content inventory:** List all blocks for migration/audit
4. **Area-specific ops:** Get blocks from one area for moving

## Enhanced Tools

### `get_page_content`
**Improvements:**
- Better error handling with try-catch at each level
- `renderMethod` field shows if page was rendered or fell back to block extraction
- `renderErrors` array with detailed failure reasons
- Enhanced permission error messages
- Version handle included in response

### `batch_update_page_content`
**Improvements:**
- Type validation with specific error messages showing received types
- Enhanced remapping error messages with suggestion to use `get_page_blocks`
- Per-block success/failure tracking with error traces
- Summary statistics (successCount, failureCount)
- Block type included in results
- Better versioning error messages
- Warning field when partial failures occur

## Recommended Workflow

### For Page Content Updates:
1. **Inspect page structure:**
   ```json
   {"tool": "get_page_blocks", "pageID": 1234, "version": "recent"}
   ```

2. **Verify block IDs and areas from response**

3. **Prepare updates with exact block IDs:**
   ```json
   {
     "tool": "batch_update_page_content",
     "pageID": 1234,
     "blocks": [
       {
         "areaHandle": "Main",
         "blockID": 567,
         "value": {"content": "Updated content"}
       }
     ]
   }
   ```

4. **Review result for partial failures:**
   - Check `summary.failureCount`
   - Review `blocks` array for `ok: false` entries
   - Read `error` and `trace` for debugging

5. **If errors occur:**
   - Re-run `get_page_blocks` to verify current state
   - Check error messages for specific failure reasons
   - Verify permissions with page context tools

## Testing Recommendations

### Test Case 1: Basic Block Inspection
```json
{
  "tool": "get_page_blocks",
  "pageID": <test_page_id>,
  "version": "recent"
}
```
**Expected:** Full area/block inventory with no errors

### Test Case 2: Area-Specific Inspection
```json
{
  "tool": "get_page_blocks",
  "pageID": <test_page_id>,
  "areaHandle": "Main"
}
```
**Expected:** Only blocks from "Main" area

### Test Case 3: Single Block Update
```json
{
  "tool": "batch_update_page_content",
  "pageID": <test_page_id>,
  "blocks": [
    {
      "areaHandle": "Main",
      "blockID": <valid_block_id>,
      "value": {"content": "Test update"}
    }
  ]
}
```
**Expected:** Success with new version created

### Test Case 4: Invalid Block ID (Error Path)
```json
{
  "tool": "batch_update_page_content",
  "pageID": <test_page_id>,
  "blocks": [
    {
      "areaHandle": "Main",
      "blockID": 99999,
      "value": {"content": "Test"}
    }
  ]
}
```
**Expected:** Detailed error about remapping failure with suggestion to use get_page_blocks

## Static Analysis Notes

The following static analysis warnings are **false positives** and can be ignored:
- `Area::get($page, $handle)` - Type hint mismatch (runtime compatible)
- `$area->getAreaBlocksArray($page)` - Type hint mismatch (runtime compatible)

These are artifacts of Concrete CMS's type hints using a generic `Page` interface while the actual runtime type is `Concrete\Core\Page\Page`. The code follows existing patterns in the codebase and will work correctly.

## Future Enhancements

### Phase 2 (Next Priority):
1. **OpenAPI Dynamic Tool Generation** - Automatically generate 100+ tools from Concrete CMS's existing endpoints
2. **Batch move operations** - Move multiple blocks between areas/pages atomically
3. **Block clone operations** - Duplicate blocks with content preservation
4. **Content search tools** - Find blocks by content/type across site

### Phase 3:
1. **Bulk page operations** - Batch updates across multiple pages
2. **Workflow integration** - Trigger approval workflows programmatically
3. **Asset management** - Enhanced file/image tools with preview

## Documentation

All enhancements maintain backward compatibility. Existing tool usage remains unchanged - new features are opt-in via additional parameters or new tools.

For implementation details, see:
- `/httpdocs/packages/katalysis_neuron_ai/src/Tools/PagesToolkit.php` - Tool implementations
- `/httpdocs/packages/katalysis_neuron_ai/src/Tools/Utilities/BlockIdRemapper.php` - Remapping logic
- `/httpdocs/packages/katalysis_neuron_ai/FEATURE_COMPARISON.md` - Architecture roadmap
