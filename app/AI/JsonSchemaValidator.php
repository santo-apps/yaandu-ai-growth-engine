<?php

namespace App\AI;

use RuntimeException;

final class JsonSchemaValidator
{
    public function validate(array $value, array $schema): void
    {
        $this->assertValue($value, $schema, '$');
    }

    private function assertValue(mixed $value, array $schema, string $path): void
    {
        $type = $schema['type'] ?? (isset($schema['required']) || isset($schema['properties']) ? 'object' : null);
        if ($type !== null && ! $this->matchesType($value, $type)) {
            throw new RuntimeException("Structured output at {$path} must be of type [{$this->typeName($type)}].");
        }

        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            throw new RuntimeException("Structured output at {$path} is not an allowed value.");
        }

        if (is_array($value) && ($type === 'object' || isset($schema['required']) || isset($schema['properties']))) {
            foreach ($schema['required'] ?? [] as $key) {
                if (! array_key_exists($key, $value)) throw new RuntimeException("Structured output at {$path} is missing required field [{$key}].");
            }
            foreach ($schema['properties'] ?? [] as $key => $propertySchema) {
                if (array_key_exists($key, $value) && is_array($propertySchema)) $this->assertValue($value[$key], $propertySchema, $path.'.'.$key);
            }
            if (($schema['additionalProperties'] ?? true) === false) {
                $unknown = array_diff(array_keys($value), array_keys($schema['properties'] ?? []));
                if ($unknown !== []) throw new RuntimeException("Structured output at {$path} contains unknown fields.");
            }
        }

        if (is_array($value) && ($type === 'array' || isset($schema['items']))) {
            if (isset($schema['minItems']) && count($value) < $schema['minItems']) throw new RuntimeException("Structured output at {$path} has too few items.");
            if (isset($schema['maxItems']) && count($value) > $schema['maxItems']) throw new RuntimeException("Structured output at {$path} has too many items.");
            if (isset($schema['items']) && is_array($schema['items'])) {
                foreach ($value as $index => $item) $this->assertValue($item, $schema['items'], $path.'.'.$index);
            }
        }

        if (is_string($value)) {
            if (isset($schema['minLength']) && mb_strlen($value) < $schema['minLength']) throw new RuntimeException("Structured output at {$path} is too short.");
            if (isset($schema['maxLength']) && mb_strlen($value) > $schema['maxLength']) throw new RuntimeException("Structured output at {$path} is too long.");
        }
    }

    private function matchesType(mixed $value, string|array $types): bool
    {
        foreach ((array) $types as $type) {
            $matches = match ($type) {
                'object' => is_array($value) && ! array_is_list($value),
                'array' => is_array($value) && array_is_list($value),
                'string' => is_string($value),
                'integer' => is_int($value),
                'number' => is_int($value) || is_float($value),
                'boolean' => is_bool($value),
                'null' => $value === null,
                default => true,
            };
            if ($matches) return true;
        }
        return false;
    }

    private function typeName(string|array $type): string
    {
        return implode('|', (array) $type);
    }
}
