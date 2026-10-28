<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools;

use Concrete\Core\Attribute\Key\CollectionKey as PageAttributeKey;
use Concrete\Core\Attribute\Key\UserKey as UserAttributeKey;
use Concrete\Core\Attribute\Key\FileKey as FileAttributeKey;
use Concrete\Core\Attribute\Type as AttributeType;
use Concrete\Core\Attribute\Category\CategoryService;
use Concrete\Core\Attribute\Set as AttributeSet;
use Concrete\Core\Entity\Attribute\Key\PageKey;
use Concrete\Core\Entity\Attribute\Key\Key;
use Concrete\Core\Entity\Attribute\Type as AttributeTypeEntity;
use Concrete\Core\Support\Facade\Application;
use Concrete\Core\Page\Page;
use Concrete\Core\Permission\Checker;
use Concrete\Core\User\User;
use Katalysis\NeuronAi\Tools\Utilities\PageByName;
use Katalysis\NeuronAi\Tools\Utilities\DraftCleanup;
use Katalysis\NeuronAi\Tools\Utilities\DraftState;
use Katalysis\NeuronAi\Tools\Utilities\EditModeGuard;
use Katalysis\NeuronAi\Tools\Utilities\PageDraft;
use Katalysis\NeuronAi\Tools\Utilities\SystemPageGuard;
use NeuronAI\Tools\Tool;
use Katalysis\NeuronAi\Tools\Support\CallableTool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Toolkits\AbstractToolkit;

/**
 * Concrete CMS Attributes toolkit for Neuron AI
 * 
 * Provides native tools for attribute management.
 * Allows creation and management of page, file, and user attributes.
 */
class AttributesToolkit extends AbstractToolkit
{
    public function guidelines(): ?string
    {
        return "Use these tools to manage attributes and attribute sets in Concrete CMS. You can list existing attributes, create new page/user/file attributes, get attribute details, and set attribute values on pages. You can also manage attribute sets: list them, create/delete sets, and add/remove attributes from sets. When setting page attributes, you can identify pages by ID, path, or name (fuzzy matching supported). For select attributes, option values are matched case-insensitively. Always check that attribute handles don't already exist before creating new ones. Attribute handles should be lowercase with underscores (e.g., 'nav_group', 'custom_field'). Attribute sets help organize attributes in the CMS interface.";
    }

    public function provide(): array
    {
        return [
            $this->makeListPageAttributesTool(),
            $this->makeGetAttributeDetailsTool(),
            $this->makeCreatePageAttributeTool(),
            $this->makeUpdateSelectAttributeOptionsTool(),
            $this->makeSetPageAttributeValueTool(),
            $this->makeListAttributeTypesTool(),
            $this->makeListAttributeSetsTool(),
            $this->makeCreateAttributeSetTool(),
            $this->makeDeleteAttributeSetTool(),
            $this->makeAddAttributeToSetTool(),
            $this->makeRemoveAttributeFromSetTool(),
        ];
    }

    protected function makeListPageAttributesTool(): Tool
    {
        $tool = new CallableTool(
            'list_page_attributes',
            'List all page attributes defined in the system with their handles, names, and types.'
        );

        $tool->setHandler($this->guarded(function (): array {
            $attributes = PageAttributeKey::getList();
            $results = [];

            foreach ($attributes as $ak) {
                $type = $ak->getAttributeType();
                $results[] = [
                    'handle' => $ak->getAttributeKeyHandle(),
                    'name' => $ak->getAttributeKeyDisplayName(),
                    'type' => $type ? $type->getAttributeTypeHandle() : 'unknown',
                    'searchable' => (bool) $ak->isAttributeKeySearchable(),
                    'indexed' => (bool) $ak->isAttributeKeyContentIndexed(),
                ];
            }

            return [
                'totalAttributes' => count($results),
                'attributes' => $results,
            ];
        }));

        return $tool;
    }

