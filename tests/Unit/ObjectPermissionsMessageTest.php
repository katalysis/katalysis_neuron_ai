<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Unit;

use Katalysis\NeuronAi\Access\ObjectPermissions;
use Katalysis\NeuronAi\Access\PermissionRefused;
use PHPUnit\Framework\TestCase;

/**
 * P3.5-1 (B-ACC-6): the refusal a permission check gives the model says what was refused, which permission is
 * missing, who can grant it, and, for a write, that nothing changed and that it must not try another route.
 */
final class ObjectPermissionsMessageTest extends TestCase
{
    public function testAWriteRefusalNamesTheActionThePermissionWhoCanGrantItAndForbidsAnotherRoute(): void
    {
        $message = ObjectPermissions::message('edit the content of page 12 (/neuron-test/locked)', 'Edit Contents', 'A site administrator can grant it in the page\'s Permissions.');

        $this->assertStringStartsWith('Permission denied: ', $message);
        $this->assertStringContainsString("you can't edit the content of page 12 (/neuron-test/locked)", $message);
        $this->assertStringContainsString('"Edit Contents" permission', $message);
        $this->assertStringContainsString('A site administrator can grant it', $message);
        $this->assertStringContainsString('Nothing was changed.', $message);
        $this->assertStringContainsString("don't try another tool to make the same change", $message);
    }

    public function testAReadRefusalDoesNotSayNothingWasChanged(): void
    {
        $message = ObjectPermissions::message('read page 12 (/x)', 'View', 'Grant.', false);

        $this->assertStringNotContainsString('Nothing was changed', $message);
        $this->assertStringContainsString("don't look for another way to read it", $message);
    }

    public function testThePermissionsObjectIsNamedWhenItIsNotTheOneRefused(): void
    {
        $message = ObjectPermissions::message('create a page attribute', 'View', 'Grant.', true, 'Dashboard page /dashboard/pages/attributes');

        $this->assertStringContainsString('"View" permission on Dashboard page /dashboard/pages/attributes', $message);
    }

    public function testDenyThrowsTheRefusalAndPassesNull(): void
    {
        ObjectPermissions::deny(null);

        $this->expectException(PermissionRefused::class);
        $this->expectExceptionMessage('Permission denied: you can\'t x.');
        ObjectPermissions::deny(ObjectPermissions::message('x', 'Y', 'Z.'));
    }
}
