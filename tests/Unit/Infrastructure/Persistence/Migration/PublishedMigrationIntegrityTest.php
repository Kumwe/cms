<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Infrastructure\Persistence\Migration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class PublishedMigrationIntegrityTest extends TestCase
{
    public function testPublishedMigrationSourceRemainsByteForByteImmutable(): void
    {
        $root = dirname(__DIR__, 5);
        $published = [
            'ApplicationAuthorizationMigration.php' =>
                '687bccd141376133117c53468185d84cefebab11418a8506f0b2df43e7513ef9',
            'AuthorizationRecoveryIntegrationMigration.php' =>
                '60c2d1cd888c89bffb49456b77851d13e08d520d57bb7851c9640443b5910f43',
            'CoreSchemaMigration.php' =>
                '6e2b6ab55e5f0eae4f979e9e49090ae6fcbd061ea3ed3ba8caac20e42d02c021',
            'JobRecoveryMigration.php' =>
                '23d02dcd543e35ef72849dae327bccda2115ddaaa3f6298c8449123174e5b92f',
        ];

        foreach ($published as $file => $expected) {
            self::assertSame(
                $expected,
                hash_file('sha256', $root . '/src/Infrastructure/Persistence/Migration/' . $file),
                sprintf('Published migration %s changed; add a forward migration instead.', $file),
            );
        }
    }
}
