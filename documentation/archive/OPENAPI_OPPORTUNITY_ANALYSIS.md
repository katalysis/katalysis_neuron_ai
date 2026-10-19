# OpenAPI Auto-Generation Opportunity Analysis

**Date**: 7 August 2026  
**Purpose**: Explore what we're missing by not implementing OpenAPI auto-generation

## 🚨 Critical Update

**A real incident** (documented in [VERSION_MANAGEMENT_CASE_STUDY.md](VERSION_MANAGEMENT_CASE_STUDY.md)) revealed that **VersionsToolkit is now the highest priority**:

- User asked AI to clean HTML tags
- AI deleted actual content (not just formatting)
- No tool available to revert to previous version
- User had to manually fix via CMS dashboard

**New Priority**: Implement VersionsToolkit FIRST (4-5 days), then ExpressToolkit and GroupsToolkit.

**Safety > Features**: Version management is a safety mechanism, not just a feature enhancement.

---

## 🔍 Discovery: Concrete CMS Has a Full REST API

### What We Found

Concrete CMS 9.x includes a **comprehensive REST API** with:
- **Location**: `/ccm/api/1.0/`
- **Spec Format**: OpenAPI 3.0 annotations (`@OA\` in PHP controllers)
- **Auto-documentation**: Swagger UI at `/dashboard/system/api`
- **Controllers**: `concrete/src/Api/Controller/`
- **Spec Generator**: `concrete/src/Api/OpenApi/SpecGenerator.php`

### Available API Endpoints

**Core Resources** (from `concrete/src/Api/Controller/`):

1. **Pages.php** - Page management
   - `GET /pages` - List pages (with cursor pagination)
   - `GET /pages/{id}` - Get single page
   - `PUT /pages/{id}` - Update page (creates new version)
   - `DELETE /pages/{id}` - Delete page
   - `POST /pages` - Create page
   - **Includes support**: `custom_attributes`, `areas`, `files`, `content`, `areas.content`

2. **Blocks.php** - Block operations
   - `GET /blocks/{id}` - Get block
   - `DELETE /blocks/{id}` - Delete block
   - **Includes**: `pages`

3. **Areas.php** - Area/block management
   - `GET /areas/{id}` - Get area
   - `POST /areas/{id}/blocks` - Add block to area
   - `PUT /areas/blocks/{blockID}` - Update block
   - `DELETE /areas/blocks/{blockID}` - Delete block from area

4. **Files.php** - File management
   - `GET /files` - List files
   - `GET /files/{id}` - Get file
   - `PUT /files/{id}` - Update file
   - `DELETE /files/{id}` - Delete file
   - `POST /files` - Upload file
   - **Includes**: `custom_attributes`

5. **Users.php** - User management
   - `GET /users` - List users
   - `GET /users/{id}` - Get user
   - `PUT /users/{id}` - Update user
   - `POST /users` - Create user
   - **Includes**: `custom_attributes`, `groups`

6. **Groups.php** - Group management
   - `GET /groups` - List groups
   - `GET /groups/{id}` - Get group
   - `POST /groups` - Create group
   - `PUT /groups/{id}` - Update group
   - `DELETE /groups/{id}` - Delete group
   - `POST /groups/{id}/users` - Add users to group

7. **Express.php** - Express Objects (custom entities)
   - `GET /express/entities` - List all express entities
   - `GET /express/entities/{handle}` - Get entity definition
   - `GET /express/{entity}` - List entries for entity
   - `GET /express/{entity}/{id}` - Get single entry
   - `POST /express/{entity}` - Create entry
   - `PUT /express/{entity}/{id}` - Update entry
   - `DELETE /express/{entity}/{id}` - Delete entry
   - **Dynamic**: Works with ANY custom express object!

8. **Versions.php** - Page versioning
   - `GET /pages/{id}/versions` - List page versions
   - `POST /pages/{id}/versions` - Create new version
   - `PUT /pages/{id}/versions/{versionID}/approve` - Approve version

9. **Sites.php** - Multisite management
   - `GET /sites` - List sites
   - `GET /sites/{id}` - Get site
   - `POST /sites` - Create site

10. **Account.php** - Current user operations
    - `GET /account` - Get current user info
    - `PUT /account` - Update current user

11. **System.php** - System information
    - `GET /system/info` - System stats
    - `GET /system/health` - Health check

12. **Oauth2.php** - OAuth management
    - Token generation
    - Client management

---

## 📊 What We're Missing vs What We Have

### Coverage Comparison

| Feature Category | API Endpoints | Neuron AI Tools | Gap |
|-----------------|---------------|-----------------|-----|
| **Pages** | 5 ops + includes | ✅ 16 tools | ✅ **COVERED** |
| **Blocks** | 4 ops | ✅ 6 tools | ✅ **COVERED** |
| **Files** | 5 ops + includes | ✅ 7 tools | ✅ **COVERED** |
| **Users** | 4 ops + includes | ✅ 4 tools | ✅ **COVERED** |
| **Attributes** | Via includes | ✅ 18 tools | ✅ **BETTER** |
| **Topics** | Not in API | ✅ 4 tools | ✅ **EXCLUSIVE** |
| **People (Katalysis)** | Not in API | ✅ 4 tools | ✅ **EXCLUSIVE** |
| **Versions** | ❌ 3 ops | ⚠️ Partial | 🔴 **CRITICAL GAP** |
| **Groups** | ❌ 5 ops | ❌ None | ⚠️ **GAP** |
| **Express Objects** | ❌ Full CRUD | ❌ None | ⚠️ **GAP** |
| **Sites** | ❌ 3 ops | ❌ None | ⚠️ **GAP** |
| **System Info** | ❌ 2 ops | ❌ None | ⚠️ **LOW PRIORITY** |

---

## 🎯 Specific Opportunities We're Missing

### 1. **Version Management** ⭐⭐⭐⭐⭐ 🔴 **CRITICAL PRIORITY**

**What API Provides**: (See detailed section below)

**Why This Is #1**: Real incident showed AI deleted content with no recovery path. This is a **safety issue**. See [VERSION_MANAGEMENT_CASE_STUDY.md](VERSION_MANAGEMENT_CASE_STUDY.md) for full analysis.

---

### 2. **Express Objects (Custom Entities)** ⭐⭐⭐⭐⭐

**What API Provides**:
```javascript
// List all custom entities in the system
GET /ccm/api/1.0/express/entities
// Returns: ["products", "testimonials", "events", "locations", etc.]

// Get entries from any entity
GET /ccm/api/1.0/express/products?limit=20
GET /ccm/api/1.0/express/testimonials

// Create entry
POST /ccm/api/1.0/express/products
{
  "name": "Widget Pro",
  "price": 99.99,
  "sku": "WID-001",
  "category": 5
}

// Update entry
PUT /ccm/api/1.0/express/products/42
{
  "price": 89.99
}

// Delete entry
DELETE /ccm/api/1.0/express/products/42
```

**Why This Is HUGE**:
- **Automatic support** for ANY custom entity
- No toolkit code needed per entity
- Site-specific data structures "just work"
- Future-proof as entities are added

**Use Cases**:
- "Show me all products with price > $100"
- "Create a new testimonial for client XYZ"
- "Update event #45 to change the date"
- "List all locations in London"
- "Delete all draft products"

**Real-World Impact**:
- **E-commerce sites**: Product management
- **Event sites**: Event/registration management  
- **Directory sites**: Listing management
- **Membership sites**: Member data management
- **Custom apps**: Any bespoke data structure

**Implementation Effort**: 1-2 weeks (Generic ExpressToolkit that discovers entities dynamically)

---

### 3. **Groups Management** ⭐⭐⭐

// Get specific group
GET /ccm/api/1.0/groups/3

// Create group
POST /ccm/api/1.0/groups
{
  "name": "Regional Managers",
  "description": "EMEA regional team",
  "folderID": 5
}

// Add users to group
POST /ccm/api/1.0/groups/3/users
{
  "userIDs": [12, 45, 67]
}

// Update group
PUT /ccm/api/1.0/groups/3
{
  "name": "Updated Name"
}

// Delete group
DELETE /ccm/api/1.0/groups/3
```

**Use Cases**:
- "Add all editors to the Content Team group"
- "Create a new group for beta testers"
- "Show me all users in the Administrators group"
- "Remove inactive users from all groups"

**Implementation Effort**: 2-3 days (GroupsToolkit with 6 tools)

---

### 4. **Multisite Management** ⭐

**What API Provides**:
```javascript
// List all sites
GET /ccm/api/1.0/sites

// Get site details
GET /ccm/api/1.0/sites/2

// Create site
POST /ccm/api/1.0/sites
{
  "name": "Regional Site - EU",
  "handle": "eu_site"
}
```

**Use Cases**: (Low priority - most installs are single-site)
- "List all sites in this multisite installation"
- "Create a new site for the EU region"

**Implementation Effort**: 1 day (SitesToolkit)

---

### 5. **Advanced Page Includes** ⭐⭐⭐

**What API Provides** (via `?includes=` parameter):
```javascript
// Get page with rendered content
GET /ccm/api/1.0/pages/123?includes=content

// Get page with all area blocks detailed
GET /ccm/api/1.0/pages/123?includes=areas

// Get page with block content rendered
GET /ccm/api/1.0/pages/123?includes=areas.content

// Multiple includes
GET /ccm/api/1.0/pages/123?includes=custom_attributes,areas,files,content
```

**What We Have**: Partially - we have `get_page_content` and `get_page_blocks` but they're separate calls

**Benefit of API Approach**: Single call gets everything, less overhead

**Implementation Effort**: 1 day (enhance existing tools)

---

## 💡 OpenAPI Auto-Generation Architecture

### How It Would Work

```php
class OpenApiToolkit extends AbstractToolkit
{
    protected SpecGenerator $specGenerator;
    protected array $generatedTools = [];
    
    public function provide(): array
    {
        // Load OpenAPI spec from Concrete CMS
        $spec = $this->specGenerator->generate();
        
        // Auto-generate tools for each endpoint
        foreach ($spec->paths as $path => $operations) {
            foreach ($operations as $method => $operation) {
                $this->generatedTools[] = $this->createToolFromOperation(
                    $path,
                    $method,
                    $operation
                );
            }
        }
        
        return $this->generatedTools;
    }
    
    protected function createToolFromOperation($path, $method, $operation): Tool
    {
        $tool = new Tool(
            $this->generateToolName($path, $method),
            $operation->summary ?? $operation->description
        );
        
        // Add parameters from OpenAPI spec
        foreach ($operation->parameters as $param) {
            $tool->addProperty(new ToolProperty(
                $param->name,
                $this->mapOpenApiType($param->schema->type),
                $param->description,
                $param->required
            ));
        }
        
        // Create callable that makes API request
        $tool->setCallable(function(...$args) use ($path, $method) {
            return $this->makeApiRequest($method, $path, $args);
        });
        
        return $tool;
    }
    
    protected function makeApiRequest(string $method, string $path, array $params): array
    {
        // Use Guzzle or Concrete's HTTP client to call API
        // But better: call the controller directly!
        $controller = $this->resolveController($path);
        return $controller->handle($params);
    }
}
```

### Advantages of This Approach

**1. Zero-Maintenance Coverage**
- **Current**: Must manually implement each new endpoint
- **With OpenAPI**: New endpoints auto-appear when Concrete updates
- **Example**: Concrete adds Calendar API → instantly available

**2. Express Object Support**
- **Current**: Must create ExpressToolkit, discover entities, create CRUD per entity
- **With OpenAPI**: Single generic implementation handles ALL entities
- **Example**: Site adds "Products" entity → instantly controllable

**3. Completeness**
- **Current**: 70 tools covering ~40% of API surface
- **With OpenAPI**: 100+ tools covering ~100% of API surface
- **Example**: Can use System health checks, OAuth management, etc.

**4. Consistency**
- **Current**: Each toolkit has different patterns, error handling
- **With OpenAPI**: Uniform behavior from OpenAPI spec
- **Example**: All tools have same pagination, filtering, includes patterns

---

## ⚖️ Trade-offs

### Advantages of OpenAPI Auto-Generation

| Advantage | Impact |
|-----------|--------|
| **Completeness** | Access to 100+ API endpoints vs 70 manual tools |
| **Future-proof** | Auto-updates with Concrete CMS API changes |
| **Express support** | ANY custom entity works automatically |
| **Zero maintenance** | Don't need to update when API evolves |
| **Consistency** | Uniform error handling, pagination, filtering |
| **Discovery** | Tools expose what's actually available |

### Disadvantages of OpenAPI Auto-Generation

| Disadvantage | Impact |
|--------------|--------|
| **HTTP overhead** | Must make HTTP requests (or call controllers directly) |
| **Less control** | Can't optimize API calls for AI workflow |
| **Generic tools** | May not be as AI-friendly as purpose-built tools |
| **Complex setup** | Need SpecGenerator, request handling, auth |
| **Two sources of truth** | Manual tools + generated tools |
| **Debugging** | Generated code harder to trace than hand-written |

---

## 🎯 Recommended Approach: Hybrid Model

### Best of Both Worlds

```php
protected function tools(): array
{
    $toolkits = [
        // === TIER 1: Hand-Crafted High-Performance Tools ===
        // Core workflows optimized for AI
        new PagesToolkit(),              // 16 tools - includes batch, descendants
        new FilesToolkit(),               // 7 tools - optimized for AI patterns
        new UsersToolkit(),               // 4 tools
        new PageAttributesToolkit(),      // 6 tools - more than API provides
        new UserAttributesToolkit(),      // 6 tools
        new FileAttributesToolkit(),      // 6 tools
        new AttributeSetsToolkit(),       // 4 tools - not in API!
        new TopicsToolkit(),              // 4 tools - not in API!
        new PeopleToolkit(),              // 4 tools - Katalysis exclusive
        
        // === TIER 2: Strategic API Gaps ===
        // Features not worth manual implementation
        new GroupsToolkit(),              // 6 tools from API
        new VersionsToolkit(),            // 3 tools from API
        
        // === TIER 3: Dynamic/Generic Tools ===
        // Auto-generated or discovered
        new ExpressToolkit(),             // Discovers & handles ALL custom entities
        
        // === TIER 4: Optional OpenAPI ===
        // Comprehensive fallback for edge cases
        // new OpenApiToolkit(),          // 100+ generated tools (disabled by default)
    ];
    
    return array_merge($toolkits, ExternalToolkitRegistry::getExternalToolkits());
}
```

### Implementation Priority

**Phase 1: Safety & Critical Gaps** (1 week)
1. 🔴 **VersionsToolkit** - **HIGHEST PRIORITY** - Safety mechanism for all editing operations
   - Prevents content loss incidents (see VERSION_MANAGEMENT_CASE_STUDY.md)
   - Enables instant recovery from mistakes
   - Required for safe AI-assisted content editing
   - **5 tools, 4-5 days implementation**

**Phase 2: Strategic Gaps** (2-3 weeks)
2. ✅ ExpressToolkit - Maximum ROI, handles ANY custom entity
3. ✅ GroupsToolkit - Common need, clean API

**Phase 3: Enhanced Includes** (2-3 days)
4. ✅ Add `includes` parameter to existing tools
5. ✅ Single-call page retrieval with all details

**Phase 4: OpenAPI Generator** (Optional, 2-3 weeks)
6. ⚠️ Build spec parser and tool generator
7. ⚠️ Only if user demand justifies complexity

---

## 📈 Impact Analysis

### What We Gain Per Implementation

**VersionsToolkit**: 🔴 **CRITICAL**
- **Effort**: 4-5 days
- **Tools**: +5 (list, approve, create_draft, discard, get_content)
- **Use cases**: Safe content editing, instant recovery from mistakes, preview changes
- **ROI**: **CRITICAL** - Prevents data loss, enables safe AI editing
- **Justification**: Real incident showed content loss with no recovery (see case study)

**ExpressToolkit**: ⭐⭐⭐⭐⭐
- **Effort**: 1-2 weeks
- **Tools**: +7-10 (base CRUD + discovery)
- **Use cases**: ANY custom entity on ANY site
- **ROI**: **VERY HIGH** - Universal, future-proof

**GroupsToolkit**: ⭐⭐⭐
- **Effort**: 2-3 days
- **Tools**: +6
- **Use cases**: User organization, permission management
- **ROI**: Medium - common need

**OpenApiToolkit**: ⭐
- **Effort**: 2-3 weeks
- **Tools**: +100-150 (everything else)
- **Use cases**: Edge cases, specialized features, future endpoints
- **ROI**: Low - high cost, limited benefit over strategic toolkits

---

## 💪 Our Current Advantages (Don't Lose These!)

### Why Our Native Approach Is Better

1. **Batch Operations** ⭐⭐⭐⭐⭐
   - API: Individual operations only
   - Us: `batch_update_page_content` processes 50 blocks in one call
   - **Impact**: 50x fewer tool calls for bulk updates

2. **Descendant Filtering** ⭐⭐⭐⭐
   - API: Parent-only filtering
   - Us: `includeDescendants=true` gets entire tree
   - **Impact**: Solved the press release migration issue

3. **Block ID Remapping** ⭐⭐⭐⭐⭐
   - API: No automatic remapping
   - Us: `BlockIdRemapper` handles versioning complexity
   - **Impact**: Safe batch updates without manual ID tracking

4. **Extended Attributes** ⭐⭐⭐
   - API: Basic attribute CRUD via includes
   - Us: Dedicated toolkits with sets, categories, advanced queries
   - **Impact**: More powerful attribute management

5. **Custom Toolkits** ⭐⭐⭐⭐
   - API: Generic endpoints only
   - Us: PeopleToolkit, TopicsToolkit for site-specific entities
   - **Impact**: Deep integration with Katalysis Pro features

6. **No HTTP Overhead** ⭐⭐⭐
   - API: HTTP request/response cycle
   - Us: Direct PHP method calls
   - **Impact**: Sub-100ms operations vs 200-500ms API calls

7. **Native Permissions** ⭐⭐⭐⭐
   - API: Token-based auth
   - Us: Concrete's session/permission system
   - **Impact**: Works exactly like dashboard, respects all permission nuances

8. **Optimized for AI** ⭐⭐⭐⭐⭐
   - API: Generic JSON responses
   - Us: Responses crafted for LLM consumption (tables, summaries, counts)
   - **Impact**: Better AI understanding and fewer follow-up questions

---

## 🎯 Final Recommendation

### Implement Safety First, Then Strategic Gaps

**URGENT - DO THIS FIRST**:
1. 🔴 **VersionsToolkit** - **CRITICAL SAFETY MECHANISM**
   - Real incident shows content loss from AI edits (case study documented)
   - No recovery path currently available  
   - Blocks all safe content editing workflows
   - **Implementation: 4-5 days**

**DO THIS NEXT**:
2. ✅ **ExpressToolkit** - Maximum ROI, handles ANY custom entity, future-proof
3. ✅ **GroupsToolkit** - Fill obvious gap, common need

**DON'T DO THIS**:
1. ❌ **OpenApiToolkit** - Complexity not worth marginal benefit
2. ❌ **SitesToolkit** - Too specialized (multisite rare)
3. ❌ **SystemToolkit** - Not AI-friendly use cases

**KEEP DOING THIS**:
1. ✅ **Manual high-quality tools** - Better than auto-generated
2. ✅ **Batch operations** - API can't provide these
3. ✅ **AI-optimized responses** - Our differentiator

**Rationale for Priority Change**:
- Originally planned Express first (new capability)
- Case study revealed Versions are **safety critical** (prevent data loss)
- Real user experienced content loss with no recovery path
- Versions enable safe editing for ALL tools (Pages, Blocks, Files, etc.)
- Express can wait; safety cannot

---

## 📊 Updated Total Coverage

**After Implementing Strategic Toolkits**:

| Category | Tools | Coverage |
|----------|-------|----------|
| Pages | 16 | ✅ Complete |
| Blocks | 6 | ✅ Complete |
| Files | 7 | ✅ Complete |
| Users | 4 | ✅ Complete |
| Attributes | 18 | ✅ Complete |
| Topics | 4 | ✅ Complete |
| People | 4 | ✅ Complete |
| **Versions** | **5** | 🔴 **CRITICAL - Safety** |
| **Express** | **8** | ✅ **New!** |
| **Groups** | **6** | ✅ **New!** |
| **Total** | **78-84** | **~95% of needs** |

**What We're Missing** (and why it's OK):
- Sites (multisite management) - Rare use case
- System (health checks) - Not AI-appropriate
- OAuth (client management) - Admin function