    protected function makeListAttributeTypesTool(): Tool
    {
        $tool = new CallableTool(
            'list_attribute_types',
            'List all available attribute types that can be used when creating new attributes.'
        );

        $tool->setHandler($this->guarded(function (): array {
            $app = Application::getFacadeApplication();
            $types = AttributeType::getList();
            $results = [];

            foreach ($types as $type) {
                $results[] = [
                    'handle' => $type->getAttributeTypeHandle(),
                    'name' => $type->getAttributeTypeName(),
                ];
            }

            return [
                'totalTypes' => count($results),
                'types' => $results,
                'commonTypes' => [
                    'text' => 'Text (short text field)',
                    'textarea' => 'Text Area (long text field)',
                    'boolean' => 'Checkbox (yes/no)',
                    'date_time' => 'Date/Time picker',
                    'select' => 'Select/Dropdown (predefined options)',
                    'image_file' => 'Image/File selector',
                    'number' => 'Number field',
                    'url' => 'URL field',
                ],
            ];
        }));

        return $tool;
    }

    protected function makeGetAttributeDetailsTool(): Tool
    {
        $tool = new CallableTool(
            'get_attribute_details',
            'Get detailed information about a specific page attribute by handle.'
        );

        $tool->addProperty(new ToolProperty(
            'handle',
            PropertyType::STRING,
            'Attribute handle (e.g., "meta_title", "exclude_nav")',
            true
        ));

        $tool->setHandler($this->guarded(function (?string $handle = null): array {
            if (!$handle) {
                throw new \RuntimeException("Attribute handle is required");
            }

            $ak = PageAttributeKey::getByHandle($handle);
            
            if (!$ak) {
                throw new \RuntimeException("Page attribute with handle '{$handle}' not found");
            }

            $type = $ak->getAttributeType();
            $settings = [];

            // Get type-specific settings
            if ($type && $type->getAttributeTypeHandle() === 'select') {
                try {
                    $controller = $ak->getController();
                    if (method_exists($controller, 'getOptions')) {
                        $options = $controller->getOptions();
                        if (is_array($options)) {
                            $settings['options'] = array_map(function($opt) {
                                return is_object($opt) && method_exists($opt, 'getSelectAttributeOptionValue') 
                                    ? $opt->getSelectAttributeOptionValue() 
                                    : (string)$opt;
                            }, $options);
                        }
                    }
                } catch (\Exception $e) {
                    $settings['optionsError'] = $e->getMessage();
                }
            }

            return [
                'handle' => $ak->getAttributeKeyHandle(),
                'name' => $ak->getAttributeKeyDisplayName(),
                'type' => $type ? $type->getAttributeTypeHandle() : 'unknown',
                'typeName' => $type ? $type->getAttributeTypeName() : 'Unknown',
                'searchable' => (bool) $ak->isAttributeKeySearchable(),
                'indexed' => (bool) $ak->isAttributeKeyContentIndexed(),
                'required' => false, // Note: Concrete doesn't have a global required flag
                'settings' => $settings,
            ];
        }));

        return $tool;
    }

