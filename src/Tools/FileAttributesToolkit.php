<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools;

use Concrete\Core\Attribute\Key\FileKey as FileAttributeKey;
use Concrete\Core\Attribute\Type as AttributeType;
use Concrete\Core\Entity\Attribute\Key\Key;
use Concrete\Core\Support\Facade\Application;
use Concrete\Core\User\User;
use Concrete\Core\File\File;
use NeuronAI\Tools\Tool;
use Katalysis\NeuronAi\Tools\Support\LegacyTool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Toolkits\AbstractToolkit;

/**
 * File Attributes toolkit for Neuron AI
 * 
 * Provides tools for managing file attributes in Concrete CMS.
 */
class FileAttributesToolkit extends AbstractToolkit
{
    public function guidelines(): ?string
    {
        return "Use these tools to manage file attributes in Concrete CMS. You can list existing file attributes, get detailed information about specific attributes, create new file attributes with various types (text, select, boolean, etc.), update select attribute options, and set attribute values on files. When setting file attributes, you can identify files by ID or filename. For select attributes, option values are matched case-insensitively. Always check that attribute handles don't already exist before creating new ones. Attribute handles should be lowercase with underscores (e.g., 'copyright_holder', 'license_type').";
    }

    public function provide(): array
    {
        return [
            $this->makeListFileAttributesTool(),
            $this->makeGetFileAttributeDetailsTool(),
            $this->makeCreateFileAttributeTool(),
            $this->makeUpdateFileSelectAttributeOptionsTool(),
            $this->makeSetFileAttributeValueTool(),
        ];
    }