**Priority Rationale**:
- **Versions** moved to #1 priority after case study showed content loss incident
- **Express** remains high value but can wait (new capability, not safety issue)
- **Groups** fills gap but less urgent than safety mechanisms

---

## ⏱️ Implementation Timeline

**Week 1**: VersionsToolkit ⭐ **PRIORITY**
- Day 1: Implement `approve_page_version` (critical recovery tool)
- Day 2: Implement `list_page_versions` and `get_version_content`
- Day 3: Implement `create_page_draft` and `discard_draft_version`
- Day 4: Update AI instructions with safety protocol
- Day 5: Testing and documentation

**Week 2**: GroupsToolkit
- Day 1-2: Implement 6 tools (list, get, create, update, delete, add_users)
- Day 3: Testing and error handling
- Day 4: Documentation and AI instruction updates
- Day 5: QA and refinement

**Week 3-4**: ExpressToolkit
- Days 1-3: Entity discovery mechanism
- Days 4-6: Generic CRUD implementation
- Days 7-8: Attribute handling for express entries
- Days 9-10: Testing with real express objects
- Days 11-12: Documentation

**Total Effort**: 3-4 weeks to go from 70 tools to 79-84 tools and cover 95% of practical use cases

**Why VersionsToolkit First**: Real incident (see VERSION_MANAGEMENT_CASE_STUDY.md) shows content loss from AI edits with no recovery path. This is a **safety issue**, not just a feature gap.

---

## 🎓 Key Learnings

### What OpenAPI Auto-Generation Would Give Us
1. ✅ Express Object support (HIGH VALUE)
2. ✅ Groups management (MEDIUM VALUE)
3. ✅ Version operations (MEDIUM VALUE)
4. ⚠️ 50+ other endpoints (LOW VALUE - edge cases)

### What We'd Lose
1. ❌ Direct PHP performance
2. ❌ Batch operation optimizations
3. ❌ AI-optimized responses
4. ❌ Code clarity and debuggability
5. ❌ Architectural simplicity

### Verdict
**Target strategic gaps with hand-crafted toolkits rather than auto-generating everything.**

We get 95% coverage with 1/10th the complexity.

---

**Last Updated**: 7 August 2026  
**Status**: Strategic gaps identified, hybrid approach recommended  
**Next Step**: Implement ExpressToolkit (highest ROI)
