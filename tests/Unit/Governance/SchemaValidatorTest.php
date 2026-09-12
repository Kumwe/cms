<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Governance;

use Kumwe\App\Tools\Governance\GovernanceViolation;
use Kumwe\App\Tools\Governance\SchemaValidator;
use Kumwe\App\Tools\Governance\StrictYaml;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Holds `tools/Governance/SchemaValidator.php` to the JSON Schema subset it executes.
 *
 * Every supported keyword is exercised in both directions, a schema outside the subset is refused when it is
 * loaded, every shipped schema loads, and every documented example validates against its schema.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class SchemaValidatorTest extends TestCase
{
    /**
     * Scratch schema files written by a test.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private array $temporary = [];

    /**
     * Load the governance classes once.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/tools/Governance/bootstrap.php';
    }

    /**
     * Remove scratch schema files.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->temporary = [];
    }

    /**
     * A valid document yields no violation and each keyword reports its own pointer and rule when broken.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEachKeywordReportsItsPointerAndRule(): void
    {
        $schema = $this->schema([
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['schema', 'items', 'name'],
            'properties' => [
                'schema' => ['const' => 'x/v1'],
                'name' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 4, 'pattern' => '^[a-z]+$'],
                'items' => [
                    'type' => 'array',
                    'items' => ['$ref' => '#/$defs/item'],
                    'minItems' => 1,
                    'maxItems' => 2,
                    'uniqueItems' => true,
                ],
                'count' => ['type' => ['integer', 'null'], 'minimum' => 1, 'maximum' => 9],
                'kind' => ['oneOf' => [['const' => 'a'], ['const' => 'b']]],
                'either' => ['anyOf' => [['type' => 'string'], ['type' => 'integer']]],
                'both' => ['allOf' => [['type' => 'string'], ['pattern' => '^k']]],
                'never' => ['not' => ['const' => 'forbidden']],
                'level' => ['type' => 'string', 'enum' => ['low', 'high']],
                'free' => [],
            ],
            '$defs' => [
                'item' => [
                    'type' => 'object',
                    'required' => ['id'],
                    'properties' => ['id' => ['type' => 'string']],
                    'additionalProperties' => false,
                ],
            ],
        ]);
        $validator = new SchemaValidator();

        self::assertSame([], $validator->validate([
            'schema' => 'x/v1',
            'name' => 'ab',
            'items' => [['id' => 'p']],
            'count' => 5,
            'kind' => 'a',
            'either' => 3,
            'both' => 'kumwe',
            'never' => 'fine',
            'level' => 'low',
            'free' => ['anything' => [1, 2]],
        ], $schema));

        $violations = $validator->validate([
            'schema' => 'y',
            'name' => 'A',
            'items' => [['id' => 1, 'extra' => true], ['id' => 1, 'extra' => true], ['id' => 'z']],
            'count' => 12,
            'kind' => 'c',
            'either' => [],
            'both' => 'x',
            'never' => 'forbidden',
            'level' => 'mid',
            'surplus' => 1,
        ], $schema);

        $expected = [
            '#/schema: must equal "x/v1"',
            '#/name: must match pattern ^[a-z]+$',
            '#/name: must be at least 2 characters long',
            '#/items: must have at most 2 items',
            '#/items: must not contain duplicate items',
            '#/items/0/id: must be of type string, integer given',
            '#/items/0/extra: the property "extra" is not allowed here',
            '#/count: must be at most 9',
            '#/kind: must match exactly one alternative (0 matched)',
            '#/either: must match at least one alternative',
            '#/both: must match pattern ^k',
            '#/never: must not match the excluded schema',
            '#/level: must be one of "low", "high"',
            '#/surplus: the property "surplus" is not allowed here',
        ];
        foreach ($expected as $violation) {
            self::assertContains($violation, $violations);
        }
        self::assertContains(
            '#: is missing the required property "name"',
            $validator->validate(['schema' => 'x/v1', 'items' => [['id' => 'p']]], $schema),
        );
    }

    /**
     * A schema keyword the validator does not execute, an unresolved reference or a bad pattern is refused at load.
     *
     * @param   array<string, mixed>  $schema  Schema outside the subset.
     * @param   string                $rule    Fragment of the expected message.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('unsupportedSchemas')]
    public function testSchemasOutsideTheSubsetAreRefused(array $schema, string $rule): void
    {
        $path = $this->schema($schema);
        try {
            (new SchemaValidator())->validate([], $path);
            self::fail('The schema must be refused.');
        } catch (GovernanceViolation $violation) {
            self::assertStringContainsString($rule, $violation->getMessage());
        }
    }

    /**
     * Schemas the validator cannot execute honestly.
     *
     * @return  iterable<string, array{array<string, mixed>, string}>  Schema and rule fragment.
     *
     * @since   2.0.0
     */
    public static function unsupportedSchemas(): iterable
    {
        yield 'unknown keyword' => [['type' => 'object', 'patternProperties' => []], 'keyword "patternProperties"'];
        yield 'nested unknown keyword' => [['properties' => ['a' => ['format' => 'date']]], 'keyword "format"'];
        yield 'external reference' => [['$ref' => 'other.json#/x'], '#/$defs/<name>'];
        yield 'undefined definition' => [['$ref' => '#/$defs/missing'], 'undefined $defs entry'];
        yield 'unknown type' => [['type' => 'date'], 'unknown type'];
        yield 'invalid pattern' => [['type' => 'string', 'pattern' => '(['], 'not a valid expression'];
    }

    /**
     * A missing schema file is a violation, not a passing validation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAMissingSchemaFileIsRefused(): void
    {
        $this->expectException(GovernanceViolation::class);
        $this->expectExceptionMessage('missing');

        (new SchemaValidator())->validate([], sys_get_temp_dir() . '/kumwe-absent-schema.json');
    }

    /**
     * Every shipped governance schema loads under the validator's subset.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEveryShippedSchemaLoads(): void
    {
        $schemas = glob(GovernanceFixture::schemaDirectory() . '/*.schema.json') ?: [];

        self::assertCount(17, $schemas);
        foreach ($schemas as $schema) {
            $decoded = SchemaValidator::loadSchema($schema);
            self::assertSame('https://json-schema.org/draft/2020-12/schema', $decoded['$schema'], $schema);
            self::assertSame(
                'https://kumwe.dev/schemas/' . basename($schema, '.schema.json'),
                $decoded['$id'],
                $schema,
            );
        }
    }

    /**
     * Every documented example validates against the schema it is named after.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEveryExampleValidatesAgainstItsSchema(): void
    {
        $examples = glob(GovernanceFixture::repositoryRoot() . '/docs/architecture/governance/examples/*') ?: [];
        $validator = new SchemaValidator();

        self::assertCount(16, $examples);
        foreach ($examples as $example) {
            $schema = GovernanceFixture::schemaDirectory() . '/'
                . preg_replace('/\.example\.(yaml|json|md)$/', '.schema.json', basename($example));
            $bytes = file_get_contents($example);
            self::assertIsString($bytes, $example);
            $document = match (pathinfo($example, PATHINFO_EXTENSION)) {
                'json' => json_decode($bytes, true, 512, JSON_THROW_ON_ERROR),
                'yaml' => StrictYaml::parse($bytes, $example),
                default => StrictYaml::parseFrontMatter($bytes, $example)['front_matter'],
            };
            self::assertIsArray($document, $example);
            self::assertSame([], $validator->validate($document, $schema), $example);
        }
    }

    /**
     * Write a scratch schema file.
     *
     * @param   array<string, mixed>  $schema  Schema document.
     *
     * @return  string  Absolute path.
     *
     * @since   2.0.0
     */
    private function schema(array $schema): string
    {
        $path = sys_get_temp_dir() . '/kumwe-schema-' . bin2hex(random_bytes(8)) . '.json';
        self::assertNotFalse(file_put_contents($path, json_encode($schema, JSON_THROW_ON_ERROR)));
        $this->temporary[] = $path;

        return $path;
    }
}
