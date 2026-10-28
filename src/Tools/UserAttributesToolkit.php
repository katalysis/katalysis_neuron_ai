<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools;

use Concrete\Core\Attribute\Key\UserKey as UserAttributeKey;
use Concrete\Core\Attribute\Type as AttributeType;
use Concrete\Core\Entity\Attribute\Key\Key;
use Concrete\Core\Support\Facade\Application;
use Concrete\Core\User\User;
use Concrete\Core\User\UserInfo;
use NeuronAI\Tools\Tool;
use Katalysis\NeuronAi\Tools\Support\CallableTool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Toolkits\AbstractToolkit;

/**
 * User Attributes toolkit for Neuron AI
 * 
 * Provides tools for managing user attributes in Concrete CMS.
 */
class UserAttributesToolkit extends AbstractToolkit
{
    public function guidelines(): ?string
    {
        return "Use these tools to manage user attributes in Concrete CMS. You can list existing user attributes, get detailed information about specific attributes, create new user attributes with various types (text, select, boolean, etc.), update select attribute options, and set attribute values on users. When setting user attributes, you can identify users by ID, username, or email. For select attributes, option values are matched case-insensitively. Always check that attribute handles don't already exist before creating new ones. Attribute handles should be lowercase with underscores (e.g., 'department', 'job_title').";
    }

    public function provide(): array
    {
        return [
            $this->makeListUserAttributesTool(),
            $this->makeGetUserAttributeDetailsTool(),
            $this->makeCreateUserAttributeTool(),
            $this->makeUpdateUserSelectAttributeOptionsTool(),
            // set_user_attribute_value was removed in P3.5-2: user accounts are read-only at every tier (B-ACC-7).
        ];
    }