    protected function makeListFileAttributesTool(): Tool
    {
        $tool = new LegacyTool(
            'list_file_attributes',
            'List all file attributes defined in the system with their handles, names, and types.'
        );

        $tool->setCallable($this->guarded(function (): array {
            $attributes = FileAttributeKey::getList();
            $results = [];

            foreach ($attributes as $ak) {
                $type = $ak->getAttributeType();
                $set = $ak->getAttributeSet();
                
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

    protected function makeGetFileAttributeDetailsTool(): Tool
    {
        $tool = new LegacyTool(
            'get_file_attribute_details',
            'Get detailed information about a specific file attribute by handle.'
        );

        $tool->addProperty(new ToolProperty(
            'handle',
            PropertyType::STRING,
            'Attribute handle (e.g., "copyright_holder", "license_type")',
            true
        ));

        $tool->setCallable($this->guarded(function (?string $handle = null): array {
            if (!$handle) {
                throw new \RuntimeException("Attribute handle is required");
            }

            $ak = FileAttributeKey::getByHandle($handle);
            
            if (!$ak) {
                throw new \RuntimeException("File attribute with handle '{$handle}' not found");
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
                'set' => $set ? [
                    'handle' => $set->getAttributeSetHandle(),
                    'name' => $set->getAttributeSetName(),
                ] : null,
                'settings' => $settings,
            ];
        }));

        return $tool;
    }

    protected function makeCreateFileAttributeTool(): Tool
    {
        $tool = new LegacyTool(
            'create_file_attribute',
            'Create a new file attribute with specified type and settings.'
        );

        $tool->addProperty(new ToolProperty(
            'handle',
            PropertyType::STRING,
            'Unique attribute handle (lowercase with underscores, e.g., "copyright_holder", "license_type")',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'name',
            PropertyType::STRING,
            'Display name for the attribute (e.g., "Copyright Holder", "License Type")',
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
            'For select type: array of option values (e.g., ["MIT", "GPL", "Apache"]). Optional for other types.',
            false
        ));

        $tool->addProperty(new ToolProperty(
            'searchable',
            PropertyType::BOOLEAN,
            'Whether this attribute should be searchable (default: false)',
            false
        ));

        $tool->setCallable($this->guarded(function (
            ?string $handle = null,
            ?string $name = null,
            ?string $type = null,
            ?array $options = null,
            ?bool $searchable = false
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
            $existing = FileAttributeKey::getByHandle($handle);
            if ($existing) {
                throw new \RuntimeException("File attribute with handle '{$handle}' already exists");
            }

            // Get attribute type
            $attributeType = AttributeType::getByHandle($type);
            if (!$attributeType) {
                throw new \RuntimeException("Invalid attribute type '{$type}'");
            }

            $app = Application::getFacadeApplication();
            $categoryService = $app->make(\Concrete\Core\Attribute\Category\CategoryService::class);
            $fileCategory = $categoryService->getByHandle('file');

            if (!$fileCategory) {
                throw new \RuntimeException("Could not load file attribute category");
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
                $ak = $fileCategory->getController()->add(
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
                    'message' => "File attribute '{$handle}' created successfully",
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

    protected function makeUpdateFileSelectAttributeOptionsTool(): Tool
    {
        $tool = new LegacyTool(
            'update_file_select_attribute_options',
            'Update the options for an existing select/dropdown file attribute. Replaces all existing options.'
        );

        $tool->addProperty(new ToolProperty(
            'handle',
            PropertyType::STRING,
            'Handle of the select attribute to update (e.g., "license_type")',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'options',
            PropertyType::ARRAY,
            'Array of option values to set (e.g., ["MIT", "GPL"]). This replaces all existing options.',
            true
        ));

        $tool->setCallable($this->guarded(function (
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
            $ak = FileAttributeKey::getByHandle($handle);
            if (!$ak) {
                throw new \RuntimeException("File attribute with handle '{$handle}' not found");
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
                    $cache->delete('file/attribute/key/handle/' . $handle);
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

    protected function makeSetFileAttributeValueTool(): Tool
    {
        $tool = new LegacyTool(
            'set_file_attribute_value',
            'Set the value of a file attribute for a specific file. Handles all attribute types including select/dropdown. '
            . 'This changes the live file straight away. The result gives the previous value (previousValue); tell the user '
            . 'what it was so they can ask for it to be set back.'
        );

        $tool->addProperty(new ToolProperty(
            'fileID',
            PropertyType::INTEGER,
            'ID of the file to update. If not provided, filename must be provided.',
            false
        ));

        $tool->addProperty(new ToolProperty(
            'filename',
            PropertyType::STRING,
            'Filename to search for. Alternative to fileID.',
            false
        ));

        $tool->addProperty(new ToolProperty(
            'handle',
            PropertyType::STRING,
            'Attribute handle (e.g., "copyright_holder", "license_type")',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'value',
            PropertyType::STRING,
            'The value to set. For select attributes, case-insensitive matching is used.',
            true
        ));

        $tool->setCallable($this->guarded(function (
            ?int $fileID = null,
            ?string $filename = null,
            ?string $handle = null,
            ?string $value = null
        ): array {
            if (!$fileID && !$filename) {
                throw new \RuntimeException("Either fileID or filename is required");
            }

            if (!$handle) {
                throw new \RuntimeException("Attribute handle is required");
            }

            if ($value === null) {
                throw new \RuntimeException("Value is required");
            }

            // Get the file
            $file = null;
            if ($fileID) {
                $file = File::getByID($fileID);
            } elseif ($filename) {
                // Search for file by filename
                $fileList = new \Concrete\Core\File\FileList();
                $fileList->filterByFilename($filename);
                $results = $fileList->getResults();
                
                if (count($results) === 0) {
                    // Try case-insensitive
                    $fileList = new \Concrete\Core\File\FileList();
                    $fileList->getQueryObject()->where('LOWER(fv.fvFilename) = ?', [strtolower($filename)]);
                    $results = $fileList->getResults();
                }
                
                if (count($results) === 1) {
                    $file = $results[0];
                } elseif (count($results) > 1) {
                    $fileNames = array_map(function($f) { 
                        return $f->getFileName() . ' (ID: ' . $f->getFileID() . ')'; 
                    }, $results);
                    throw new \RuntimeException("Multiple files found matching '{$filename}': " . implode(', ', $fileNames) . ". Please use fileID instead.");
                } else {
                    throw new \RuntimeException("No file found matching name '{$filename}'");
                }
            }
            
            if (!$file || $file->isError()) {
                throw new \RuntimeException("File not found");
            }

            // Check permissions
            $u = new User();
            if (!$u->isSuperUser()) {
                // Check if user can edit file
                $fp = new \Concrete\Core\Permission\Checker($file);
                if (!$fp->canEditFileContents()) {
                    throw new \RuntimeException("Permission denied: Cannot edit file " . $file->getFileID());
                }
            }

            // Get the attribute
            $ak = FileAttributeKey::getByHandle($handle);
            if (!$ak) {
                throw new \RuntimeException("File attribute with handle '{$handle}' not found");
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

            // Get the file version
            $fileVersion = $file->getVersion();

            // P1.13: this write is live (files have no draft), so record what the value was before changing it.
            $display = static function ($raw): ?string {
                if ($raw === null || $raw === '') {
                    return null;
                }
                if (is_object($raw) && method_exists($raw, 'getSelectAttributeOptionValue')) {
                    return (string) $raw->getSelectAttributeOptionValue();
                }

                return (string) $raw;
            };
            $previousValue = $display($fileVersion->getAttribute($handle));

            // Set the attribute
            $fileVersion->setAttribute($ak, $attributeValue);

            // Get the actual value that was set for confirmation
            $setValueDisplay = (string) $display($fileVersion->getAttribute($handle));

            $undo = $previousValue === null
                ? 'It had no value before, so a person can clear it from the File Manager to undo this.'
                : "To undo this, set it back to '{$previousValue}'.";

            return [
                'success' => true,
                'message' => "Attribute '{$handle}' set to '{$setValueDisplay}' for file '{$file->getFileName()}'. "
                    . "The previous value was " . ($previousValue === null ? 'empty' : "'{$previousValue}'") . ". {$undo}",
                'file' => [
                    'id' => $file->getFileID(),
                    'filename' => $file->getFileName(),
                    'title' => $file->getTitle(),
                ],
                'attribute' => [
                    'handle' => $handle,
                    'name' => $ak->getAttributeKeyDisplayName(),
                    'type' => $type ? $type->getAttributeTypeHandle() : 'unknown',
                    'valueSet' => $setValueDisplay,
                    'previousValue' => $previousValue,
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
