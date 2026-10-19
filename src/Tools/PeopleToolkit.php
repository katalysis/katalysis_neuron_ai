<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools;

use Concrete\Package\KatalysisPro\Src\KatalysisPro\People\Person;
use Concrete\Package\KatalysisPro\Src\KatalysisPro\People\PeopleList;
use Concrete\Core\Support\Facade\Database;
use Concrete\Core\Tree\Node\Type\Topic as TopicNode;
use Concrete\Core\User\User;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Toolkits\AbstractToolkit;

/**
 * People toolkit for Neuron AI
 * 
 * Provides tools for managing the custom People entity in Katalysis Pro.
 * People have direct relationships to Places, Specialisms (topic tree), and Topics (topic tree).
 */
class PeopleToolkit extends AbstractToolkit
{
    public function guidelines(): ?string
    {
        return "Use these tools to manage People in the Katalysis Pro system. People are a custom entity (not pages or users) with properties like name, job title, email, and relationships to Places, Specialisms, and Topics. Specialisms and Topics are topic tree nodes stored in junction tables (KatalysisPeopleSpecialism, KatalysisPeopleTopic). When updating specialisms or topics, provide an array of topic node IDs. Use the Topics toolkit to search for topic IDs by name. People can be identified by ID, name (fuzzy matching), or email.";
    }

    public function provide(): array
    {
        return [
            $this->makeListPeopleTool(),
            $this->makeGetPersonDetailsTool(),
            $this->makeGetPeopleImageFileIdsTool(),
            $this->makeUpdatePersonSpecialismsTool(),
            $this->makeUpdatePersonTopicsTool(),
        ];
    }

    protected function makeListPeopleTool(): Tool
    {
        $tool = new Tool(
            'list_people',
            'List all people in the system with filtering options. Returns basic info including specialisms and topics.'
        );

        $tool->addProperty(new ToolProperty(
            'active_only',
            PropertyType::BOOLEAN,
            'Show only active people (default: true)',
            false
        ));

        $tool->addProperty(new ToolProperty(
            'featured_only',
            PropertyType::BOOLEAN,
            'Show only featured people (default: false)',
            false
        ));

        $tool->addProperty(new ToolProperty(
            'limit',
            PropertyType::INTEGER,
            'Maximum number of results to return (default: 100)',
            false
        ));

        $tool->setCallable($this->guarded(function (
            ?bool $active_only = true,
            ?bool $featured_only = false,
            ?int $limit = 100
        ): array {
            $list = new PeopleList();

            // Apply filters
            $list->filterByActive($active_only ? 1 : null);
            
            if ($featured_only) {
                $list->featuredOnly();
            }

            if ($limit && $limit > 0) {
                $list->limitResults($limit);
            }

            $results = $list->getResults();
            $people = [];

            foreach ($results as $person) {
                if ($person instanceof Person) {
                    $specialisms = $person->getSpecialisms();
                    $topics = $person->getTopics();
                    $places = $person->getPlaces();

                    $people[] = [
                        'sID' => $person->getSID(),
                        'name' => $person->getName(),
                        'jobTitle' => $person->getJobTitle(),
                        'email' => $person->getEmail(),
                        'phone' => $person->getPhone(),
                        'image' => $person->getImage(),
                        'active' => (bool) $person->getActive(),
                        'featured' => (bool) $person->getFeatured(),
                        'specialisms' => array_column($specialisms, 'treeNodeName'),
                        'topics' => array_column($topics, 'treeNodeName'),
                        'places' => array_column($places, 'name'),
                    ];
                }
            }

            return [
                'success' => true,
                'totalPeople' => count($people),
                'people' => $people,
            ];
        }));

        return $tool;
    }

