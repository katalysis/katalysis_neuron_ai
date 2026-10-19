<?php
/**
 * Diagnostic script to test Neuron AI tools
 * Run from command line: php test_tools.php [pageID]
 */

define('C5_EXECUTE', true);
chdir(__DIR__);
require __DIR__ . '/../../concrete/bootstrap/configure.php';
require __DIR__ . '/../../concrete/bootstrap/autoload.php';
$cms = require __DIR__ . '/../../concrete/bootstrap/start.php';

$pageID = isset($argv[1]) ? (int)$argv[1] : null;

echo "================================\n";
echo "Neuron AI Tools Diagnostic\n";
echo "================================\n\n";

try {
    echo "1. Loading PagesToolkit...\n";
    $toolkit = new \Katalysis\NeuronAi\Tools\PagesToolkit();
    $tools = $toolkit->provide();
    echo "   ✓ Success: " . count($tools) . " tools loaded\n\n";
    
    echo "2. Listing available tools:\n";
    foreach ($tools as $tool) {
        echo "   - " . $tool->getName() . "\n";
    }
    echo "\n";
    
    if (!$pageID) {
        echo "USAGE: php test_tools.php [pageID]\n";
        echo "Provide a page ID to test tool functionality\n";
        exit(0);
    }
    
    echo "3. Testing get_page_blocks on page ID {$pageID}...\n";
    try {
        $page = \Concrete\Core\Page\Page::getByID($pageID, 'RECENT');
        if (!$page || $page->isError()) {
            throw new \Exception("Page {$pageID} not found");
        }
        
        echo "   ✓ Page loaded: " . $page->getCollectionName() . "\n";
        echo "   - Version ID: " . $page->getVersionID() . "\n";
        echo "   - Is Active: " . ($page->isActive() ? 'Yes' : 'No') . "\n";
        
        echo "\n4. Testing Area access...\n";
        $areaHandles = \Concrete\Core\Area\Area::getHandleList();
        echo "   - Total areas in system: " . count($areaHandles) . "\n";
        
        foreach ($areaHandles as $handle) {
            try {
                $area = \Concrete\Core\Area\Area::get($page, $handle);
                if ($area) {
                    $blocks = $area->getAreaBlocksArray($page);
                    if (count($blocks) > 0) {
                        echo "   ✓ Area '{$handle}': " . count($blocks) . " blocks\n";
                        foreach ($blocks as $block) {
                            echo "     [{$block->getBlockID()}] " . $block->getBlockTypeHandle() . "\n";
                        }
                    }
                }
            } catch (\Exception $e) {
                echo "   ✗ Error accessing area '{$handle}': " . $e->getMessage() . "\n";
            }
        }
        
        echo "\n5. Testing block content extraction...\n";
        try {
            $reflection = new \ReflectionClass($toolkit);
            $method = $reflection->getMethod('extractContentFromBlocks');
            $method->setAccessible(true);
            $content = $method->invoke($toolkit, $page);
            
            $contentLength = strlen($content);
            echo "   ✓ Extracted {$contentLength} characters of content\n";
            
            if (strpos($content, '<!-- Error') !== false) {
                echo "   ⚠ Content contains error comments:\n";
                preg_match_all('/<!-- Error[^>]*-->/', $content, $matches);
                foreach ($matches[0] as $error) {
                    echo "     " . $error . "\n";
                }
            }
        } catch (\Exception $e) {
            echo "   ✗ Content extraction failed: " . $e->getMessage() . "\n";
        }
        
    } catch (\Exception $e) {
        echo "   ✗ Error: " . $e->getMessage() . "\n";
        echo "   Stack trace:\n" . $e->getTraceAsString() . "\n";
    }
    
} catch (\Exception $e) {
    echo "✗ FATAL ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}

echo "\n================================\n";
echo "Diagnostic complete\n";
echo "================================\n";
