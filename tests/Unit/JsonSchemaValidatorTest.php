<?php

namespace Tests\Unit;

use App\AI\JsonSchemaValidator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class JsonSchemaValidatorTest extends TestCase
{
    public function test_validates_nested_object_and_array_types(): void
    {
        (new JsonSchemaValidator())->validate(['issues' => [['summary' => 'Broken mobile layout']]], [
            'type' => 'object',
            'required' => ['issues'],
            'properties' => ['issues' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['summary'], 'properties' => ['summary' => ['type' => 'string']]]]],
        ]);
        self::assertTrue(true);
    }

    public function test_rejects_wrong_nested_types_and_missing_required_fields(): void
    {
        $validator = new JsonSchemaValidator();
        $schema = ['type' => 'object', 'required' => ['issues'], 'properties' => ['issues' => ['type' => 'array']]];

        try {
            $validator->validate(['issues' => 'none'], $schema);
            self::fail('Expected invalid structured output to be rejected.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('issues', $exception->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $validator->validate([], $schema);
    }
}