    protected function makeGetPersonDetailsTool(): Tool
    {
        $tool = new Tool(
            'get_person_details',
            'Get detailed information about a specific person including all relationships.'
        );

        $tool->addProperty(new ToolProperty(
            'person_id',
            PropertyType::INTEGER,
            'Person ID (sID). If not provided, name or email must be provided.',
            false
        ));

        $tool->addProperty(new ToolProperty(
            'name',
            PropertyType::STRING,
            'Person name (fuzzy matching). Alternative to person_id.',
            false
        ));

        $tool->addProperty(new ToolProperty(
            'email',
            PropertyType::STRING,
            'Person email. Alternative to person_id.',
            false
        ));

        $tool->setCallable($this->guarded(function (
            ?int $person_id = null,
            ?string $name = null,
            ?string $email = null
        ): array {
            if (!$person_id && !$name && !$email) {
                throw new \RuntimeException('Either person_id, name, or email is required');
            }

            $person = null;

            if ($person_id) {
                $person = Person::getByID($person_id);
            } elseif ($email) {
                $db = Database::get();
                $row = $db->fetchAssoc('SELECT * FROM KatalysisPeople WHERE email = ? LIMIT 1', [$email]);
                if ($row) {
                    $person = Person::getByID($row['sID']);
                }
            } elseif ($name) {
                // Fuzzy name matching
                $db = Database::get();
                
                // Try exact match first
                $row = $db->fetchAssoc('SELECT * FROM KatalysisPeople WHERE name = ? LIMIT 1', [$name]);
                
                // Try case-insensitive match
                if (!$row) {
                    $row = $db->fetchAssoc('SELECT * FROM KatalysisPeople WHERE LOWER(name) = ? LIMIT 1', [strtolower($name)]);
                }
                
                // Try partial match
                if (!$row) {
                    $row = $db->fetchAssoc('SELECT * FROM KatalysisPeople WHERE LOWER(name) LIKE ? LIMIT 1', ['%' . strtolower($name) . '%']);
                }
                
                if ($row) {
                    $person = Person::getByID($row['sID']);
                }
            }

            if (!$person || !$person instanceof Person) {
                throw new \RuntimeException('Person not found');
            }

            // Get relationships with full details
            $specialisms = $person->getSpecialisms();
            $topics = $person->getTopics();
            $places = $person->getPlaces();

            return [
                'success' => true,
                'person' => [
                    'sID' => $person->getSID(),
                    'name' => $person->getName(),
                    'jobTitle' => $person->getJobTitle(),
                    'email' => $person->getEmail(),
                    'phone' => $person->getPhone(),
                    'mobile' => $person->getMobile(),
                    'linkedin' => $person->getLinkedin(),
                    'image' => $person->getImage(),
                    'active' => (bool) $person->getActive(),
                    'featured' => (bool) $person->getFeatured(),
                    'sortOrder' => $person->getSortOrder(),
                    'specialisms' => $specialisms,
                    'topics' => $topics,
                    'places' => $places,
                ],
            ];
        }));

        return $tool;
    }

    protected function makeGetPeopleImageFileIdsTool(): Tool
    {
        $tool = new Tool(
            'get_people_image_file_ids',
            'Get image file IDs for all people as a comma-separated list. Useful for batch thumbnail generation.'
        );

        $tool->addProperty(new ToolProperty(
            'active_only',
            PropertyType::BOOLEAN,
            'Show only active people (default: true)',
            false
        ));

        $tool->addProperty(new ToolProperty(
            'featured_only',
            PropertyType::BOOLEAN,
            'Show only featured people (default: false)',
            false
        ));

        $tool->addProperty(new ToolProperty(
            'exclude_null',
            PropertyType::BOOLEAN,
            'Exclude people without images (default: true)',
            false
        ));

        $tool->setCallable($this->guarded(function (
            ?bool $active_only = true,
            ?bool $featured_only = false,
            ?bool $exclude_null = true
        ): array {
            $list = new PeopleList();

            // Apply filters
            $list->filterByActive($active_only ? 1 : null);
            
            if ($featured_only) {
                $list->featuredOnly();
            }

            $results = $list->getResults();
            $fileIds = [];
            $peopleWithImages = [];

            foreach ($results as $person) {
                if ($person instanceof Person) {
                    $imageId = $person->getImage();
                    if ($imageId && $imageId > 0) {
                        $fileIds[] = $imageId;
                        $peopleWithImages[] = [
                            'sID' => $person->getSID(),
                            'name' => $person->getName(),
                            'imageFileId' => $imageId,
                        ];
                    } elseif (!$exclude_null) {
                        $peopleWithImages[] = [
                            'sID' => $person->getSID(),
                            'name' => $person->getName(),
                            'imageFileId' => null,
                        ];
                    }
                }
            }

            return [
                'success' => true,
                'totalPeople' => count($peopleWithImages),
                'fileIds' => $fileIds,
                'commaSeparatedFileIds' => implode(', ', $fileIds),
                'people' => $peopleWithImages,
            ];
        }));

        return $tool;
    }

