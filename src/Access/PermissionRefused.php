<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Access;

/**
 * A tool refused because the user lacks a Concrete permission on the object (P3.5-1). The message is
 * ObjectPermissions's refusal: what was refused, which permission is missing, and who can grant it.
 */
final class PermissionRefused extends \RuntimeException
{
}