    protected function makeCreatePageAttributeTool(): Tool
    {
        $tool = new CallableTool(
            'create_page_attribute',
            'Create a new page attribute with specified type and settings. Use list_attribute_types to see available types.'
        );

        $tool->addProperty(new ToolProperty(
            'handle',
            PropertyType::STRING,
            'Unique attribute handle (lowercase with underscores, e.g., "nav_group", "custom_field")',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'name',
            PropertyType::STRING,
            'Display name for the attribute (e.g., "Navigation Group", "Custom Field")',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'type',
            PropertyType::STRING,
            'Attribute type handle (e.g., "text", "select", "boolean", "textarea"). Use list_attribute_types for all options.',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'options',
            PropertyType::ARRAY,
            'For select type: array of option values (e.g., ["option1", "option2"]). Optional for other types.',
            false
        ));

        $tool->addProperty(new ToolProperty(
            'searchable',
            PropertyType::BOOLEAN,
            'Whether this attribute should be searchable in page lists (default: false)',
            false
        ));

        $tool->addProperty(new ToolProperty(
            'indexed',
            PropertyType::BOOLEAN,
            'Whether this attribute content should be indexed for search (default: false)',
            false
        ));

        $tool->setHandler($this->guarded(function (
            ?string $handle = null,
            ?string $name = null,
            ?string $type = null,
            ?array $options = null,
            ?bool $searchable = false,
            ?bool $indexed = false
        ): array {
            if (!$handle || !$name || !$type) {
                throw new \RuntimeException("handle, name, and type are required");
            }

            // P3.5-1 decision 1: Concrete's own gate for page attributes, as in PageAttributesToolkit.
            \Katalysis\NeuronAi\Access\ObjectPermissions::deny(\Katalysis\NeuronAi\Access\ObjectPermissions::viewDashboardPage('/dashboard/pages/attributes', 'create a page attribute'));

            // Validate handle format
            if (!preg_match('/^[a-z][a-z0-9_]*$/', $handle)) {
                throw new \RuntimeException("Invalid handle format. Use lowercase letters, numbers, and underscores only. Must start with a letter.");
            }

            // Check if attribute already exists
            $existing = PageAttributeKey::getByHandle($handle);
            if ($existing) {
                throw new \RuntimeException("Page attribute with handle '{$handle}' already exists");
            }

            // Get attribute type
            $attributeType = AttributeType::getByHandle($type);
            if (!$attributeType) {
                throw new \RuntimeException("Invalid attribute type '{$type}'. Use list_attribute_types to see available types.");
            }

            $app = Application::getFacadeApplication();
            $categoryService = $app->make(CategoryService::class);
            $pageCategory = $categoryService->getByHandle('collection');

            if (!$pageCategory) {
                throw new \RuntimeException("Could not load page attribute category");
            }

            // Prepare settings based on type
            $settings = [
                'akHandle' => $handle,
                'akName' => $name,
                'akIsSearchable' => $searchable ? 1 : 0,
                'akIsSearchableIndexed' => $indexed ? 1 : 0,
            ];

            // Add type-specific settings
            if ($type === 'select' && is_array($options) && count($options) > 0) {
                $settings['akSelectAllowMultipleValues'] = 0;
                $settings['akSelectAllowOtherValues'] = 0;
                $settings['akSelectOptionDisplayOrder'] = 'display_asc';
                
                // Format options for Concrete
                $optionList = [];
                foreach ($options as $index => $value) {
                    $optionList[] = [
                        'value' => (string) $value,
                        'isEndUserAdded' => false,
                        'displayOrder' => $index,
                    ];
                }
                $settings['akSelectOptions'] = $optionList;
            }

            try {
                // Create the attribute key
                $ak = $pageCategory->getController()->add(
                    $attributeType,
                    $settings,
                    $pkg = null
                );

                if (!$ak || !$ak instanceof Key) {
                    throw new \RuntimeException("Failed to create attribute - unknown error");
                }

                return [
                    'success' => true,
                    'message' => "Page attribute '{$handle}' created successfully",
                    'attribute' => [
                        'handle' => $ak->getAttributeKeyHandle(),
                        'name' => $ak->getAttributeKeyDisplayName(),
                        'type' => $type,
                        'searchable' => $searchable,
                        'indexed' => $indexed,
                    ],
                ];

            } catch (\Exception $e) {
                throw new \RuntimeException("Failed to create attribute: " . $e->getMessage());
            }
        }));

        return $tool;
    }

