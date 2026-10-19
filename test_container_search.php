<?php
/**
 * Test script to find blocks in container 12704
 */

define('C5_EXECUTE', true);

require __DIR__ . '/../../concrete/bootstrap/configure.php';
require __DIR__ . '/../../concrete/bootstrap/autoload.php';
$cms = require __DIR__ . '/../../concrete/bootstrap/start.php';

$db = $cms->make('database')->connection();
$qb = $db->createQueryBuilder();

// Find blocks in container 12704 (including drafts)
$containerBlockID = 12704;
$likePattern = $containerBlockID . ' : %';

$qb->select(
        'b.bID',
        'bt.btHandle',
        'bt.btName',
        'cvb.arHandle',
        'cvb.cID',
        'cvb.cvID',
        'cv.cvName as pageName',
        'cv.cvIsApproved',
        'b.bDateModified'
    )
    ->from('Blocks', 'b')
    ->innerJoin('b', 'BlockTypes', 'bt', 'b.btID = bt.btID')
    ->innerJoin('b', 'CollectionVersionBlocks', 'cvb', 'b.bID = cvb.bID')
    ->innerJoin('cvb', 'CollectionVersions', 'cv', 'cvb.cID = cv.cID AND cvb.cvID = cv.cvID')
    ->innerJoin('cvb', 'Pages', 'p', 'cvb.cID = p.cID')
    ->where($qb->expr()->like('cvb.arHandle', ':pattern'))
    ->setParameter('pattern', $likePattern)
    ->orderBy('cvb.arHandle', 'ASC');

$results = $qb->execute()->fetchAllAssociative();

echo "=== Blocks in container #{$containerBlockID} ===\n\n";
if (empty($results)) {
    echo "No blocks found.\n\n";
    
    // Also check if the container block itself exists
    echo "Checking if container block exists...\n";
    $containerCheck = $db->createQueryBuilder()
        ->select('b.bID', 'bt.btHandle', 'cvb.arHandle', 'cvb.cID', 'cv.cvName')
        ->from('Blocks', 'b')
        ->innerJoin('b', 'BlockTypes', 'bt', 'b.btID = bt.btID')
        ->innerJoin('b', 'CollectionVersionBlocks', 'cvb', 'b.bID = cvb.bID')
        ->innerJoin('cvb', 'CollectionVersions', 'cv', 'cvb.cID = cv.cID AND cvb.cvID = cv.cvID')
        ->where('b.bID = :bID')
        ->setParameter('bID', $containerBlockID)
        ->execute()
        ->fetchAllAssociative();
    
    if (!empty($containerCheck)) {
        echo "Container block found:\n";
        foreach ($containerCheck as $c) {
            echo "  Type: {$c['btHandle']}, Area: {$c['arHandle']}, Page: {$c['cvName']}\n";
        }
    } else {
        echo "Container block #{$containerBlockID} does not exist.\n";
    }
} else {
    foreach ($results as $row) {
        $status = $row['cvIsApproved'] ? 'ACTIVE' : 'DRAFT';
        echo "Block ID: {$row['bID']}\n";
        echo "  Type: {$row['btHandle']} ({$row['btName']})\n";
        echo "  Area: {$row['arHandle']}\n";
        echo "  Page: {$row['pageName']} (cID: {$row['cID']}, cvID: {$row['cvID']})\n";
        echo "  Status: {$status}\n";
        echo "  Modified: {$row['bDateModified']}\n\n";
    }
}
