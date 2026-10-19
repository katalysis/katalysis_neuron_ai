<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools;

use Concrete\Core\Attribute\Key\CollectionKey as PageAttributeKey;
use Concrete\Core\Attribute\Key\UserKey as UserAttributeKey;
use Concrete\Core\Attribute\Key\FileKey as FileAttributeKey;
use Concrete\Core\Attribute\Category\CategoryService;
use Concrete\Core\Attribute\Set as AttributeSet;
use Concrete\Core\Support\Facade\Application;
use Concrete\Core\User\User;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Toolkits\AbstractToolkit;

/**
 * Attribute Sets toolkit for Neuron AI
 * 
 * Provides tools for managing attribute sets across all categories (pages, users, files).
 * Attribute sets are organizational groups that help structure attributes in the CMS interface.
 */
class AttributeSetsToolkit extends AbstractToolkit
{
    public function guidelines(): ?string
    {
        return "Use these tools to manage attribute sets in Concrete CMS. Attribute sets organize attributes into logical groups in the CMS interface. You can list existing sets, create new sets, delete sets (attributes are not deleted, just ungrouped), add attributes to sets, and remove attributes from sets. All tools support three categories: 'collection' (for pages), 'user' (for user attributes), and 'file' (for file attributes). The category parameter defaults to 'collection'. When working with sets, you can identify them by their handle (lowercase with underscores).";
    }

    public function provide(): array
    {
        return [
            $this->makeListAttributeSetsTool(),
            $this->makeCreateAttributeSetTool(),
            $this->makeDeleteAttributeSetTool(),
            $this->makeAddAttributeToSetTool(),
            $this->makeRemoveAttributeFromSetTool(),
        ];
    }

    protected function makeListAttributeSetsTool(): Tool
    {
        $tool = new Tool(
            'list_attribute_sets',
            'List all attribute sets for a category (page, user, or file) with their contained attributes.'
        );

        $tool->addProperty(new ToolProperty(
            'category',
            PropertyType::STRING,
            'Category handle: collection (pages), user, or file. Defaults to collection.',
            false
        ));

        $tool->setCallable($this->guarded(function (?string $category = 'collection'): array {
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
                    'attributeCount' => count($attributes),
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
        $tool = new Tool(
            'create_attribute_set',
            'Create a new attribute set in a category to organize related attributes.'
        );

        $tool->addProperty(new ToolProperty(
            'handle',
            PropertyType::STRING,
            'Unique handle for the set (lowercase with underscores, e.g., "seo_settings")',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'name',
            PropertyType::STRING,
            'Display name for the set (e.g., "SEO Settings")',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'category',
            PropertyType::STRING,
            'Category: collection (pages), user, or file. Defaults to collection.',
            false
        ));

        $tool->setCallable($this->guarded(function (
            ?string $handle = null,
            ?string $name = null,
            ?string $category = 'collection'
        ): array {
            if (empty($handle) || empty($name)) {
                throw new \RuntimeException('Both handle and name are required');
            }

            // Check if user has permission
            $u = new User();
            if (!$u->isSuperUser()) {
                throw new \RuntimeException("Permission denied: Only super users can create attribute sets");
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
                'message' => "Attribute set '{$name}' created successfully in {$category} category",
                'set' => [
                    'id' => $set->getAttributeSetID(),
                    'handle' => $set->getAttributeSetHandle(),
                    'name' => $set->getAttributeSetName(),
                    'category' => $category,
                ],
            ];
        }));

        return $tool;
    }

    protected function makeDeleteAttributeSetTool(): Tool
    {
        $tool = new Tool(
            'delete_attribute_set',
            'Delete an attribute set. Note: Attributes are not deleted, they are just removed from the set (ungrouped).'
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
            'Category: collection (pages), user, or file. Defaults to collection.',
            false
        ));

        $tool->setCallable($this->guarded(function (
            ?string $handle = null,
            ?string $category = 'collection'
        ): array {
            if (empty($handle)) {
                throw new \RuntimeException('Set handle is required');
            }

            // Check if user has permission
            $u = new User();
            if (!$u->isSuperUser()) {
                throw new \RuntimeException("Permission denied: Only super users can delete attribute sets");
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
                'message' => "Attribute set '{$setName}' deleted successfully (attributes were not deleted)",
            ];
        }));

        return $tool;
    }

    protected function makeAddAttributeToSetTool(): Tool
    {
        $tool = new Tool(
            'add_attribute_to_set',
            'Add an attribute to an attribute set for better organization in the CMS interface.'
        );

        $tool->addProperty(new ToolProperty(
            'attribute_handle',
            PropertyType::STRING,
            'Handle of the attribute to add to the set',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'set_handle',
            PropertyType::STRING,
            'Handle of the set to add the attribute to',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'category',
            PropertyType::STRING,
            'Category: collection (pages), user, or file. Defaults to collection.',
            false
        ));

        $tool->setCallable($this->guarded(function (
            ?string $attribute_handle = null,
            ?string $set_handle = null,
            ?string $category = 'collection'
        ): array {
            if (empty($attribute_handle) || empty($set_handle)) {
                throw new \RuntimeException('Both attribute_handle and set_handle are required');
            }

            // Check if user has permission
            $u = new User();
            if (!$u->isSuperUser()) {
                throw new \RuntimeException("Permission denied: Only super users can modify attribute sets");
            }

            // Get attribute key based on category
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
                default:
                    throw new \RuntimeException("Invalid category '{$category}'. Use 'collection', 'user', or 'file'.");
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
                'category' => $category,
            ];
        }));

        return $tool;
    }

    protected function makeRemoveAttributeFromSetTool(): Tool
    {
        $tool = new Tool(
            'remove_attribute_from_set',
            'Remove an attribute from its attribute set (ungroup it). The attribute itself is not deleted.'
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
            'Category: collection (pages), user, or file. Defaults to collection.',
            false
        ));

        $tool->setCallable($this->guarded(function (
            ?string $attribute_handle = null,
            ?string $category = 'collection'
        ): array {
            if (empty($attribute_handle)) {
                throw new \RuntimeException('attribute_handle is required');
            }

            // Check if user has permission
            $u = new User();
            if (!$u->isSuperUser()) {
                throw new \RuntimeException("Permission denied: Only super users can modify attribute sets");
            }

            // Get attribute key based on category
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
                default:
                    throw new \RuntimeException("Invalid category '{$category}'. Use 'collection', 'user', or 'file'.");
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
                'previousSet' => $setName,
                'category' => $category,
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