    protected function makeUpdateSelectAttributeOptionsTool(): Tool
    {
        $tool = new CallableTool(
            'update_select_attribute_options',
            'Update the options for an existing select/dropdown page attribute. Replaces all existing options.'
        );

        $tool->addProperty(new ToolProperty(
            'handle',
            PropertyType::STRING,
            'Handle of the select attribute to update (e.g., "nav_group")',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'options',
            PropertyType::ARRAY,
            'Array of option values to set (e.g., ["option1", "option2"]). This replaces all existing options.',
            true
        ));

        $tool->setHandler($this->guarded(function (
            ?string $handle = null,
            ?array $options = null
        ): array {
            if (!$handle) {
                throw new \RuntimeException("Attribute handle is required");
            }

            if (!is_array($options) || count($options) === 0) {
                throw new \RuntimeException("Options array is required and must contain at least one option");
            }

            // P3.5-1 decision 1: Concrete's own gate for page attributes, as in PageAttributesToolkit.
            \Katalysis\NeuronAi\Access\ObjectPermissions::deny(\Katalysis\NeuronAi\Access\ObjectPermissions::viewDashboardPage('/dashboard/pages/attributes', 'change the options of a page attribute'));

            // Get the attribute
            $ak = PageAttributeKey::getByHandle($handle);
            if (!$ak) {
                throw new \RuntimeException("Page attribute with handle '{$handle}' not found");
            }

            // Verify it's a select type
            $type = $ak->getAttributeType();
            if (!$type || $type->getAttributeTypeHandle() !== 'select') {
                throw new \RuntimeException("Attribute '{$handle}' is not a select type. Current type: " . ($type ? $type->getAttributeTypeHandle() : 'unknown'));
            }

            try {
                $app = Application::getFacadeApplication();
                $em = $app->make('Doctrine\ORM\EntityManagerInterface');
                
                // Get the attribute key settings (SelectSettings)
                $settings = $ak->getAttributeKeySettings();
                if (!$settings) {
                    throw new \RuntimeException("Could not load attribute settings for '{$handle}'");
                }
                
                // Get the option list
                $optionList = $settings->getOptionList();
                if (!$optionList) {
                    throw new \RuntimeException("Could not load option list for '{$handle}'");
                }
                
                // Mark all existing options as deleted
                foreach ($optionList->getOptions() as $existingOption) {
                    $existingOption->setIsOptionDeleted(true);
                }
                
                // Add new options
                foreach ($options as $index => $value) {
                    $option = new \Concrete\Core\Entity\Attribute\Value\Value\SelectValueOption();
                    $option->setSelectAttributeOptionValue((string) $value);
                    $option->setOptionList($optionList);
                    $option->setDisplayOrder($index);
                    $option->setIsEndUserAdded(false);
                    $optionList->getOptions()->add($option);
                    $em->persist($option);
                }
                
                // Persist the changes
                $em->persist($optionList);
                $em->persist($settings);
                $em->flush();
                
                // Clear cache
                $cache = $app->make('cache/request');
                if ($cache) {
                    $cache->delete('page/attribute/key/handle/' . $handle);
                }

                return [
                    'success' => true,
                    'message' => "Select attribute '{$handle}' options updated successfully with " . count($options) . " options",
                    'attribute' => [
                        'handle' => $handle,
                        'name' => $ak->getAttributeKeyDisplayName(),
                        'type' => 'select',
                        'optionCount' => count($options),
                        'options' => $options,
                    ],
                ];

            } catch (\Exception $e) {
                throw new \RuntimeException("Failed to update attribute options: " . $e->getMessage());
            }
        }));

        return $tool;
    }

