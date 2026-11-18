<?php

declare(strict_types=1);

namespace App\Tests\Editor;

use App\Editor\EditorUnavailable;
use App\Editor\SpeechEditor;
use App\Text\Language;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Platform\StructuredOutput\PlatformSubscriber;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class SpeechEditorTest extends TestCase
{
    #[Test]
    #[DataProvider('backends')]
    public function itPicksTheBackendFromTheSettings(string $mode, string $key, ?string $expected): void
    {
        static::assertSame($expected, $this->editor('{}', $mode, $key)->backend());
    }

    /**
     * @return iterable<string, array{string, string, ?string}>
     */
    public static function backends(): iterable
    {
        yield 'auto with a Mistral key' => ['auto', 'key', 'mistral'];
        yield 'auto without key' => ['auto', '', null];
        yield 'ollama' => ['ollama', '', 'ollama'];
        yield 'off' => ['off', 'key', null];
    }

    #[Test]
    public function itKeepsTheFaithfulRewritesOnly(): void
    {
        $answer = json_encode(['chunks' => [
            ['id' => 1, 'spoken' => 'Le 17 novembre 2026, les prix ont augmenté de 3,5 %.'],
            ['id' => 2, 'spoken' => 'Résumé.'],
            ['id' => 3, 'spoken' => ''],
        ], 'summary' => 'Les prix montent.'], \JSON_THROW_ON_ERROR);

        $edited = $this->editor($answer)->edit(Language::French, 'Prix', [
            1 => 'Le 17/11/2026, les prix ont augmenté de 3,5 %.',
            2 => 'Une longue phrase de plus de quarante caractères que le modèle a voulu résumer en un mot.',
            3 => 'Lire aussi',
        ], true);

        static::assertSame([
            1 => 'Le 17 novembre 2026, les prix ont augmenté de 3,5 %.',
            3 => '',
        ], $edited->spoken);
        static::assertSame('Les prix montent.', $edited->summary);
    }

    #[Test]
    public function itKeepsTheRulesWhenMostChunksAreUnfaithful(): void
    {
        $answer = json_encode(['chunks' => [['id' => 1, 'spoken' => 'Bref.']], 'summary' => null], \JSON_THROW_ON_ERROR);

        $edited = $this->editor($answer)->edit(Language::English, 'Title', [
            1 => 'A sentence that is long enough to be summarized by a careless model.',
            2 => 'Another sentence that the model forgot to answer, which is not acceptable.',
        ], false);

        static::assertSame([], $edited->spoken);
    }

    #[Test]
    public function itReportsAnUnreachableModel(): void
    {
        $this->expectException(EditorUnavailable::class);

        $this->editor(static fn () => throw new \RuntimeException('Connection refused'))->edit(Language::English, 'Title', [1 => 'Hello.'], false);
    }

    #[Test]
    #[DataProvider('faithfulness')]
    public function itTellsARewriteFromASummary(string $original, string $spoken, bool $faithful): void
    {
        static::assertSame($faithful, SpeechEditor::isFaithful($original, $spoken));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function faithfulness(): iterable
    {
        yield 'dates written in words, numbers kept' => ['On 11/17/2026, prices rose by 3.5%.', 'On November 17, 2026, prices rose by 3.5%.', true];
        yield 'a number spelled as the rules spell it' => ['On 11/17/2026, prices rose by 3.5%.', 'On November 17, 2026, prices rose by three point five percent.', true];
        yield 'a number spelled wrong' => ['On 11/17/2026, prices rose by 3.5%.', 'On November 17, 2026, prices rose by thirty-five percent.', false];
        yield 'an ordinal spelled right' => ['Criers dressed by a tradition dating to the 18th century.', 'Criers dressed by a tradition dating to the eighteenth century.', true];
        yield 'an ordinal misread' => ['Criers dressed by a tradition dating to the 18th century.', 'Criers dressed by a tradition dating to the eighteen hundredth century.', false];
        yield 'a version in words' => ['PHP 8.5 runs at 120 km/h.', 'PHP eight point five runs at 120 kilometers per hour.', true];
        yield 'a time in words' => ['The crier rang at 10:30 sharp.', 'The crier rang at half past ten sharp.', true];
        yield 'short leftover dropped' => ['Read more', '', true];
        yield 'long paragraph dropped' => [str_repeat('A real sentence. ', 10), '', false];
        yield 'summarized' => [str_repeat('A real sentence with content. ', 6), 'It is about content.', false];
        yield 'padded with invented content' => ['Prices rose.', str_repeat('Prices rose because of many reasons. ', 5), false];
    }

    private function editor(\Closure|string $answer, string $mode = 'mistral', string $key = 'key'): SpeechEditor
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new PlatformSubscriber());
        $agent = new Agent(MockPlatformFactory::createPlatform($answer, eventDispatcher: $dispatcher), 'mock-model');

        return new SpeechEditor(
            new ServiceLocator(['mistral' => static fn () => $agent, 'ollama' => static fn () => $agent]),
            $mode,
            $key,
            new NullLogger(),
        );
    }

    #[Test]
    public function aContinuedSentenceStaysInLowerCase(): void
    {
        $answer = json_encode(['chunks' => [['id' => 2, 'spoken' => 'Is an officer of a royal court or public authority.']], 'summary' => null], \JSON_THROW_ON_ERROR);

        $edited = $this->editor($answer)->edit(Language::English, 'Town crier', [2 => 'is an officer of a royal court or public authority.'], false);

        static::assertSame([2 => 'is an officer of a royal court or public authority.'], $edited->spoken);
    }
}
