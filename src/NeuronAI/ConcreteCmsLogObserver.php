<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\NeuronAI;

use NeuronAI\Observability\LogObserver;

/**
 * Concrete CMS specific LogObserver
 * Uses Concrete's logging facade instead of PSR logger
 */
class ConcreteCmsLogObserver extends LogObserver
{
    protected string $level;

    public function __construct(string $level = 'info')
    {
        $this->level = $level;
        // Don't call parent - we're not using PSR logger
    }

    public function onEvent(
        string $event, 
        object $source, 
        mixed $data = null, 
        ?string $branchId = null
    ): void {
        if (class_exists('\Concrete\Core\Support\Facade\Log')) {
            \Concrete\Core\Support\Facade\Log::{$this->level}(
                $event, 
                $this->serializeData($data)
            );
        }
    }
    
    protected function serializeData(mixed $data): array
    {
        if ($data === null) {
            return [];
        }
        
        if (is_array($data)) {
            return $data;
        }
        
        if (is_object($data)) {
            return $this->serializeObject($data);
        }
        
        return ['data' => (string) $data];
    }
    
    protected function serializeObject(object $data): array
    {
        $result = [
            'class' => get_class($data),
        ];
        
        // Try to get public properties
        $reflection = new \ReflectionClass($data);
        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $result[$property->getName()] = $property->getValue($data);
        }
        
        return $result;
    }
}
