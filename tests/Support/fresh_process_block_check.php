<?php

/**
 * BF-19: ObjectPermissions' View Block and Edit Block checks on a block in an area that inherits the page's permissions,
 * in a PHP process where nothing has referenced Concrete's global `Page` alias yet (CoreAliasesTest runs it with
 * c5:exec). Prints one JSON line. Reads only.
 *
 *   php concrete/bin/concrete c5:exec packages/katalysis_neuron_ai/tests/Support/fresh_process_block_check.php -- <user> <path>
 */

use Concrete\Core\Block\Block;
use Concrete\Core\Http\Request;
use Concrete\Core\Page\Page;
use Concrete\Core\Support\Facade\Application;
use Concrete\Core\User\UserInfoRepository;
use Katalysis\NeuronAi\Access\ObjectPermissions;

$user = (string) ($args[0] ?? 'neuron_content_editor');
$path = (string) ($args[1] ?? '/neuron-test/edit-mode');

$app = Application::getFacadeApplication();
$db = $app->make('database')->connection();
$aliasLoadedBefore = class_exists('Page', false);

Request::getInstance()->setCustomRequestUser($app->make(UserInfoRepository::class)->getByUserName($user));
$app->make('cache/request')->flush();

$page = Page::getByPath($path, 'RECENT');
$row = $db->fetchAssociative('SELECT bID, arHandle FROM CollectionVersionBlocks WHERE cID = ? AND cvID = ? ORDER BY cbDisplayOrder LIMIT 1', [$page->getCollectionID(), $page->getVersionID()]);
$override = (int) $db->fetchOne('SELECT arOverrideCollectionPermissions FROM Areas WHERE cID = ? AND arHandle = ?', [$page->getCollectionID(), $row['arHandle']]);
$block = Block::getByID((int) $row['bID'], $page, (string) $row['arHandle']);

echo json_encode([
    'aliasLoadedBefore' => $aliasLoadedBefore,
    'areaOverrides' => $override === 1,
    'viewPage' => ObjectPermissions::viewPage($page),
    'viewBlock' => ObjectPermissions::viewBlock($block),
    'editBlock' => ObjectPermissions::editBlock($block),
]), "\n";