    protected function makeSetPageAttributeValueTool(): Tool
    {
        $tool = new CallableTool(
            'set_page_attribute_value',
            'Set the value of a page attribute for a specific page. Handles all attribute types including select/dropdown. Supports fuzzy matching for page names and option values.'
        );

        $tool->addProperty(new ToolProperty(
            'pageID',
            PropertyType::INTEGER,
            'ID of the page to update. If not provided, pagePath or pageName must be provided.',
            false
        ));

        $tool->addProperty(new ToolProperty(
            'pagePath',
            PropertyType::STRING,
            'Path of the page (e.g., "/indexes"). Alternative to pageID.',
            false
        ));

        $tool->addProperty(new ToolProperty(
            'pageName',
            PropertyType::STRING,
            'Name of the page (e.g., "Indexes"). Will do fuzzy matching. Alternative to pageID.',
            false
        ));

        $tool->addProperty(new ToolProperty(
            'handle',
            PropertyType::STRING,
            'Attribute handle (e.g., "nav_group", "meta_title")',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'value',
            PropertyType::STRING,
            'The value to set. For select attributes, case-insensitive matching is used.',
            true
        ));

        $tool->setHandler($this->guarded(function (
            ?int $pageID = null,
            ?string $pagePath = null,
            ?string $pageName = null,
            ?string $handle = null,
            ?string $value = null
        ): array {
            if (!$pageID && !$pagePath && !$pageName) {
                throw new \RuntimeException("Either pageID, pagePath, or pageName is required");
            }

            if (!$handle) {
                throw new \RuntimeException("Attribute handle is required");
            }

            if ($value === null) {
                throw new \RuntimeException("Value is required");
            }

            // Get the page
            $page = null;
            if ($pageID) {
                $page = \Concrete\Core\Page\Page::getByID($pageID);
            } elseif ($pagePath) {
                $page = \Concrete\Core\Page\Page::getByPath($pagePath);
            } elseif ($pageName) {
                // Exact, then ignoring case, then contains (BF-2); one page or an error.
                $page = PageByName::find($pageName);
            }
            
            if (!$page || $page->isError()) {
                throw new \RuntimeException("Page not found");
            }
            SystemPageGuard::refuse($page);
            EditModeGuard::refuse($page);

            // P3.5-1 decision 2: an attribute is a page property (Edit Properties), as in PageAttributesToolkit.
            \Katalysis\NeuronAi\Access\ObjectPermissions::deny(\Katalysis\NeuronAi\Access\ObjectPermissions::editPageProperties($page));

            // Get the attribute
            $ak = PageAttributeKey::getByHandle($handle);
            if (!$ak) {
                throw new \RuntimeException("Page attribute with handle '{$handle}' not found");
            }

            $app = Application::getFacadeApplication();
            $em = $app->make('Doctrine\ORM\EntityManagerInterface');

            // Check if it's a select attribute
            $type = $ak->getAttributeType();
            $attributeValue = $value;

            if ($type && $type->getAttributeTypeHandle() === 'select') {
                // For select attributes, do fuzzy matching on option values
                $settings = $ak->getAttributeKeySettings();
                if ($settings) {
                    $optionList = $settings->getOptionList();
                    if ($optionList) {
                        $options = $optionList->getOptions();
                        $matchedOption = null;
                        $availableOptions = [];

                        // Try exact match first
                        foreach ($options as $option) {
                            if (!$option->isOptionDeleted()) {
                                $optionValue = $option->getSelectAttributeOptionValue();
                                $availableOptions[] = $optionValue;
                                
                                if ($optionValue === $value) {
                                    $matchedOption = $option;
                                    break;
                                }
                            }
                        }

                        // Try case-insensitive match
                        if (!$matchedOption) {
                            foreach ($options as $option) {
                                if (!$option->isOptionDeleted()) {
                                    if (strtolower($option->getSelectAttributeOptionValue()) === strtolower($value)) {
                                        $matchedOption = $option;
                                        break;
                                    }
                                }
                            }
                        }

                        // Try partial match
                        if (!$matchedOption) {
                            foreach ($options as $option) {
                                if (!$option->isOptionDeleted()) {
                                    $optionValue = strtolower($option->getSelectAttributeOptionValue());
                                    $searchValue = strtolower($value);
                                    
                                    if (strpos($optionValue, $searchValue) !== false || 
                                        strpos($searchValue, $optionValue) !== false) {
                                        $matchedOption = $option;
                                        break;
                                    }
                                }
                            }
                        }

                        if (!$matchedOption) {
                            throw new \RuntimeException("Select option '{$value}' not found for attribute '{$handle}'. Available options: " . 
                                implode(', ', $availableOptions));
                        }

                        // Use the option object as the value
                        $attributeValue = $matchedOption;
                    }
                }
            }

            // Edit the page's draft: the newest version if it is already a draft, else a new one.
            $pageToModify = $this->getDraftOf($page);

            // Set the attribute
            $pageToModify->setAttribute($ak, $attributeValue);

            // Get the actual value that was set for confirmation
            $setValue = $pageToModify->getAttribute($handle);
            if (is_object($setValue) && method_exists($setValue, 'getSelectAttributeOptionValue')) {
                $setValueDisplay = $setValue->getSelectAttributeOptionValue();
            } else {
                $setValueDisplay = (string) $setValue;
            }

            return [
                'success' => true,
                'message' => "Attribute '{$handle}' set to '{$setValueDisplay}' on page '{$page->getCollectionName()}'",
                'page' => [
                    'id' => $page->getCollectionID(),
                    'name' => $page->getCollectionName(),
                    'path' => $page->getCollectionPath(),
                ],
                'attribute' => [
                    'handle' => $handle,
                    'name' => $ak->getAttributeKeyDisplayName(),
                    'type' => $type ? $type->getAttributeTypeHandle() : 'unknown',
                    'valueSet' => $setValueDisplay,
                ],
            ] + DraftState::of($pageToModify);
        }));

        return $tool;
    }

