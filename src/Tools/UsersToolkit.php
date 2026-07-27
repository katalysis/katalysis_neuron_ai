<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools;

use Concrete\Core\User\User;
use Concrete\Core\User\UserInfo;
use Concrete\Core\User\UserInfoRepository;
use Concrete\Core\User\UserList;
use Concrete\Core\User\Search\ColumnSet\Column\DateAddedColumn;
use Concrete\Core\Permission\Checker;
use Concrete\Core\Support\Facade\Application;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Toolkits\AbstractToolkit;

/**
 * Concrete CMS Users toolkit for Neuron AI
 * 
 * Provides native tools for user management without HTTP overhead.
 */
class UsersToolkit extends AbstractToolkit
{
    public function guidelines(): ?string
    {
        return "Use these tools to retrieve information about users in Concrete CMS. You can list users, get user details, and check the current user. User creation and modification should be done with caution.";
    }

    public function provide(): array
    {
        return [
            $this->makeListUsersTool(),
            $this->makeGetUserTool(),
            $this->makeGetCurrentUserTool(),
            $this->makeSearchUsersTool(),
        ];
    }

    protected function makeListUsersTool(): Tool
    {
        $tool = new Tool(
            'list_users',
            'List users in the system with pagination'
        );

        $tool->addProperty(new ToolProperty(
            'limit',
            PropertyType::INTEGER,
            'Maximum number of users to return (1-100)',
            false
        ));

        $tool->addProperty(new ToolProperty(
            'activeOnly',
            PropertyType::BOOLEAN,
            'Only return active users',
            false
        ));

        $tool->setCallable($this->guarded(function (
            ?int $limit = 20,
            ?bool $activeOnly = false
        ): array {
            $list = new UserList();
            $list->setPermissionsChecker(function ($userInfo) {
                $permissions = new Checker($userInfo);
                return $permissions->canViewUser();
            });

            $dateAddedColumn = new DateAddedColumn();
            $dateAddedColumn->setColumnSortDirection('desc');
            $list->sortBySearchColumn($dateAddedColumn);
            
            if ($activeOnly) {
                $list->filterByIsActive(true);
            }
            
            $list->setItemsPerPage(min(max($limit, 1), 100));
            $results = $list->getResults();
            
            return array_map(fn($userInfo) => $this->serializeUser($userInfo), $results);
        }));

        return $tool;
    }

    protected function makeGetUserTool(): Tool
    {
        $tool = new Tool(
            'get_user',
            'Retrieve detailed information about a specific user by ID'
        );

        $tool->addProperty(new ToolProperty(
            'userID',
            PropertyType::INTEGER,
            'ID of the user to retrieve',
            false
        ));

        $tool->addProperty(new ToolProperty(
            'identifier',
            PropertyType::STRING,
            'User ID (for example: "1") or username',
            false
        ));

        $tool->setCallable($this->guarded(function (?int $userID = null, ?string $identifier = null): array {
            if (!$userID && (!$identifier || trim($identifier) === '')) {
                throw new \RuntimeException("userID or identifier is required");
            }

            if (!$userID && $identifier !== null && ctype_digit(trim($identifier))) {
                $userID = (int) trim($identifier);
            }

            $repository = Application::getFacadeApplication()->make(UserInfoRepository::class);
            $userInfo = null;

            if ($userID) {
                $userInfo = $repository->getByID($userID);
            }

            if (!$userInfo && $identifier !== null && trim($identifier) !== '') {
                $list = new UserList();
                $list->filterByUserName(trim($identifier));
                $list->setItemsPerPage(1);
                $matches = $list->getResults();
                if (count($matches) > 0) {
                    $userInfo = $matches[0];
                    $userID = $userInfo->getUserID();
                }
            }
            
            if (!$userInfo) {
                $target = $identifier ?: (string) $userID;
                throw new \RuntimeException("User '{$target}' not found");
            }

            $permissions = new Checker($userInfo);
            if (!$permissions->canViewUser()) {
                throw new \RuntimeException("Permission denied: Cannot view user {$userInfo->getUserID()}");
            }
            
            return $this->serializeUser($userInfo, includeDetails: true);
        }));

        return $tool;
    }

    protected function makeGetCurrentUserTool(): Tool
    {
        $tool = new Tool(
            'get_current_user',
            'Get information about the currently logged in user'
        );

        $tool->setCallable($this->guarded(function (): array {
            $user = new User();
            
            if (!$user->isRegistered()) {
                return [
                    'logged_in' => false,
                    'message' => 'No user currently logged in'
                ];
            }
            
            $userInfo = UserInfo::getByID($user->getUserID());
            
            return [
                'logged_in' => true,
                'user' => $this->serializeUser($userInfo, includeDetails: true)
            ];
        }));

        return $tool;
    }

    protected function makeSearchUsersTool(): Tool
    {
        $tool = new Tool(
            'search_users',
            'Search for users by username or email'
        );

        $tool->addProperty(new ToolProperty(
            'query',
            PropertyType::STRING,
            'Search query (username or email)',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'limit',
            PropertyType::INTEGER,
            'Maximum number of results',
            false
        ));

        $tool->setCallable($this->guarded(function (?string $query = null, ?int $limit = 20): array {
            if (!$query) {
                throw new \RuntimeException("query is required");
            }
            $list = new UserList();
            $list->filterByKeywords($query);
            $list->setItemsPerPage(min(max($limit, 1), 100));
            
            $results = $list->getResults();
            
            return [
                'query' => $query,
                'total' => count($results),
                'users' => array_map(fn($userInfo) => $this->serializeUser($userInfo), $results)
            ];
        }));

        return $tool;
    }

    protected function serializeUser(UserInfo $userInfo, bool $includeDetails = false): array
    {
        $data = [
            'id' => $userInfo->getUserID(),
            'username' => $userInfo->getUserName(),
            'email' => $userInfo->getUserEmail(),
            'isActive' => $userInfo->isActive(),
        ];

        if ($includeDetails) {
            $data['dateAdded'] = $userInfo->getUserDateAdded();
            $data['lastLogin'] = $userInfo->getLastLogin();
            $data['timezone'] = $userInfo->getUserTimezone();
            
            // Get user attributes
            $attributes = [];
            $controller = $userInfo->getController();
            if ($controller) {
                foreach ($controller->getAttributes() as $ak) {
                    $attributes[$ak->getAttributeKeyHandle()] = $userInfo->getAttribute($ak);
                }
            }
            $data['attributes'] = $attributes;
        }

        return $data;
    }

    private function guarded(callable $callback): callable
    {
        return function (...$args) use ($callback): array {
            try {
                $result = $callback(...$args);

                return $this->normalizeToolResult($result);
            } catch (\Throwable $e) {
                return [
                    'ok' => false,
                    'error' => $e->getMessage(),
                ];
            }
        };
    }

    private function normalizeToolResult($result): array
    {
        if (!is_array($result)) {
            return [
                'ok' => true,
                'result' => $result,
                'error' => null,
            ];
        }

        if ($this->isListArray($result)) {
            return [
                'ok' => true,
                'items' => $result,
                'error' => null,
            ];
        }

        if (!array_key_exists('ok', $result)) {
            $result['ok'] = true;
        }
        if (!array_key_exists('error', $result)) {
            $result['error'] = null;
        }

        return $result;
    }

    private function isListArray(array $array): bool
    {
        return array_values($array) === $array;
    }
}
