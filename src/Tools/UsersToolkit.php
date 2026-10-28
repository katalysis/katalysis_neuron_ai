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
use Concrete\Core\Attribute\Key\UserKey as UserAttributeKey;
use Katalysis\NeuronAi\Access\ObjectPermissions;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\Tool;
use Katalysis\NeuronAi\Tools\Support\CallableTool;
use Katalysis\NeuronAi\Tools\Utilities\ListWindow;
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
        $tool = new CallableTool(
            'list_users',
            'List users in the system, newest first. The result gives the total and whether there are more; use offset for the next users.'
        );

        $tool->addProperty(new ToolProperty(
            'limit',
            PropertyType::INTEGER,
            'Maximum number of users to return (1-100, default 20)',
            false
        ));

        $tool->addProperty(new ToolProperty(
            'offset',
            PropertyType::INTEGER,
            ListWindow::OFFSET_DESCRIPTION,
            false
        ));

        $tool->addProperty(new ToolProperty(
            'activeOnly',
            PropertyType::BOOLEAN,
            'Only return active users',
            false
        ));

        $tool->addProperty(self::fieldsProperty(['email']));

        $tool->setHandler($this->guarded(function (
            ?int $limit = 20,
            ?bool $activeOnly = false,
            ?int $offset = null,
            ?array $fields = null
        ): array {
            $window = ListWindow::of($limit, $offset, 20, 100);
            $list = new UserList();

            $dateAddedColumn = new DateAddedColumn();
            $dateAddedColumn->setColumnSortDirection('desc');
            $list->sortBySearchColumn($dateAddedColumn);
            $list->getQueryObject()->addOrderBy('u.uID', 'desc');

            if ($activeOnly) {
                $list->filterByIsActive(true);
            }

            // BF-6: getResults() ignores setItemsPerPage() and returns every match, so the permitted matches are counted
            // here and the window is sliced from them. BF-14: only the ones the requester may view.
            $results = self::viewable($list);
            $users = array_map(fn($userInfo) => $this->serializeUser($userInfo, (array) $fields), $window->slice($results));

            return ['users' => $users] + $window->summary(count($results), count($users), 'list_users', 'activeOnly, or search_users');
        }));

        return $tool;
    }

    protected function makeGetUserTool(): Tool
    {
        $tool = new CallableTool(
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

        $tool->addProperty(self::fieldsProperty(['email', 'lastLogin', 'timezone', 'attributes']));
        $tool->addProperty(self::attributesProperty());

        $tool->setHandler($this->guarded(function (?int $userID = null, ?string $identifier = null, ?array $fields = null, ?array $attributes = null): array {
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
            
            return $this->serializeUser($userInfo, (array) $fields, (array) $attributes, dateAdded: true);
        }));

        return $tool;
    }

    protected function makeGetCurrentUserTool(): Tool
    {
        $tool = new CallableTool(
            'get_current_user',
            'Get information about the currently logged in user'
        );

        $tool->addProperty(self::fieldsProperty(['lastLogin', 'timezone', 'attributes']));
        $tool->addProperty(self::attributesProperty());

        $tool->setHandler($this->guarded(function (?array $fields = null, ?array $attributes = null): array {
            $user = new User();
            
            if (!$user->isRegistered()) {
                return [
                    'logged_in' => false,
                    'message' => 'No user currently logged in'
                ];
            }
            
            $userInfo = UserInfo::getByID($user->getUserID());
            
            // Your own account: the email too, without asking.
            return [
                'logged_in' => true,
                'user' => $this->serializeUser($userInfo, array_merge(['email'], (array) $fields), (array) $attributes),
            ];
        }));

        return $tool;
    }

    protected function makeSearchUsersTool(): Tool
    {
        $tool = new CallableTool(
            'search_users',
            'Search for users by username or email. The result gives the total and whether there are more; use offset for the next users.'
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
            'Maximum number of results (1-100, default 20)',
            false
        ));

        $tool->addProperty(new ToolProperty(
            'offset',
            PropertyType::INTEGER,
            ListWindow::OFFSET_DESCRIPTION,
            false
        ));

        $tool->addProperty(self::fieldsProperty(['email']));

        $tool->setHandler($this->guarded(function (?string $query = null, ?int $limit = 20, ?int $offset = null, ?array $fields = null): array {
            if (!$query) {
                throw new \RuntimeException("query is required");
            }
            $window = ListWindow::of($limit, $offset, 20, 100);
            $list = new UserList();
            $list->filterByKeywords($query);
            $list->getQueryObject()->addOrderBy('u.uID', 'asc');

            // BF-6: every match the user may view, counted here; the window is sliced.
            $results = self::viewable($list);
            $users = array_map(fn($userInfo) => $this->serializeUser($userInfo, (array) $fields), $window->slice($results));

            return [
                'query' => $query,
                'users' => $users,
            ] + $window->summary(count($results), count($users), 'search_users', 'a more specific query');
        }));

        return $tool;
    }

    /**
     * BF-14 (B-PII-1): the list's matches the requester may view (Concrete's canViewUser, as get_user checks).
     * UserList::getResults() never calls a permission checker set on the list (only Concrete's pagination does), so
     * each user is checked here.
     *
     * @return list<UserInfo>
     */
    private static function viewable(UserList $list): array
    {
        return array_values(array_filter(
            $list->getResults(),
            static fn (UserInfo $userInfo): bool => (bool) (new Checker($userInfo))->canViewUser()
        ));
    }

    /**
     * P3.5-2 (B-PII-2): the optional details a request may ask for; the identity is always returned.
     *
     * @param list<string> $allowed
     */
    private static function fieldsProperty(array $allowed): ArrayProperty
    {
        return new ArrayProperty(
            'fields',
            'Details to add, only when the request needs them: ' . implode(', ', $allowed) . '. By default only the '
            . 'identity is returned (ID, username, active).',
            false,
            new ToolProperty('field', PropertyType::STRING, 'One of: ' . implode(', ', $allowed), true)
        );
    }

    private static function attributesProperty(): ArrayProperty
    {
        return new ArrayProperty(
            'attributes',
            'With fields ["attributes"]: the user attribute handles to read (list_user_attributes lists them). Each is '
            . 'returned only if Concrete lets you view that attribute.',
            false,
            new ToolProperty('handle', PropertyType::STRING, 'A user attribute handle', true)
        );
    }

    /**
     * P3.5-2 (B-PII-2): the identity, and only the details asked for. An attribute must be named, and Concrete's View
     * User Attributes must allow that key.
     *
     * @param list<string> $fields
     * @param list<string> $attributeHandles
     */
    protected function serializeUser(UserInfo $userInfo, array $fields = [], array $attributeHandles = [], bool $dateAdded = false): array
    {
        $data = [
            'id' => $userInfo->getUserID(),
            'username' => $userInfo->getUserName(),
        ];
        if (in_array('email', $fields, true)) {
            $data['email'] = $userInfo->getUserEmail();
        }
        $data['isActive'] = $userInfo->isActive();
        if ($dateAdded) {
            $data['dateAdded'] = $userInfo->getUserDateAdded();
        }
        if (in_array('lastLogin', $fields, true)) {
            $data['lastLogin'] = $userInfo->getLastLogin();
        }
        if (in_array('timezone', $fields, true)) {
            $data['timezone'] = $userInfo->getUserTimezone();
        }

        if (in_array('attributes', $fields, true)) {
            $attributes = [];
            $notFound = [];
            $withheld = [];
            foreach ($attributeHandles as $handle) {
                $ak = UserAttributeKey::getByHandle((string) $handle);
                if (!$ak) {
                    $notFound[] = (string) $handle;
                } elseif (ObjectPermissions::viewUserAttribute($ak) !== null) {
                    $withheld[] = (string) $handle;
                } else {
                    $attributes[(string) $handle] = $userInfo->getAttribute($ak);
                }
            }
            $data['attributes'] = $attributes;
            if ($attributeHandles === []) {
                $data['attributesNote'] = 'Name the attribute handles in attributes; list_user_attributes lists them.';
            }
            if ($notFound !== []) {
                $data['attributesNotFound'] = $notFound;
            }
            if ($withheld !== []) {
                $data['attributesWithheld'] = $withheld;
                $data['attributesWithheldReason'] = ObjectPermissions::viewUserAttribute(UserAttributeKey::getByHandle($withheld[0]));
            }
        }

        return $data;
    }

    private function guarded(callable $callback): callable
    {
        return function (...$args) use ($callback): array {
            try {
                // P3.5-1: a login check, as every other toolkit's guarded() makes (this one checked nothing; a guest
                // was given every user's email address under the CLI).
                if (!(new User())->isRegistered()) {
                    throw new \RuntimeException('Authentication required: Please log in to use this tool.');
                }
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
