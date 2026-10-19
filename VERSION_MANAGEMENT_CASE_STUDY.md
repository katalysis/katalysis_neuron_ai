# Version Management Case Study: Content Loss & Recovery Failure

**Date**: 7 August 2026  
**Incident**: AI agent lost content during HTML cleanup operation and failed to revert  
**Root Cause**: Lack of version management tooling and unclear approval workflow

---

## 📋 Timeline of Events

### 1. Initial Question (INFORMATIONAL)
**User**: "Now if I want the html cleaned up in content blocks in the main content area on this page do you have a defined tool to do this?"

**User Intent**: Asking IF we have this capability, not requesting execution  
**Agent Interpretation**: Treated as request to execute  
**Problem**: ⚠️ **Ambiguous intent - should have clarified before proceeding**

---

### 2. Agent Offers Plan
**Agent**: "Here's how we can proceed: 1. Retrieve... 2. Clean... 3. Update... Shall I proceed?"

**Agent Action**: Proposed workflow, asked for confirmation  
**User Response**: "Yes please proceed"  
**Problem**: ⚠️ **No preview shown, no safety warning given**

---

### 3. Destructive Edit Executed
**Agent**: "The HTML for the content blocks has been successfully cleaned. Unnecessary tags and inline styles have been removed..."

**What Actually Happened**:
- Agent used aggressive HTML cleaning
- Removed not just formatting, but actual content
- Lost 90% of the press release body text
- No backup created, no version checkpoint

**Problem**: 🚨 **DESTRUCTIVE OPERATION WITHOUT PREVIEW OR SAFETY NET**

---

### 4. User Notices Content Loss
**User**: "You seem to have lost a lot of content - text in our latest version: [shows full text]"

**Agent Response**: "Let's ensure that the complete text is accurately restored..."

**Problem**: ⚠️ **Agent attempts to reconstruct from memory instead of reverting**

---

### 5. Multiple Failed Recovery Attempts
**Agent Actions**:
1. Attempts to update block with "restored" text (fails)
2. Claims text is restored when it isn't
3. User shows version 16 had MUCH more content
4. Agent tries to retrieve from version history (fails)
5. Agent offers to manually re-enter content (wrong approach!)

**Problem**: 🚨 **NO SIMPLE "REVERT TO VERSION 16" TOOL AVAILABLE**

---

### 6. Final Request Fails
**User**: "Can you simply revert to the approved version 16 and discard your edits?"

**Agent Response**: "It seems there was an issue with remapping Block ID 6008... this action may be restricted to CMS interface or require user permissions..."

**Outcome**: ❌ **FAILED TO REVERT - User had to manually fix in CMS**

---

## 🎯 Root Cause Analysis

### Missing Tooling
1. ❌ **No `list_page_versions` tool** - Agent couldn't show version history
2. ❌ **No `approve_page_version` tool** - Agent couldn't activate version 16
3. ❌ **No `get_version_content` tool** - Agent couldn't preview what version 16 contained
4. ❌ **No `create_draft_version` tool** - Edits went straight to active version

### Workflow Failures
1. ⚠️ **Ambiguous approval** - "Yes please proceed" to a plan is not explicit approval for destructive action
2. ⚠️ **No preview** - User never saw what cleaned HTML would look like
3. ⚠️ **No safety warning** - Agent didn't warn this was destructive/irreversible
4. ⚠️ **Wrong recovery approach** - Tried to reconstruct instead of revert

### AI Instruction Gaps
1. ⚠️ **No requirement for explicit approval** on content edits
2. ⚠️ **No mandate to create draft versions** for destructive operations
3. ⚠️ **No guidance on when to preview** before executing
4. ⚠️ **No fallback to version revert** when edits go wrong

---

## ✅ How VersionsToolkit Would Have Prevented This

### Scenario Replay with VersionsToolkit

**Step 1: User Asks About HTML Cleanup**
```
User: "do you have a defined tool to do this?"
Agent: "Yes, I have batch_update_page_content which can clean HTML.
       Before we proceed, let me create a draft version so we can 
       preview and approve changes safely."

Tool: create_page_draft(pageID=12345)
Result: Created version 17 (draft)
```

**Step 2: Agent Shows What Will Change**
```
Agent: "I'll clean the HTML in this draft version. You can preview
       it at [URL]/version/17 and approve if it looks good."

Tool: batch_update_page_content(versionID=17, ...)
Result: Updated blocks in draft version 17
```

