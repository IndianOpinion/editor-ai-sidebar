<?php

declare(strict_types=1);

namespace IndianOpinion\EditorAiSidebar\Tests\Unit;

use IndianOpinion\EditorAiSidebar\Canonical;
use PHPUnit\Framework\TestCase;

/**
 * The canonical JSON rule must match the sidebar script byte for byte.
 * The vector file is shared with tests/js/core.test.js.
 */
final class CanonicalTest extends TestCase
{
    public function testSharedVectors(): void
    {
        $vectors = json_decode((string) file_get_contents(dirname(__DIR__) . '/fixtures/hash-vectors.json'), true);
        self::assertIsArray($vectors);
        self::assertGreaterThan(10, count($vectors));
        foreach ($vectors as $vector) {
            self::assertSame($vector['canonical'], Canonical::encode($vector['input']));
            self::assertSame($vector['sha256'], hash('sha256', Canonical::encode($vector['input'])));
        }
    }

    public function testKeyOrderDoesNotMatter(): void
    {
        self::assertSame(
            Canonical::contextHash(['title' => 'a', 'text' => 'b']),
            Canonical::contextHash(['text' => 'b', 'title' => 'a'])
        );
        self::assertSame('{"a":1}', Canonical::encode(['a' => 1.0]));
        self::assertSame('{}', Canonical::encode([]));
    }

    public function testItemHashCoversTypeAndValue(): void
    {
        self::assertNotSame(Canonical::itemHash('text', 'x'), Canonical::itemHash('list', ['x']));
        self::assertSame(
            Canonical::itemHash('fields', ['a' => '1', 'b' => '2']),
            Canonical::itemHash('fields', ['b' => '2', 'a' => '1'])
        );
    }

    public function testInvalidUtf8Throws(): void
    {
        $this->expectException(\JsonException::class);
        Canonical::encode(['text' => "\xff"]);
    }
}
