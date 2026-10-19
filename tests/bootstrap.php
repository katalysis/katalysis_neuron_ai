<?php

/**
 * PHPUnit bootstrap for the Katalysis Neuron AI package.
 *
 * It does NOT boot Concrete. Unit tests run without the dev site. Tests that
 * need Concrete extend Tests\Support\ConcreteTestCase, which boots it through
 * Tests\Support\ConcreteBoot. That is the only boot path, and it enforces the
 * dev-host guard, so no test can use Concrete without passing it.
 *
 * Rules for tests (see CLAUDE.md, Testing):
 * - No test may call the AI provider or use an API key. Only the scenario
 *   runner does that.
 * - Tests that write must clean up after themselves.
 */

declare(strict_types=1);

$packageDir = dirname(__DIR__);

// Test classes.
spl_autoload_register(static function (string $class) use ($packageDir): void {
    $prefix = 'Katalysis\\NeuronAi\\Tests\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = $packageDir . '/tests/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// Package and Neuron classes, for unit tests that run without Concrete. When
// Concrete boots, ConcreteBoot loads the same autoloader after its own, as the
// package controller does.
spl_autoload_register(static function (string $class): void {
    static $loaded = false;
    if ($loaded || !(str_starts_with($class, 'Katalysis\\NeuronAi\\') || str_starts_with($class, 'NeuronAI\\'))) {
        return;
    }
    $loaded = true;
    \Katalysis\NeuronAi\Tests\Support\ConcreteBoot::loadPackageAutoload();
    // The loader registered above isn't consulted for the class being loaded right now.
    spl_autoload_call($class);
});
