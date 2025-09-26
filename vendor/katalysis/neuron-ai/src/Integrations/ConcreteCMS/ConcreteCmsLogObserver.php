<?php

declare(strict_types=1);

namespace NeuronAI\Integrations\ConcreteCMS;

/**
 * Concrete CMS specific LogObserver that uses Concrete's logging facade
 * instead of requiring psr/log dependency.
 * 
 * This is a drop-in replacement for the standard LogObserver when
 * running within Concrete CMS environment.
 */
class ConcreteCmsLogObserver implements \SplObserver
{
    public function update(\SplSubject $subject, ?string $event = null, mixed $data = null): void
    {
        if ($event !== null && class_exists('\Concrete\Core\Support\Facade\Log')) {
            // Use Concrete CMS Log facade instead of PSR Logger
            \Concrete\Core\Support\Facade\Log::info($event, $this->serializeData($data));
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeData(mixed $data): array
    {
        if ($data === null) {
            return [];
        }

        if (\is_array($data)) {
            return $data;
        }

        if (!\is_object($data)) {
            return ['data' => $data];
        }

        // Simple data extraction - for full compatibility, copy the match
        // logic from the upstream LogObserver when you update
        return [
            'event_class' => $data::class,
            'event_data' => $this->extractEventData($data)
        ];
    }

    /**
     * Extract event data safely
     */
    private function extractEventData(object $data): array
    {
        $result = [];
        
        // Use reflection to safely extract public properties
        $reflection = new \ReflectionClass($data);
        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            try {
                $value = $property->getValue($data);
                $result[$property->getName()] = $this->serializeValue($value);
            } catch (\Throwable) {
                // Skip properties that can't be accessed
                continue;
            }
        }
        
        return $result;
    }

    /**
     * Safely serialize a value for logging
     */
    private function serializeValue(mixed $value): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }
        
        if (is_array($value)) {
            return array_map([$this, 'serializeValue'], $value);
        }
        
        if (is_object($value) && method_exists($value, 'jsonSerialize')) {
            return $value->jsonSerialize();
        }
        
        if (is_object($value)) {
            return ['class' => $value::class];
        }
        
        return (string) $value;
    }
}