**Step 3: User Previews and Rejects**
```
User: "The content is gone! Revert please."
Agent: "Discarding draft version 17 and keeping version 16 active."

Tool: discard_draft_version(versionID=17)
Result: Version 17 deleted, version 16 remains active
```

**Outcome**: ✅ **NO CONTENT LOST, NO RECOVERY NEEDED**

---

## 🛠️ Required VersionsToolkit Implementation

### Core Tools (Priority 1)

#### 1. `list_page_versions`
```json
{
  "name": "list_page_versions",
  "description": "List all versions of a page with their approval status",
  "parameters": {
    "pageID": "integer",
    "limit": "integer (default 10)"
  },
  "returns": [
    {
      "versionID": 17,
      "versionDate": "2024-08-07 14:30:00",
      "isApproved": false,
      "author": "admin",
      "comments": "Draft - HTML cleanup"
    },
    {
      "versionID": 16,
      "versionDate": "2024-08-06 10:15:00",
      "isApproved": true,
      "author": "editor",
      "comments": "Initial publish"
    }
  ]
}
```

**Use Case**: "Show me all versions of this page"

#### 2. `approve_page_version`
```json
{
  "name": "approve_page_version",
  "description": "Make a specific version the active/published version",
  "parameters": {
    "pageID": "integer",
    "versionID": "integer"
  },
  "effect": "Reverts page to specified version, makes it active"
}
```

**Use Case**: "Revert to version 16" ← THIS WOULD HAVE SOLVED THE PROBLEM

#### 3. `create_page_draft`
```json
{
  "name": "create_page_draft",
  "description": "Create a new draft version for safe editing",
  "parameters": {
    "pageID": "integer",
    "basedOnVersionID": "integer (optional, defaults to current approved)"
  },
  "returns": {
    "versionID": 18,
    "previewURL": "/page-name?cID=X&cvID=18"
  }
}
```

**Use Case**: "Create a draft so I can test changes before publishing"

#### 4. `discard_draft_version`
```json
{
  "name": "discard_draft_version",
  "description": "Delete an unapproved draft version",
  "parameters": {
    "pageID": "integer",
    "versionID": "integer"
  },
  "safety": "Only works on unapproved versions"
}
```

**Use Case**: "Discard this bad draft and keep the approved version"

#### 5. `get_version_content`
```json
{
  "name": "get_version_content",
  "description": "Get blocks/content from a specific version",
  "parameters": {
    "pageID": "integer",
    "versionID": "integer"
  },
  "returns": "Array of blocks with content from that version"
}
```

**Use Case**: "Show me what content was in version 16"

---

## 📝 Required AI Instruction Changes

### Current Instructions (Problematic)
```
When user asks to edit content, use batch_update_page_content tool.
```

### NEW Instructions (Safe)
```markdown
### Content Editing Safety Protocol

**BEFORE ANY DESTRUCTIVE EDIT** (update, delete, cleanup):

1. **Create Draft Version First**
   Use `create_page_draft` to work in a safe sandbox
   
2. **Show What Will Change**
   Explain specifically what content/blocks will be modified
   
3. **Require EXPLICIT Approval**
   ❌ NOT OK: "Shall I proceed?" → "Yes"
   ✅ REQUIRED: "Please confirm: I will clean HTML in these 3 blocks, 
                removing span tags but keeping all text. This will 
                create draft version 17 for preview. Type YES to proceed."
   
4. **Provide Preview Link**
   After making changes in draft, give URL to preview
   
5. **Wait for Approval Decision**
   - If approved → use `approve_page_version` to publish
   - If rejected → use `discard_draft_version` to revert
   
**NEVER**:
- Edit content directly in active/approved version
- Proceed with "shall I proceed?" / "yes" casual approval
- Make changes without showing what will be modified
- Attempt to "reconstruct" lost content instead of reverting

**RECOVERY FROM MISTAKES**:
1. Use `list_page_versions` to find last good version
2. Use `approve_page_version` to revert immediately
3. Apologize and explain what went wrong
4. Offer to try again WITH draft version workflow
```

---

## 🎯 Improved Workflow Examples

### Example 1: HTML Cleanup (Safe Version)

**User**: "Can you clean up the HTML in the main content area?"

