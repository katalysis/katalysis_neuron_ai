<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools;

use Concrete\Core\Attribute\Key\CollectionKey as PageAttributeKey;
use Concrete\Core\Attribute\Set as AttributeSet;
use Concrete\Core\Attribute\Type as AttributeType;
use Concrete\Core\Entity\Attribute\Key\Key;
use Concrete\Core\Support\Facade\Application;
use Concrete\Core\Permission\Checker;
use Concrete\Core\User\User;
use Katalysis\NeuronAi\Tools\Utilities\PageByName;
use Katalysis\NeuronAi\Tools\Utilities\PageDraft;
use Katalysis\NeuronAi\Tools\Utilities\SystemPageGuard;
use NeuronAI\Tools\Tool;
use Katalysis\NeuronAi\Tools\Support\CallableTool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Toolkits\AbstractToolkit;

/**
 * Page Attributes toolkit for Neuron AI
 * 
 * Provides tools for managing page (collection) attributes in Concrete CMS.
 */
class PageAttributesToolkit extends AbstractToolkit
{
    public function guidelines(): ?string
    {
        return "Use these tools to manage page attributes in Concrete CMS. You can list existing page attributes, get detailed information about specific attributes, create new page attributes with various types (text, select, boolean, etc.), update select attribute options, and set attribute values on pages. When setting page attributes, you can identify pages by ID, path, or name (fuzzy matching supported). For select attributes, option values are matched case-insensitively. Always check that attribute handles don't already exist before creating new ones. Attribute handles should be lowercase with underscores (e.g., 'nav_group', 'custom_field').";
    }

