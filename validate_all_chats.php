<?php
/**
 * Batch Chat History Validator
 * 
 * Scans all chats and reports/fixes invalid message sequences
 * Run: php validate_all_chats.php [--fix]
 */

define('C5_EXECUTE', true);
chdir(__DIR__ . '/../..');
require __DIR__ . '/../../concrete/bootstrap/configure.php';
require __DIR__ . '/../../concrete/bootstrap/autoload.php';
$cms = require __DIR__ . '/../../concrete/bootstrap/start.php';

$app = \Concrete\Core\Support\Facade\Application::getFacadeApplication();
$entityManager = $app->make('Doctrine\ORM\EntityManager');

$autoFix = in_array('--fix', $argv);

echo "================================\n";
echo "Batch Chat History Validator\n";
echo "================================\n\n";

// Load all chats
$chats = $entityManager->getRepository(\Katalysis\NeuronAi\Entity\Chat::class)->findAll();

if (empty($chats)) {
    echo "No chats found in database\n";
    exit(0);
}

echo "Found " . count($chats) . " chat(s) to validate\n";
if ($autoFix) {
    echo "Auto-fix mode: ENABLED\n";
} else {
    echo "Auto-fix mode: DISABLED (use --fix to enable)\n";
}
echo "\n";

$validCount = 0;
$invalidCount = 0;
$fixedCount = 0;
$failedCount = 0;

foreach ($chats as $chat) {
    $chatId = $chat->getId();
    $firstMsg = substr($chat->getFirstMessage(), 0, 40);
    
    echo sprintf("Chat %d: %s...\n", $chatId, $firstMsg);
    
    $historyJson = $chat->getChatHistory();
    if (!$historyJson) {
        echo "  ✓ Empty - OK\n";
        $validCount++;
        continue;
    }
    
    $messages = json_decode($historyJson, true);
    if (!is_array($messages)) {
        echo "  ✗ Invalid JSON\n";
        $invalidCount++;
        $failedCount++;
        continue;
    }
    
    // Validate alternation
    $errors = [];
    $expectingUser = true;
    
    foreach ($messages as $index => $message) {
        $role = $message['role'] ?? 'unknown';
        $expectedRole = $expectingUser ? 'user' : 'assistant';
        
        if ($role !== $expectedRole) {
            $errors[] = sprintf("Pos %d: expected %s, got %s", $index, $expectedRole, $role);
        }
        
        $expectingUser = !$expectingUser;
    }
    
    if (empty($errors)) {
        echo "  ✓ Valid (" . count($messages) . " messages)\n";
        $validCount++;
        continue;
    }
    
    // Found errors
    echo "  ✗ INVALID:\n";
    foreach ($errors as $error) {
        echo "    - {$error}\n";
    }
    $invalidCount++;
    
    if (!$autoFix) {
        echo "    (run with --fix to repair)\n";
        continue;
    }
    
    // Auto-repair
    echo "    Repairing...";
    
    try {
        $repaired = [];
        $expectingUser = true;
        $placeholderCount = 0;
        
        foreach ($messages as $index => $message) {
            $role = $message['role'] ?? 'unknown';
            $expectedRole = $expectingUser ? 'user' : 'assistant';
            
            if ($role !== $expectedRole) {
                // Insert placeholder
                $repaired[] = [
                    'role' => $expectedRole,
                    'content' => $expectedRole === 'user' 
                        ? '[Recovered: User message was lost]' 
                        : '[Recovered: Assistant response was interrupted]',
                    'usage' => null,
                    '_recovered' => true
                ];
                $placeholderCount++;
            }
            
            $repaired[] = $message;
            $expectingUser = !$expectingUser;
        }
        
        $chat->setChatHistory(json_encode($repaired, JSON_PRETTY_PRINT));
        $chat->setUpdatedDate(new \DateTime());
        $entityManager->flush();
        
        echo " ✓ Fixed (+{$placeholderCount} placeholders)\n";
        $fixedCount++;
        
    } catch (\Exception $e) {
        echo " ✗ FAILED: " . $e->getMessage() . "\n";
        $failedCount++;
    }
}

echo "\n================================\n";
echo "Summary\n";
echo "================================\n";
echo "Total chats:    " . count($chats) . "\n";
echo "Valid:          {$validCount}\n";
echo "Invalid:        {$invalidCount}\n";

if ($autoFix) {
    echo "Fixed:          {$fixedCount}\n";
    echo "Failed to fix:  {$failedCount}\n";
}

if ($invalidCount > 0 && !$autoFix) {
    echo "\nTo repair invalid chats, run: php validate_all_chats.php --fix\n";
}

echo "\n";
