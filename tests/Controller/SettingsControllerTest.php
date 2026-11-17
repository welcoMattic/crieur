<?php

declare(strict_types=1);

namespace App\Tests\Controller;

final class SettingsControllerTest extends ControllerTestCase
{
    private const string SECRET = 'sk-crieur-sentinel-0123456789';

    /**
     * @var array<string, mixed>
     */
    private array $saved = [];

    protected function setUp(): void
    {
        // A real-looking key, an Ollama that refuses connections at once, the editor chosen from the key.
        foreach (['MISTRAL_API_KEY' => self::SECRET, 'OLLAMA_HOST_URL' => 'http://127.0.0.1:9', 'CRIEUR_EDITOR' => 'auto'] as $name => $value) {
            $this->saved[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null];
            $_ENV[$name] = $_SERVER[$name] = $value;
        }

        parent::setUp();
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => [$env, $server]) {
            $_ENV[$name] = $env;
            $_SERVER[$name] = $server;
        }

        parent::tearDown();
    }

    public function testTheSettingsExplainTheSetupWithoutShowingTheKey(): void
    {
        $this->client->request('GET', '/settings');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString(self::SECRET, $content);
        self::assertStringNotContainsString('sk-crieur', $content);
        self::assertSelectorTextContains('#voice', 'Set (never shown)');
        self::assertSelectorTextContains('#voice', 'Not reachable at http://127.0.0.1:9');
        self::assertSelectorTextContains('#voice', 'mistral-small-latest');
        self::assertSelectorTextContains('#mcp', 'claude mcp add --transport http crieur http://localhost/mcp');
        self::assertSelectorTextContains('#mcp', 'mcp:server');
        self::assertSelectorExists('#bookmarklet a.bookmarklet[href^="javascript:"]');
        self::assertSelectorTextContains('#podcast', 'http://localhost/feed/');
    }
}
