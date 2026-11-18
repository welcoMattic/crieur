<?php

declare(strict_types=1);

namespace App\Tests\Extraction;

use App\Extraction\TitleCleaner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TitleCleanerTest extends TestCase
{
    #[Test]
    public function theNameOfTheSiteLeavesTheTitle(): void
    {
        static::assertSame('Crieur public', TitleCleaner::clean('Crieur public — Wikipédia', 'Wikipedia', 'https://fr.wikipedia.org/wiki/Crieur_public'));
        static::assertSame('Town crier', TitleCleaner::clean('Town crier - Wikipedia', null, 'https://en.wikipedia.org/wiki/Town_crier'));
        static::assertSame('Symfony AI 1.0.0-BETA1 + AI & MCP Hackathon', TitleCleaner::clean('Symfony AI 1.0.0-BETA1 + AI & MCP Hackathon (Symfony Blog)', 'Symfony', 'https://symfony.com/blog/x'));
        static::assertSame('Notes on PHP', TitleCleaner::clean('Notes on PHP | example.org', null, 'https://www.example.org/notes'));
    }

    #[Test]
    public function theRestOfTheTitleStays(): void
    {
        static::assertSame('Symfony 8: what changes - a review', TitleCleaner::clean('Symfony 8: what changes - a review', 'Symfony', 'https://symfony.com/blog'));
        static::assertSame('A letter to me - me', TitleCleaner::clean('A letter to me - me', null, 'https://medium.com/@someone/a'));
        static::assertSame('The PHP (Hypertext Preprocessor)', TitleCleaner::clean('The PHP (Hypertext Preprocessor)', 'Example', 'https://example.org'));
        static::assertSame('Plain title', TitleCleaner::clean('Plain title', 'Example', 'https://example.org'));
    }
}