    public function provide(): array
    {
        return [
            $this->makeListPageAttributesTool(),
            $this->makeGetPageAttributeDetailsTool(),
            $this->makeCreatePageAttributeTool(),
            $this->makeCreatePageAttributeAndAddToSetTool(),
            $this->makeUpdatePageSelectAttributeOptionsTool(),
            $this->makeSetPageAttributeValueTool(),
            $this->makeListAttributeTypesTool(),
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
                $set = $ak->getAttributeSet();
                
                $results[] = [
                    'handle' => $ak->getAttributeKeyHandle(),
                    'name' => $ak->getAttributeKeyDisplayName(),
                    'type' => $type ? $type->getAttributeTypeHandle() : 'unknown',
                    'searchable' => (bool) $ak->isAttributeKeySearchable(),
                    'indexed' => (bool) $ak->isAttributeKeyContentIndexed(),
                    'set' => $set ? $set->getAttributeSetName() : null,
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

    protected function makeGetPageAttributeDetailsTool(): Tool
    {
        $tool = new CallableTool(
            'get_page_attribute_details',
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
            $set = $ak->getAttributeSet();
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
                'set' => $set ? [
                    'handle' => $set->getAttributeSetHandle(),
                    'name' => $set->getAttributeSetName(),
                ] : null,
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

            // Check if user has permission
            $u = new User();
            if (!$u->isSuperUser()) {
                throw new \RuntimeException("Permission denied: Only super users can create attributes");
            }

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
            $categoryService = $app->make(\Concrete\Core\Attribute\Category\CategoryService::class);
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

                // For select type, we need to manually create the option entities after attribute creation
                if ($type === 'select' && is_array($options) && count($options) > 0) {
                    $em = $app->make('Doctrine\ORM\EntityManagerInterface');
                    
                    // Get the attribute key settings (SelectSettings)
                    $keySettings = $ak->getAttributeKeySettings();
                    if ($keySettings) {
                        // Get the option list
                        $optionList = $keySettings->getOptionList();
                        if ($optionList) {
                            // Add the options as entities
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
                            $em->persist($keySettings);
                            $em->flush();
                        }
                    }
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

    protected function makeCreatePageAttributeAndAddToSetTool(): Tool
    {
        $tool = new CallableTool(
            'create_page_attribute_and_add_to_set',
            'Create a new page attribute and immediately add it to an attribute set. This combines attribute creation with set assignment to avoid timing issues.'
        );

        $tool->addProperty(new ToolProperty(
            'handle',
            PropertyType::STRING,
            'Unique handle for the attribute (lowercase, underscores only, e.g., "menu_label")',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'name',
            PropertyType::STRING,
            'Display name for the attribute (e.g., "Menu Label")',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'type',
            PropertyType::STRING,
            'Attribute type: text, textarea, number, url, boolean, date_time, select',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'set_handle',
            PropertyType::STRING,
            'Handle of the attribute set to add this attribute to',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'options',
            PropertyType::ARRAY,
            'For select type: array of option values (e.g., ["New", "Live", "Updated"])',
            false
        ));

        $tool->addProperty(new ToolProperty(
            'searchable',
            PropertyType::BOOLEAN,
            'Whether the attribute is searchable (default: true)',
            false
        ));

        $tool->addProperty(new ToolProperty(
            'indexed',
            PropertyType::BOOLEAN,
            'Whether the attribute is indexed for advanced search (default: false)',
            false
        ));

        $tool->setHandler($this->guarded(function (
            ?string $handle = null,
            ?string $name = null,
            ?string $type = null,
            ?string $set_handle = null,
            ?array $options = null,
            ?bool $searchable = true,
            ?bool $indexed = false
        ): array {
            if (!$handle || !$name || !$type || !$set_handle) {
                throw new \RuntimeException("Handle, name, type, and set_handle are all required");
            }

            // Check if user has permission
            $u = new User();
            if (!$u->isSuperUser()) {
                throw new \RuntimeException("Permission denied: Only super users can create attributes");
            }

            // Validate handle format
            if (!preg_match('/^[a-z][a-z0-9_]*$/', $handle)) {
                throw new \RuntimeException("Invalid handle format. Must start with a lowercase letter and contain only lowercase letters, numbers, and underscores");
            }

            // Check if attribute already exists
            $existing = PageAttributeKey::getByHandle($handle);
            if ($existing) {
                throw new \RuntimeException("Attribute with handle '{$handle}' already exists");
            }

            // Validate attribute type
            $validTypes = ['text', 'textarea', 'number', 'url', 'boolean', 'date_time', 'select'];
            if (!in_array($type, $validTypes)) {
                throw new \RuntimeException("Invalid attribute type. Must be one of: " . implode(', ', $validTypes));
            }

            // For select type, options are required
            if ($type === 'select' && (!is_array($options) || count($options) === 0)) {
                throw new \RuntimeException("Options array is required for select type attributes");
            }

            // Verify attribute set exists
            $attributeSet = AttributeSet::getByHandle($set_handle);
            if (!$attributeSet) {
                throw new \RuntimeException("Attribute set '{$set_handle}' not found");
            }

            $app = Application::getFacadeApplication();
            $categoryService = $app->make(\Concrete\Core\Attribute\Category\CategoryService::class);
            $pageCategory = $categoryService->getByHandle('collection');

            if (!$pageCategory) {
                throw new \RuntimeException("Could not load page attribute category");
            }

            // Get attribute type object
            $attributeType = AttributeType::getByHandle($type);
            if (!$attributeType) {
                throw new \RuntimeException("Attribute type '{$type}' not found");
            }

            // Build settings array
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

                // For select type, we need to manually create the option entities after attribute creation
                if ($type === 'select' && is_array($options) && count($options) > 0) {
                    $em = $app->make('Doctrine\ORM\EntityManagerInterface');
                    
                    // Get the attribute key settings (SelectSettings)
                    $keySettings = $ak->getAttributeKeySettings();
                    if ($keySettings) {
                        // Get the option list
                        $optionList = $keySettings->getOptionList();
                        if ($optionList) {
                            // Add the options as entities
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
                            $em->persist($keySettings);
                            $em->flush();
                        }
                    }
                }

                // Add to set using the returned attribute key object (not a lookup)
                $ak->setAttributeSet($attributeSet);

                // Clear cache
                $cache = $app->make('cache/request');
                if ($cache) {
                    $cache->delete('page/attribute/key/handle/' . $handle);
                }

                return [
                    'success' => true,
                    'message' => "Page attribute '{$handle}' created and added to set '{$set_handle}' successfully",
                    'attribute' => [
                        'handle' => $ak->getAttributeKeyHandle(),
                        'name' => $ak->getAttributeKeyDisplayName(),
                        'type' => $type,
                        'searchable' => $searchable,
                        'indexed' => $indexed,
                    ],
                    'set' => [
                        'handle' => $attributeSet->getAttributeSetHandle(),
                        'name' => $attributeSet->getAttributeSetDisplayName(),
                    ],
                ];

            } catch (\Exception $e) {
                throw new \RuntimeException("Failed to create attribute and add to set: " . $e->getMessage());
            }
        }));

        return $tool;
    }

    protected function makeUpdatePageSelectAttributeOptionsTool(): Tool
    {
        $tool = new CallableTool(
            'update_page_select_attribute_options',
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

            // Check if user has permission
            $u = new User();
            if (!$u->isSuperUser()) {
                throw new \RuntimeException("Permission denied: Only super users can update attributes");
            }

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

            // Check permissions
            $checker = new \Concrete\Core\Permission\Checker($page);
            if (!$checker->canEditPageContents()) {
                throw new \RuntimeException("Permission denied: Cannot edit page " . $page->getCollectionID());
            }

            // Get the attribute
            $ak = PageAttributeKey::getByHandle($handle);
            if (!$ak) {
                throw new \RuntimeException("Page attribute with handle '{$handle}' not found");
            }

            $app = Application::getFacadeApplication();

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
            $pageToModify = PageDraft::of($page);

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

            return $callable(...$args);
        };
    }
}
