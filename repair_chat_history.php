<?php
/**
 * Chat History Repair Utility
 * 
 * Fixes corrupted conversation histories with invalid message sequences
 * Run: php repair_chat_history.php [chatId]
 */

define('C5_EXECUTE', true);
chdir(__DIR__ . '/../..');
require __DIR__ . '/../../concrete/bootstrap/configure.php';
require __DIR__ . '/../../concrete/bootstrap/autoload.php';
$cms = require __DIR__ . '/../../concrete/bootstrap/start.php';

$app = \Concrete\Core\Support\Facade\Application::getFacadeApplication();
$entityManager = $app->make('Doctrine\ORM\EntityManager');

$chatId = isset($argv[1]) ? (int)$argv[1] : null;

echo "================================\n";
echo "Chat History Repair Utility\n";
echo "================================\n\n";

if (!$chatId) {
    echo "USAGE: php repair_chat_history.php [chatId]\n\n";
    echo "Available chats:\n";
    
    $chats = $entityManager->getRepository(\Katalysis\NeuronAi\Entity\Chat::class)->findAll();
    
    if (empty($chats)) {
        echo "  No chats found in database\n";
        exit(0);
    }
    
    foreach ($chats as $chat) {
        echo sprintf(
            "  ID: %d | Messages: %d | First: %s\n",
            $chat->getId(),
            $chat->getUserMessageCount(),
            substr($chat->getFirstMessage(), 0, 60)
        );
    }
    exit(0);
}

// Load the chat
echo "Loading chat ID {$chatId}...\n";
$chat = $entityManager->find(\Katalysis\NeuronAi\Entity\Chat::class, $chatId);

if (!$chat) {
    echo "✗ Chat {$chatId} not found\n";
    exit(1);
}

echo "✓ Chat loaded: " . substr($chat->getFirstMessage(), 0, 60) . "\n";

// Parse existing history
$historyJson = $chat->getChatHistory();
if (!$historyJson) {
    echo "✓ Chat history is empty - no repair needed\n";
    exit(0);
}

$messages = json_decode($historyJson, true);
if (!is_array($messages)) {
    echo "✗ Failed to parse chat history JSON\n";
    exit(1);
}

echo "Found " . count($messages) . " messages in history\n\n";

// Validate and repair
echo "Validating message sequence...\n";
$repaired = [];
$errors = [];
$expectingUser = true;
$duplicateCount = 0;

foreach ($messages as $index => $message) {
    $role = $message['role'] ?? 'unknown';
    $expectedRole = $expectingUser ? 'user' : 'assistant';
    
    if ($role !== $expectedRole) {
        $errors[] = sprintf(
            "Position %d: Expected %s, got %s",
            $index,
            $expectedRole,
            $role
        );
        
        // Auto-repair strategy: Insert a placeholder message to fix alternation
        if ($role === 'user' && !$expectingUser) {
            // Two user messages in a row - insert assistant placeholder
            $repaired[] = [
                'role' => 'assistant',
                'content' => '[Recovered: Assistant response was interrupted]',
                'usage' => null,
                '_recovered' => true
            ];
            $duplicateCount++;
            echo "  ⚠ Position {$index}: Inserted placeholder assistant message\n";
        } elseif ($role === 'assistant' && $expectingUser) {
            // Two assistant messages in a row - insert user placeholder
            $repaired[] = [
                'role' => 'user',
                'content' => '[Recovered: User message was lost]',
                '_recovered' => true
            ];
            $duplicateCount++;
            echo "  ⚠ Position {$index}: Inserted placeholder user message\n";
        }
    }
    
    $repaired[] = $message;
    $expectingUser = !$expectingUser;
}

echo "\n";

if (empty($errors)) {
    echo "✓ No errors found - chat history is valid\n";
    exit(0);
}

echo "Found " . count($errors) . " error(s):\n";
foreach ($errors as $error) {
    echo "  - {$error}\n";
}

echo "\nRepair summary:\n";
echo "  - Original messages: " . count($messages) . "\n";
echo "  - Repaired messages: " . count($repaired) . "\n";
echo "  - Placeholders inserted: {$duplicateCount}\n\n";

// Ask for confirmation
echo "Apply repair? (yes/no): ";
$handle = fopen ("php://stdin","r");
$line = trim(fgets($handle));

if (strtolower($line) !== 'yes') {
    echo "Repair cancelled\n";
    exit(0);
}

// Apply repair
try {
    $chat->setChatHistory(json_encode($repaired, JSON_PRETTY_PRINT));
    $chat->setUpdatedDate(new \DateTime());
    $entityManager->flush();
    
    echo "\n✓ Chat history repaired successfully!\n";
    echo "  Database updated for chat ID {$chatId}\n";
    
} catch (\Exception $e) {
    echo "\n✗ Failed to save repaired history: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n================================\n";
echo "Repair complete\n";
echo "================================\n";
