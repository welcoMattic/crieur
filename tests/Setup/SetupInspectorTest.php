<?php

declare(strict_types=1);

namespace App\Tests\Setup;

use App\Setup\SetupInspector;
use App\Speech\VoiceCatalog;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SetupInspectorTest extends TestCase
{
    private const string KEY = 'sk-test-never-shown';

    public function testEverythingLocalIsReady(): void
    {
        $report = $this->inspector(['phonon:40m-multilingual', 'ministral-3:8b'], key: '', editor: 'ollama')->inspect();

        self::assertTrue($report->ollamaReachable);
        self::assertSame('phonon', $report->activeEngine()?->name);
        self::assertTrue($report->activeEngine()->ready);
        self::assertSame('ministral-3:8b', $report->editor->model);
        self::assertTrue($report->editor->ready);
        self::assertTrue($report->isReady());
        self::assertSame('setup.issue.key_missing', $report->engines[1]->issue);
    }

    public function testOllamaDown(): void
    {
        $report = $this->inspector(null, key: '', editor: 'ollama')->inspect();

        self::assertFalse($report->ollamaReachable);
        self::assertSame('setup.issue.ollama_unreachable', $report->activeEngine()?->issue);
        self::assertSame('setup.issue.ollama_unreachable', $report->editor->issue);
        self::assertFalse($report->isReady());
    }

    public function testAModelNotPulled(): void
    {
        $report = $this->inspector(['llama3:latest'], key: '', editor: 'off')->inspect();

        self::assertSame('setup.issue.model_missing', $report->activeEngine()?->issue);
        self::assertNull($report->editor->model);
        self::assertTrue($report->editor->ready);
    }

    public function testAutoUsesMistralWhenTheKeyIsSet(): void
    {
        $withKey = $this->inspector(['phonon:40m-multilingual'], key: self::KEY, editor: 'auto')->inspect();
        self::assertSame('mistral-small-latest', $withKey->editor->model);
        self::assertTrue($withKey->mistralKeySet);
        self::assertTrue($withKey->engines[1]->ready);

        $withoutKey = $this->inspector(['phonon:40m-multilingual'], key: '', editor: 'auto')->inspect();
        self::assertSame('rules', $withoutKey->editor->name);
        self::assertFalse($withoutKey->mistralKeySet);
    }

    public function testTheReportNeverHoldsTheKey(): void
    {
        $report = $this->inspector(['phonon:40m-multilingual'], key: self::KEY, editor: 'mistral', ollamaUrl: 'http://user:password@127.0.0.1:11434')->inspect();

        self::assertStringNotContainsString(self::KEY, serialize($report));
        self::assertStringNotContainsString('password', serialize($report));
        self::assertSame('http://127.0.0.1:11434', $report->ollamaUrl);
    }

    /**
     * @param ?list<string> $models the models pulled in Ollama, null when it does not answer
     */
    private function inspector(?array $models, string $key, string $editor, string $ollamaUrl = 'http://127.0.0.1:11434'): SetupInspector
    {
        $http = new MockHttpClient(static function (string $method, string $url) use ($models): MockResponse {
            self::assertSame('GET', $method);
            self::assertStringEndsWith('/api/tags', $url);
            if (null === $models) {
                throw new TransportException('Connection refused.');
            }

            return new MockResponse(json_encode(['models' => array_map(static fn (string $name): array => ['name' => $name], $models)], \JSON_THROW_ON_ERROR));
        });

        $voices = new VoiceCatalog([
            'phonon' => ['label' => 'Phonon', 'model' => 'phonon:40m-multilingual', 'license' => 'CC BY-NC 4.0', 'local' => true, 'voices' => []],
            'voxtral' => ['label' => 'Voxtral', 'model' => 'voxtral-mini-tts-latest', 'license' => 'Mistral AI', 'local' => false, 'voices' => []],
        ], 'phonon');

        return new SetupInspector($http, $voices, $ollamaUrl, $key, $editor);
    }
}