    protected function makeUpdatePersonSpecialismsTool(): Tool
    {
        $tool = new Tool(
            'update_person_specialisms',
            'Update the specialisms (topic tree nodes) for a person. Replaces all existing specialisms with the provided list.'
        );

        $tool->addProperty(new ToolProperty(
            'person_id',
            PropertyType::INTEGER,
            'Person ID (sID)',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'specialism_ids',
            PropertyType::ARRAY,
            'Array of topic node IDs for specialisms. Use get_topic_by_name or get_topic_tree_structure to find IDs. Empty array removes all specialisms.',
            true
        ));

        $tool->setCallable($this->guarded(function (
            ?int $person_id = null,
            ?array $specialism_ids = null
        ): array {
            if (!$person_id) {
                throw new \RuntimeException('person_id is required');
            }

            if (!is_array($specialism_ids)) {
                throw new \RuntimeException('specialism_ids must be an array');
            }

            // Check if user has permission
            $u = new User();
            if (!$u->isSuperUser()) {
                throw new \RuntimeException('Permission denied: Only super users can update people');
            }

            // Verify person exists
            $person = Person::getByID($person_id);
            if (!$person || !$person instanceof Person) {
                throw new \RuntimeException("Person with ID {$person_id} not found");
            }

            // Validate all specialism IDs exist as topic nodes
            $validatedIds = [];
            foreach ($specialism_ids as $id) {
                $nodeId = (int) $id;
                if ($nodeId > 0) {
                    $node = TopicNode::getByID($nodeId);
                    if (!$node || !$node instanceof TopicNode) {
                        throw new \RuntimeException("Topic node ID {$nodeId} not found");
                    }
                    $validatedIds[] = $nodeId;
                }
            }

            $db = Database::get();

            // Delete existing specialisms
            $db->executeStatement(
                'DELETE FROM KatalysisPeopleSpecialism WHERE personID = ?',
                [$person_id]
            );

            // Insert new specialisms
            foreach ($validatedIds as $specialismId) {
                $db->executeStatement(
                    'INSERT INTO KatalysisPeopleSpecialism (personID, specialismID) VALUES (?, ?)',
                    [$person_id, $specialismId]
                );
            }

            // Get updated specialisms for confirmation
            $updatedSpecialisms = $person->getSpecialisms();

            return [
                'success' => true,
                'message' => "Specialisms updated for person '{$person->getName()}'",
                'person' => [
                    'sID' => $person->getSID(),
                    'name' => $person->getName(),
                ],
                'specialismsCount' => count($updatedSpecialisms),
                'specialisms' => $updatedSpecialisms,
            ];
        }));

        return $tool;
    }

    protected function makeUpdatePersonTopicsTool(): Tool
    {
        $tool = new Tool(
            'update_person_topics',
            'Update the topics (topic tree nodes) for a person. Replaces all existing topics with the provided list.'
        );

        $tool->addProperty(new ToolProperty(
            'person_id',
            PropertyType::INTEGER,
            'Person ID (sID)',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'topic_ids',
            PropertyType::ARRAY,
            'Array of topic node IDs. Use get_topic_by_name or get_topic_tree_structure to find IDs. Empty array removes all topics.',
            true
        ));

        $tool->setCallable($this->guarded(function (
            ?int $person_id = null,
            ?array $topic_ids = null
        ): array {
            if (!$person_id) {
                throw new \RuntimeException('person_id is required');
            }

            if (!is_array($topic_ids)) {
                throw new \RuntimeException('topic_ids must be an array');
            }

            // Check if user has permission
            $u = new User();
            if (!$u->isSuperUser()) {
                throw new \RuntimeException('Permission denied: Only super users can update people');
            }

            // Verify person exists
            $person = Person::getByID($person_id);
            if (!$person || !$person instanceof Person) {
                throw new \RuntimeException("Person with ID {$person_id} not found");
            }

            // Validate all topic IDs exist as topic nodes
            $validatedIds = [];
            foreach ($topic_ids as $id) {
                $nodeId = (int) $id;
                if ($nodeId > 0) {
                    $node = TopicNode::getByID($nodeId);
                    if (!$node || !$node instanceof TopicNode) {
                        throw new \RuntimeException("Topic node ID {$nodeId} not found");
                    }
                    $validatedIds[] = $nodeId;
                }
            }

            $db = Database::get();

            // Delete existing topics
            $db->executeStatement(
                'DELETE FROM KatalysisPeopleTopic WHERE personID = ?',
                [$person_id]
            );

            // Insert new topics
            foreach ($validatedIds as $topicId) {
                $db->executeStatement(
                    'INSERT INTO KatalysisPeopleTopic (personID, topicID) VALUES (?, ?)',
                    [$person_id, $topicId]
                );
            }

            // Get updated topics for confirmation
            $updatedTopics = $person->getTopics();

            return [
                'success' => true,
                'message' => "Topics updated for person '{$person->getName()}'",
                'person' => [
                    'sID' => $person->getSID(),
                    'name' => $person->getName(),
                ],
                'topicsCount' => count($updatedTopics),
                'topics' => $updatedTopics,
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