    protected function makeListAttributeSetsTool(): Tool
    {
        $tool = new CallableTool(
            'list_attribute_sets',
            'List all attribute sets for a category (page, user, or file)'
        );

        $tool->addProperty(new ToolProperty(
            'category',
            PropertyType::STRING,
            'Category handle: collection (pages), user, or file',
            false
        ));

        $tool->setHandler($this->guarded(function (?string $category = 'collection'): array {
            // Get category service
            $app = Application::getFacadeApplication();
            $categoryService = $app->make(CategoryService::class);
            $categoryEntity = $categoryService->getByHandle($category);
            
            if (!$categoryEntity) {
                throw new \RuntimeException("Category '{$category}' not found. Use 'collection' for pages, 'user', or 'file'.");
            }

            // Get sets for this category
            $sets = $categoryEntity->getController()->getSetManager()->getAttributeSets();
            
            $setsList = [];
            foreach ($sets as $set) {
                $attributes = [];
                foreach ($set->getAttributeKeys() as $key) {
                    $attributes[] = [
                        'handle' => $key->getAttributeKeyHandle(),
                        'name' => $key->getAttributeKeyDisplayName(),
                    ];
                }
                
                $setsList[] = [
                    'id' => $set->getAttributeSetID(),
                    'handle' => $set->getAttributeSetHandle(),
                    'name' => $set->getAttributeSetName(),
                    'attributes' => $attributes,
                ];
            }

            return [
                'success' => true,
                'category' => $category,
                'sets' => $setsList,
                'count' => count($setsList),
            ];
        }));

        return $tool;
    }

    protected function makeCreateAttributeSetTool(): Tool
    {
        $tool = new CallableTool(
            'create_attribute_set',
            'Create a new attribute set in a category'
        );

        $tool->addProperty(new ToolProperty(
            'handle',
            PropertyType::STRING,
            'Unique handle for the set (lowercase, underscores)',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'name',
            PropertyType::STRING,
            'Display name for the set',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'category',
            PropertyType::STRING,
            'Category: collection (pages), user, or file',
            false
        ));

        $tool->setHandler($this->guarded(function (
            ?string $handle = null,
            ?string $name = null,
            ?string $category = 'collection'
        ): array {
            if (empty($handle) || empty($name)) {
                throw new \RuntimeException('Both handle and name are required');
            }

            // Get category service
            $app = Application::getFacadeApplication();
            $categoryService = $app->make(CategoryService::class);
            $categoryEntity = $categoryService->getByHandle($category);
            
            if (!$categoryEntity) {
                throw new \RuntimeException("Category '{$category}' not found");
            }

            // Create the set
            $set = $categoryEntity->addSet($handle, $name, null);

            return [
                'success' => true,
                'message' => "Attribute set '{$name}' created successfully",
                'set' => [
                    'id' => $set->getAttributeSetID(),
                    'handle' => $set->getAttributeSetHandle(),
                    'name' => $set->getAttributeSetName(),
                ],
            ];
        }));

        return $tool;
    }

    protected function makeDeleteAttributeSetTool(): Tool
    {
        $tool = new CallableTool(
            'delete_attribute_set',
            'Delete an attribute set (attributes are not deleted, just ungrouped)'
        );

        $tool->addProperty(new ToolProperty(
            'handle',
            PropertyType::STRING,
            'Handle of the set to delete',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'category',
            PropertyType::STRING,
            'Category: collection (pages), user, or file',
            false
        ));

        $tool->setHandler($this->guarded(function (
            ?string $handle = null,
            ?string $category = 'collection'
        ): array {
            if (empty($handle)) {
                throw new \RuntimeException('Set handle is required');
            }

            // Get category service
            $app = Application::getFacadeApplication();
            $categoryService = $app->make(CategoryService::class);
            $categoryEntity = $categoryService->getByHandle($category);
            
            if (!$categoryEntity) {
                throw new \RuntimeException("Category '{$category}' not found");
            }

            // Get the set
            $set = AttributeSet::getByHandle($handle);
            if (!$set) {
                throw new \RuntimeException("Attribute set '{$handle}' not found");
            }

            $setName = $set->getAttributeSetName();
            
            // Delete the set
            $set->delete();

            return [
                'success' => true,
                'message' => "Attribute set '{$setName}' deleted successfully",
            ];
        }));

        return $tool;
    }

