<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Guards the one rule that keeps the attribute support optional now that it ships in the same package as the
 * engine: the dependency only ever points *inwards*. `Neos\Schematic\Attributes` may use the engine; nothing in
 * the engine (nor in the engine's own tests, which prove it works standalone) may name the attribute namespace.
 *
 * See ADR 0008.
 */
final class AttributesAreDecoupledTest extends TestCase
{
    private const ATTRIBUTES_NAMESPACE = 'Neos\\Schematic\\Attributes';

    public function testEngineSourcesDoNotReferenceTheAttributesNamespace(): void
    {
        self::assertSame([], $this->offendingFiles(__DIR__ . '/../../src', 'src/Attributes'));
    }

    public function testEngineTestsDoNotReferenceTheAttributesNamespace(): void
    {
        self::assertSame([], $this->offendingFiles(__DIR__ . '/../../tests', 'tests/Attributes'));
    }

    /**
     * @param string $excludedPath the (relative) directory that is allowed to reference the namespace
     * @return list<string> the relative paths of files that reference the attributes namespace anyway
     */
    private function offendingFiles(string $directory, string $excludedPath): array
    {
        $root = realpath(__DIR__ . '/../..');
        self::assertIsString($root);
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        $offenders = [];
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $realPath = (string) $file->getRealPath();
            if ($realPath === __FILE__) {
                continue;
            }
            $path = str_replace($root . '/', '', $realPath);
            if (str_starts_with($path, $excludedPath . '/')) {
                continue;
            }
            $contents = file_get_contents((string) $file->getRealPath());
            if (is_string($contents) && str_contains($contents, self::ATTRIBUTES_NAMESPACE)) {
                $offenders[] = $path;
            }
        }
        sort($offenders);
        return $offenders;
    }
}