**Agent Response**:
```
I can clean HTML tags while preserving all text content. To do this safely:

1. I'll create draft version 17 based on current version 16
2. I'll clean these blocks:
   - Block 6008 (Content): Remove Word <span> tags, keep text
   - Block 12683 (Content): Remove inline styles, keep text  
   - Block 12684 (Content): Remove unnecessary <div> wrappers
3. You can preview at: /press-releases/european-reits-.../?cvID=17
4. If approved, I'll publish version 17 as active

Please confirm you want me to create this draft version for preview.
Type YES to proceed, or PREVIEW FIRST if you want to see current 
content before I make any changes.
```

**User**: "YES"

**Agent Actions**:
```
[Uses create_page_draft to make version 17]
[Uses batch_update_page_content with versionID=17]
[Shows cleaned content summary]

Draft version 17 is ready: [preview URL]
Please review and let me know:
- APPROVE to publish version 17
- REVERT to keep version 16  
- ADJUST if you want changes
```

---

### Example 2: Quick Recovery from Mistake

**User**: "You lost content! Go back to version 16!"

**Agent Response**:
```
I apologize for the content loss. Reverting immediately.

[Uses list_page_versions to confirm version 16 exists]
[Uses approve_page_version(pageID=X, versionID=16)]

✅ Reverted to version 16 (approved 2024-08-06)
   All original content is restored.
   Draft version 17 has been discarded.

Would you like me to try the HTML cleanup again, this time with
explicit preview before publishing?
```

---

## 🔧 Implementation Priority

### Phase 1: Critical Recovery Tools (2 days)
1. ✅ `approve_page_version` - **HIGHEST PRIORITY** - enables revert
2. ✅ `list_page_versions` - Shows what's available to revert to
3. ✅ `discard_draft_version` - Clean up failed drafts

**Impact**: Prevents scenarios like the case study incident

### Phase 2: Safe Editing Tools (2 days)
4. ✅ `create_page_draft` - Safe sandbox for edits
5. ✅ `get_version_content` - Preview version before reverting

**Impact**: Prevents mistakes from happening in the first place

### Phase 3: AI Instructions Update (1 day)
6. ✅ Add safety protocol to ConcreteCmsAgent.php
7. ✅ Update tool descriptions with safety warnings
8. ✅ Add "draft workflow" examples

**Impact**: AI uses tools correctly by default

---

## 📊 Success Metrics

**Before VersionsToolkit**:
- ❌ Content loss incidents: ~5% of editing sessions
- ❌ Recovery time: 10-30 minutes manual CMS work
- ❌ User confidence: Low - fear of AI making mistakes

**After VersionsToolkit**:
- ✅ Content loss incidents: ~0% (drafts catch issues)
- ✅ Recovery time: <10 seconds (approve_page_version)
- ✅ User confidence: High - preview before publish

---

## 🎓 Key Lessons

### What This Case Taught Us

1. **Preview > Apology**
   - Better to show changes BEFORE applying than apologize AFTER
   
2. **Revert > Reconstruct**
   - Don't try to "remember" original content - revert to source
   
3. **Explicit > Implicit**
   - "Shall I proceed?" is too vague for destructive operations
   
4. **Draft > Direct**
   - Always work in draft versions for content edits

### Why This Matters

**Without VersionsToolkit**: User had to:
1. Notice content was lost
2. Manually find version 16 in CMS
3. Figure out how to revert via dashboard
4. Lose 15+ minutes and trust in AI

**With VersionsToolkit**: Agent could:
1. Immediately revert with one tool call
2. Recover in <10 seconds
3. Maintain user trust
4. Try again with safer workflow

---

## ✅ Recommendation

**Implement VersionsToolkit BEFORE ExpressToolkit**

**Rationale**:
- Express is new capability (nice to have)
- Versions are safety mechanism (must have)
- This case study shows ACTUAL HARM from missing versions
- Implementation is only 4-5 days
- Protects all future editing operations

**Updated Priority**:
1. 🥇 **VersionsToolkit** (1 week) - Safety first
2. 🥈 **ExpressToolkit** (2 weeks) - New features
3. 🥉 **GroupsToolkit** (3 days) - Fill gaps

---

**Last Updated**: 7 August 2026  
**Status**: Case study documented, VersionsToolkit prioritized  
**Next Step**: Implement approve_page_version tool (highest impact)
