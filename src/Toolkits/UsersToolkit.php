<?php

namespace Katalysis\NeuronAi\Toolkits;

use NeuronAI\Tools\Toolkits\AbstractToolkit;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\PropertyType;
use Concrete\Core\User\User;
use Concrete\Core\User\UserList;
use Concrete\Core\User\UserInfo;

/**
 * Native Concrete CMS Users Toolkit
 * 
 * Provides 4 tools for managing users:
 * - list_users: List users in the system
 * - get_user: Get detailed user information
 * - get_current_user: Get information about the currently logged-in user
 * - search_users: Search for users by username or email
 */
class UsersToolkit extends AbstractToolkit
{
    public function provide(): array
    {
        return [
            $this->createListUsersTool(),
            $this->createGetUserTool(),
            $this->createGetCurrentUserTool(),
            $this->createSearchUsersTool(),
        ];
    }

    /**
     * Tool: list_users
     * List users in the system
     */
    protected function createListUsersTool(): Tool
    {
        $tool = new Tool(
            'list_users',
            'List users in the system, sorted by date added descending',
            [
                ToolProperty::make('limit', PropertyType::INTEGER, 'Maximum number of users to return (default: 20, max: 100)', false),
                ToolProperty::make('isActive', PropertyType::STRING, 'Filter by active status: "active", "inactive", or "all" (default: "all")', false),
            ]
        );

        $tool->setCallable(function(?int $limit = null, ?string $isActive = 'all'): string {
            try {
                $ul = new UserList();
                
                // Apply filters
                if ($isActive === 'active') {
                    $ul->filterByIsActive(true);
                } elseif ($isActive === 'inactive') {
                    $ul->filterByIsActive(false);
                }
                
                $ul->setItemsPerPage(min($limit ?? 20, 100));
                $ul->sortByUserID();
                
                $users = $ul->getResults();
                
                if (empty($users)) {
                    return "No users found.";
                }
                
                $count = count($users);
                $output = "👥 **Found {$count} user(s):**\n\n";
                
                foreach ($users as $ui) {
                    if (!$ui instanceof UserInfo) continue;
                    
                    $uID = $ui->getUserID();
                    $username = $ui->getUserName();
                    $email = $ui->getUserEmail();
                    $dateAdded = $ui->getUserDateAdded();
                    $isActive = $ui->isActive() ? 'Active' : 'Inactive';
                    
                    $output .= "**{$username}** (ID: {$uID})\n";
                    $output .= "- Email: {$email}\n";
                    $output .= "- Status: {$isActive}\n";
                    $output .= "- Joined: {$dateAdded}\n\n";
                }
                
                return $output;
                
            } catch (\Exception $e) {
                return "❌ **Error listing users:** " . $e->getMessage();
            }
        });

        return $tool;
    }

    /**
     * Tool: get_user
     * Get detailed user information
     */
    protected function createGetUserTool(): Tool
    {
        $tool = new Tool(
            'get_user',
            'Get detailed information about a specific user by ID or username',
            [
                ToolProperty::make('identifier', PropertyType::STRING, 'User ID (e.g., "123") or username (e.g., "admin")', true),
            ]
        );

        $tool->setCallable(function(?string $identifier): string {
            try {
                if (!$identifier) {
                    return "❌ User identifier is required.";
                }

                // Get user by ID or username
                if (is_numeric($identifier)) {
                    $ui = UserInfo::getByID($identifier);
                } else {
                    $ui = UserInfo::getByUserName($identifier);
                }
                
                if (!$ui) {
                    return "❌ User '$identifier' not found.";
                }
                
                $uID = $ui->getUserID();
                $username = $ui->getUserName();
                $email = $ui->getUserEmail();
                $dateAdded = $ui->getUserDateAdded();
                $lastLogin = $ui->getLastLogin();
                $isActive = $ui->isActive() ? 'Yes' : 'No';
                $totalLogins = $ui->getUserTotalLogins();
                
                // Get groups
                $groups = [];
                foreach ($ui->getUserGroups() as $groupID => $groupName) {
                    $groups[] = $groupName;
                }
                $groupsList = !empty($groups) ? implode(', ', $groups) : 'None';
                
                $output = "👤 **User Details**\n\n";
                $output .= "**{$username}**\n\n";
                $output .= "| Property | Value |\n";
                $output .= "|----------|-------|\n";
                $output .= "| ID | {$uID} |\n";
                $output .= "| Username | {$username} |\n";
                $output .= "| Email | {$email} |\n";
                $output .= "| Active | {$isActive} |\n";
                $output .= "| Joined | {$dateAdded} |\n";
                $output .= "| Last Login | {$lastLogin} |\n";
                $output .= "| Total Logins | {$totalLogins} |\n";
                $output .= "| Groups | {$groupsList} |\n";
                
                return $output;
                
            } catch (\Exception $e) {
                return "❌ **Error getting user:** " . $e->getMessage();
            }
        });

        return $tool;
    }

