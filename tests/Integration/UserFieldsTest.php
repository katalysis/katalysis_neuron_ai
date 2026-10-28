<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Attribute\Key\UserKey;
use Concrete\Core\Http\Request;
use Concrete\Core\Permission\Key\Key as PermissionKey;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\ThrowawayPages;
use Katalysis\NeuronAi\Tests\Support\ToolByName;
use Katalysis\NeuronAi\Tools\ToolKind;

/**
 * P3.5-2 (B-PII-2): the user tools return the identifying fields by default, and contact and private details only when
 * the request asks for them (`fields`). A user attribute is returned only when named, and only if Concrete's View User
 * Attributes allows that key (as its user search does). No AI provider call; nothing written.
 */
final class UserFieldsTest extends ConcreteTestCase
{
    protected function tearDown(): void
    {
        Request::getInstance()->setCustomRequestUser(-1);
        $this->app()->make('cache/request')->flush();
    }

    /**
     * @return array<string, mixed>
     */
    private function call(string $tool, array $inputs): array
    {
        $output = ToolByName::run($tool, $inputs);
        $this->assertTrue(ToolKind::succeeded($output), "{$tool}: {$output}");
        $data = json_decode($output, true);
        $this->assertIsArray($data, $output);

        return $data;
    }

    private function editorRow(array $users): array
    {
        foreach ($users as $user) {
            if (($user['username'] ?? null) === 'neuron_editor') {
                return $user;
            }
        }
        $this->fail('neuron_editor is not in the list: run the seed.');
    }

    public function testListAndSearchLeaveOutTheEmailUnlessAskedFor(): void
    {
        ThrowawayPages::actAs('superuser');

        foreach (['list_users' => ['limit' => 100], 'search_users' => ['query' => 'neuron_editor']] as $tool => $inputs) {
            $row = $this->editorRow($this->call($tool, $inputs)['users']);
            $this->assertSame(['id', 'username', 'isActive'], array_keys($row), $tool);

            $row = $this->editorRow($this->call($tool, $inputs + ['fields' => ['email']])['users']);
            $this->assertSame('neuron_editor@neuron-test.invalid', $row['email'] ?? null, $tool);
        }
    }

    public function testGetUserGivesTheIdentityByDefaultAndTheRestOnRequest(): void
    {
        ThrowawayPages::actAs('superuser');

        $user = $this->call('get_user', ['identifier' => 'neuron_editor']);
        $this->assertSame(['id', 'username', 'isActive', 'dateAdded'], array_keys(array_diff_key($user, ['ok' => 1, 'error' => 1])));

        $user = $this->call('get_user', ['identifier' => 'neuron_editor', 'fields' => ['email', 'lastLogin', 'timezone']]);
        $this->assertSame('neuron_editor@neuron-test.invalid', $user['email']);
        $this->assertArrayHasKey('lastLogin', $user);
        $this->assertArrayHasKey('timezone', $user);
        $this->assertArrayNotHasKey('attributes', $user);
    }

    public function testAnAttributeIsReturnedOnlyWhenNamedAndAllowed(): void
    {
        $keys = UserKey::getList();
        if ($keys === []) {
            $this->markTestSkipped('No user attribute keys on this site.');
        }
        $handle = (string) $keys[0]->getAttributeKeyHandle();
        ThrowawayPages::actAs('superuser');

        $user = $this->call('get_user', ['identifier' => 'neuron_editor', 'fields' => ['attributes'], 'attributes' => [$handle]]);
        $this->assertArrayHasKey($handle, $user['attributes']);
        $this->assertCount(1, $user['attributes'], 'only the named attribute');

        $user = $this->call('get_user', ['identifier' => 'neuron_editor', 'fields' => ['attributes'], 'attributes' => ['no_such_user_attribute']]);
        $this->assertSame([], $user['attributes']);
        $this->assertContains('no_such_user_attribute', $user['attributesNotFound'] ?? []);

        // As neuron_editor, about their own account: Concrete's View User Attributes decides, key by key.
        ThrowawayPages::actAs('neuron_editor');
        $allowed = (bool) PermissionKey::getByHandle('view_user_attributes')->validate($keys[0]);
        $own = $this->call('get_current_user', ['fields' => ['attributes'], 'attributes' => [$handle]])['user'];
        $this->assertSame($allowed, array_key_exists($handle, $own['attributes']));
        $this->assertSame(!$allowed, in_array($handle, $own['attributesWithheld'] ?? [], true));
    }

    /**
     * BF-14 (B-PII-1): search_users lists, and counts, only users the requester may view, as list_users does. Found in
     * P3.5-2's scenario 34 run: neuron_editor's search found neuron_chat_editor, whom get_user then refused.
     */
    public function testSearchListsOnlyUsersTheRequesterMayView(): void
    {
        ThrowawayPages::actAs('neuron_editor');
        $target = $this->app()->make(\Concrete\Core\User\UserInfoRepository::class)->getByUserName('neuron_chat_editor');
        $this->assertNotNull($target, 'run the seed');
        if ((bool) (new \Concrete\Core\Permission\Checker($target))->canViewUser()) {
            $this->markTestSkipped('neuron_editor may view neuron_chat_editor on this site.');
        }

        $result = $this->call('search_users', ['query' => 'neuron_chat_editor']);

        $this->assertSame([], array_column($result['users'], 'username'), 'a user the requester may not view was listed');
        $this->assertSame(0, $result['total'] ?? null, 'and counted');

        // list_users had the same gap: UserList::getResults() never calls the permission checker set on the list.
        $listed = array_column($this->call('list_users', ['limit' => 100])['users'], 'username');
        $this->assertNotContains('neuron_chat_editor', $listed, 'list_users listed a user the requester may not view');
    }

    public function testTheCurrentUserSeesTheirOwnEmailButNoAttributesByDefault(): void
    {
        ThrowawayPages::actAs('neuron_editor');

        $own = $this->call('get_current_user', [])['user'];

        $this->assertSame(['id', 'username', 'email', 'isActive'], array_keys($own));
        $this->assertSame('neuron_editor@neuron-test.invalid', $own['email']);
    }
}