    protected function makeAddAttributeToSetTool(): Tool
    {
        $tool = new CallableTool(
            'add_attribute_to_set',
            'Add an attribute to an attribute set'
        );

        $tool->addProperty(new ToolProperty(
            'attribute_handle',
            PropertyType::STRING,
            'Handle of the attribute to add',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'set_handle',
            PropertyType::STRING,
            'Handle of the set to add to',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'category',
            PropertyType::STRING,
            'Category: collection (pages), user, or file',
            false
        ));

        $tool->setHandler($this->guarded(function (
            ?string $attribute_handle = null,
            ?string $set_handle = null,
            ?string $category = 'collection'
        ): array {
            if (empty($attribute_handle) || empty($set_handle)) {
                throw new \RuntimeException('Both attribute_handle and set_handle are required');
            }

            // Get attribute key
            $ak = null;
            switch ($category) {
                case 'collection':
                    $ak = PageAttributeKey::getByHandle($attribute_handle);
                    break;
                case 'user':
                    $ak = UserAttributeKey::getByHandle($attribute_handle);
                    break;
                case 'file':
                    $ak = FileAttributeKey::getByHandle($attribute_handle);
                    break;
            }

            if (!$ak) {
                throw new \RuntimeException("Attribute '{$attribute_handle}' not found in category '{$category}'");
            }

            // Get the set
            $set = AttributeSet::getByHandle($set_handle);
            if (!$set) {
                throw new \RuntimeException("Attribute set '{$set_handle}' not found");
            }

            // Add attribute to set
            $ak->setAttributeSet($set);

            return [
                'success' => true,
                'message' => "Attribute '{$ak->getAttributeKeyDisplayName()}' added to set '{$set->getAttributeSetName()}'",
                'attribute' => [
                    'handle' => $ak->getAttributeKeyHandle(),
                    'name' => $ak->getAttributeKeyDisplayName(),
                ],
                'set' => [
                    'handle' => $set->getAttributeSetHandle(),
                    'name' => $set->getAttributeSetName(),
                ],
            ];
        }));

        return $tool;
    }

    protected function makeRemoveAttributeFromSetTool(): Tool
    {
        $tool = new CallableTool(
            'remove_attribute_from_set',
            'Remove an attribute from its attribute set (ungroup it)'
        );

        $tool->addProperty(new ToolProperty(
            'attribute_handle',
            PropertyType::STRING,
            'Handle of the attribute to remove from its set',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'category',
            PropertyType::STRING,
            'Category: collection (pages), user, or file',
            false
        ));

        $tool->setHandler($this->guarded(function (
            ?string $attribute_handle = null,
            ?string $category = 'collection'
        ): array {
            if (empty($attribute_handle)) {
                throw new \RuntimeException('attribute_handle is required');
            }

            // Get attribute key
            $ak = null;
            switch ($category) {
                case 'collection':
                    $ak = PageAttributeKey::getByHandle($attribute_handle);
                    break;
                case 'user':
                    $ak = UserAttributeKey::getByHandle($attribute_handle);
                    break;
                case 'file':
                    $ak = FileAttributeKey::getByHandle($attribute_handle);
                    break;
            }

            if (!$ak) {
                throw new \RuntimeException("Attribute '{$attribute_handle}' not found in category '{$category}'");
            }

            $currentSet = $ak->getAttributeSet();
            $setName = $currentSet ? $currentSet->getAttributeSetName() : 'none';

            // Remove from set (set to null)
            $ak->setAttributeSet(null);

            return [
                'success' => true,
                'message' => "Attribute '{$ak->getAttributeKeyDisplayName()}' removed from set '{$setName}'",
                'attribute' => [
                    'handle' => $ak->getAttributeKeyHandle(),
                    'name' => $ak->getAttributeKeyDisplayName(),
                ],
            ];
        }));

        return $tool;
    }

    /**
     * Wrapper for guarding tool execution with permission checks
     */
    protected function guarded(callable $callable): callable
    {
        return function (...$args) use ($callable) {
            // Basic authentication check
            $u = new User();
            if (!$u->isRegistered()) {
                throw new \RuntimeException('Authentication required: Please log in to use this tool.');
            }

            // A call that fails leaves no draft it made behind (BF-3).
            return DraftCleanup::run(static fn () => $callable(...$args));
        };
    }

    /**
     * The version of the page to edit (see PageDraft::of()).
     */
    protected function getDraftOf(Page $page): Page
    {
        return PageDraft::of($page);
    }
}