    /**
     * Tool: get_current_user
     * Get information about the currently logged-in user
     */
    protected function createGetCurrentUserTool(): Tool
    {
        $tool = new Tool(
            'get_current_user',
            'Get information about the currently logged-in user',
            []
        );

        $tool->setCallable(function(): string {
            try {
                $u = new User();
                
                if (!$u->isRegistered()) {
                    return "ℹ️ No user is currently logged in (guest user).";
                }
                
                $ui = $u->getUserInfoObject();
                
                if (!$ui) {
                    return "❌ Could not retrieve current user information.";
                }
                
                $uID = $ui->getUserID();
                $username = $ui->getUserName();
                $email = $ui->getUserEmail();
                $dateAdded = $ui->getUserDateAdded();
                $lastLogin = $ui->getLastLogin();
                
                // Get groups
                $groups = [];
                foreach ($ui->getUserGroups() as $groupID => $groupName) {
                    $groups[] = $groupName;
                }
                $groupsList = !empty($groups) ? implode(', ', $groups) : 'None';
                
                $output = "👤 **Current User**\n\n";
                $output .= "**{$username}**\n\n";
                $output .= "| Property | Value |\n";
                $output .= "|----------|-------|\n";
                $output .= "| ID | {$uID} |\n";
                $output .= "| Username | {$username} |\n";
                $output .= "| Email | {$email} |\n";
                $output .= "| Joined | {$dateAdded} |\n";
                $output .= "| Last Login | {$lastLogin} |\n";
                $output .= "| Groups | {$groupsList} |\n";
                
                return $output;
                
            } catch (\Exception $e) {
                return "❌ **Error getting current user:** " . $e->getMessage();
            }
        });

        return $tool;
    }

    /**
     * Tool: search_users
     * Search for users by username or email
     */
    protected function createSearchUsersTool(): Tool
    {
        $tool = new Tool(
            'search_users',
            'Search for users by username or email address',
            [
                ToolProperty::make('query', PropertyType::STRING, 'Search query (username or email)', true),
                ToolProperty::make('limit', PropertyType::INTEGER, 'Maximum results to return (default: 10)', false),
            ]
        );

        $tool->setCallable(function(?string $query, ?int $limit = null): string {
            try {
                if (!$query) {
                    return "❌ Search query is required.";
                }

                $ul = new UserList();
                $ul->filterByKeywords($query);
                $ul->setItemsPerPage(min($limit ?? 10, 100));
                
                $users = $ul->getResults();
                
                if (empty($users)) {
                    return "No users found matching '{$query}'.";
                }
                
                $count = count($users);
                $output = "👥 **Found {$count} user(s) matching '{$query}':**\n\n";
                
                foreach ($users as $ui) {
                    if (!$ui instanceof UserInfo) continue;
                    
                    $uID = $ui->getUserID();
                    $username = $ui->getUserName();
                    $email = $ui->getUserEmail();
                    $isActive = $ui->isActive() ? 'Active' : 'Inactive';
                    
                    $output .= "**{$username}** (ID: {$uID})\n";
                    $output .= "- Email: {$email}\n";
                    $output .= "- Status: {$isActive}\n\n";
                }
                
                return $output;
                
            } catch (\Exception $e) {
                return "❌ **Error searching users:** " . $e->getMessage();
            }
        });

        return $tool;
    }
}