    protected function makeListUserAttributesTool(): Tool
    {
        $tool = new CallableTool(
            'list_user_attributes',
            'List all user attributes defined in the system with their handles, names, and types.'
        );

        $tool->setHandler($this->guarded(function (): array {
            $attributes = UserAttributeKey::getList();
            $results = [];

            foreach ($attributes as $ak) {
                $type = $ak->getAttributeType();
                // BF-15: Concrete 9.5.5's keys have no getAttributeSet(); a key's sets come from getAttributeSets().
                $sets = $ak->getAttributeSets();
                $set = $sets === [] ? null : reset($sets);
                
                $results[] = [
                    'handle' => $ak->getAttributeKeyHandle(),
                    'name' => $ak->getAttributeKeyDisplayName(),
                    'type' => $type ? $type->getAttributeTypeHandle() : 'unknown',
                    'searchable' => (bool) $ak->isAttributeKeySearchable(),
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

    protected function makeGetUserAttributeDetailsTool(): Tool
    {
        $tool = new CallableTool(
            'get_user_attribute_details',
            'Get detailed information about a specific user attribute by handle.'
        );

        $tool->addProperty(new ToolProperty(
            'handle',
            PropertyType::STRING,
            'Attribute handle (e.g., "department", "job_title")',
            true
        ));

        $tool->setHandler($this->guarded(function (?string $handle = null): array {
            if (!$handle) {
                throw new \RuntimeException('This tool needs handle: the attribute\'s handle, from list_user_attributes. Nothing was changed.');
            }

            $ak = UserAttributeKey::getByHandle($handle);
            
            if (!$ak) {
                throw new \RuntimeException("User attribute '{$handle}' not found: there is no attribute with that handle. list_user_attributes lists the handles. Nothing was changed.");
            }

            $type = $ak->getAttributeType();
            // BF-15: Concrete 9.5.5's keys have no getAttributeSet(); a key's sets come from getAttributeSets().
            $sets = $ak->getAttributeSets();
            $set = $sets === [] ? null : reset($sets);
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
                'set' => $set ? [
                    'handle' => $set->getAttributeSetHandle(),
                    'name' => $set->getAttributeSetName(),
                ] : null,
                'settings' => $settings,
            ];
        }));

        return $tool;
    }

    protected function makeCreateUserAttributeTool(): Tool
    {
        $tool = new CallableTool(
            'create_user_attribute',
            'Create a new user attribute with specified type and settings. Use list_attribute_types to see available types.'
        );

        $tool->addProperty(new ToolProperty(
            'handle',
            PropertyType::STRING,
            'Unique attribute handle (lowercase with underscores, e.g., "department", "job_title")',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'name',
            PropertyType::STRING,
            'Display name for the attribute (e.g., "Department", "Job Title")',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'type',
            PropertyType::STRING,
            'Attribute type handle (e.g., "text", "select", "boolean", "textarea")',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'options',
            PropertyType::ARRAY,
            'For select type: array of option values (e.g., ["Sales", "Marketing", "Engineering"]). Optional for other types.',
            false
        ));

        $tool->addProperty(new ToolProperty(
            'searchable',
            PropertyType::BOOLEAN,
            'Whether this attribute should be searchable (default: false)',
            false
        ));

        $tool->setHandler($this->guarded(function (
            ?string $handle = null,
            ?string $name = null,
            ?string $type = null,
            ?array $options = null,
            ?bool $searchable = false
        ): array {
            if (!$handle || !$name || !$type) {
                throw new \RuntimeException('This tool needs handle, name and type. Nothing was changed.');
            }

            // Check if user has permission
            // P3.5-1 decision 1: Concrete's own gate for user attributes, not super user only.
            \Katalysis\NeuronAi\Access\ObjectPermissions::deny(\Katalysis\NeuronAi\Access\ObjectPermissions::viewDashboardPage('/dashboard/users/attributes', 'create a user attribute'));

            // Validate handle format
            if (!preg_match('/^[a-z][a-z0-9_]*$/', $handle)) {
                throw new \RuntimeException('The handle must start with a lowercase letter and use only lowercase letters, numbers and underscores (for example "room_size"). Nothing was changed.');
            }

            // Check if attribute already exists
            $existing = UserAttributeKey::getByHandle($handle);
            if ($existing) {
                throw new \RuntimeException("There is already a user attribute '{$handle}': use it (get_user_attribute_details), or choose another handle. Nothing was changed.");
            }

            // Get attribute type
            $attributeType = AttributeType::getByHandle($type);
            if (!$attributeType) {
                throw new \RuntimeException("There is no attribute type '{$type}'. list_attribute_types lists them. Nothing was changed.");
            }

            $app = Application::getFacadeApplication();
            $categoryService = $app->make(\Concrete\Core\Attribute\Category\CategoryService::class);
            $userCategory = $categoryService->getByHandle('user');

            if (!$userCategory) {
                throw new \RuntimeException("Could not load user attribute category");
            }

            // Prepare settings based on type
            $settings = [
                'akHandle' => $handle,
                'akName' => $name,
                'akIsSearchable' => $searchable ? 1 : 0,
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
                $ak = $userCategory->getController()->add(
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
                    'message' => "User attribute '{$handle}' created successfully",
                    'attribute' => [
                        'handle' => $ak->getAttributeKeyHandle(),
                        'name' => $ak->getAttributeKeyDisplayName(),
                        'type' => $type,
                        'searchable' => $searchable,
                    ],
                ];

            } catch (\Exception $e) {
                throw new \RuntimeException("Failed to create attribute: " . $e->getMessage());
            }
        }));

        return $tool;
    }

    protected function makeUpdateUserSelectAttributeOptionsTool(): Tool
    {
        $tool = new CallableTool(
            'update_user_select_attribute_options',
            'Update the options for an existing select/dropdown user attribute. Replaces all existing options.'
        );

        $tool->addProperty(new ToolProperty(
            'handle',
            PropertyType::STRING,
            'Handle of the select attribute to update (e.g., "department")',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'options',
            PropertyType::ARRAY,
            'Array of option values to set (e.g., ["Sales", "Marketing"]). This replaces all existing options.',
            true
        ));

        $tool->setHandler($this->guarded(function (
            ?string $handle = null,
            ?array $options = null
        ): array {
            if (!$handle) {
                throw new \RuntimeException('This tool needs handle: the attribute\'s handle, from list_user_attributes. Nothing was changed.');
            }

            if (!is_array($options) || count($options) === 0) {
                throw new \RuntimeException('options needs at least one option. Nothing was changed.');
            }

            // Check if user has permission
            // P3.5-1 decision 1: Concrete's own gate for user attributes, not super user only.
            \Katalysis\NeuronAi\Access\ObjectPermissions::deny(\Katalysis\NeuronAi\Access\ObjectPermissions::viewDashboardPage('/dashboard/users/attributes', 'change the options of a user attribute'));

            // Get the attribute
            $ak = UserAttributeKey::getByHandle($handle);
            if (!$ak) {
                throw new \RuntimeException("User attribute '{$handle}' not found: there is no attribute with that handle. list_user_attributes lists the handles. Nothing was changed.");
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
                    $cache->delete('user/attribute/key/handle/' . $handle);
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
