<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

final class FunctionalTestIsolationTest extends TestCase
{
    public function testEveryFunctionalTestClassResetsTheDatabase(): void
    {
        $missing = [];
        foreach (new Finder()->files()->in(\dirname(__DIR__).'/Functional')->name('*Test.php') as $file) {
            $source = $file->getContents();
            if (1 === preg_match('/\bextends\s+\S*(?:KernelTestCase|WebTestCase|ApiTestCase)\b/', $source)
                && !str_contains($source, 'use ResetDatabase;')) {
                $missing[] = $file->getRelativePathname();
            }
        }

        self::assertSame([], $missing, 'A functional test without ResetDatabase that runs first leaves a DAMA transaction open, and the next ResetDatabase test then hangs on DROP DATABASE.');
    }
}
