<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Editor\SpeechEditor;
use App\Settings\EngineSettings;
use App\Speech\VoiceCatalog;

final class SettingsControllerTest extends ControllerTestCase
{
    private const string SECRET = 'sk-crieur-sentinel-0123456789';
    private const string API_TOKEN = 'api-token-sentinel-0123456789';

    /**
     * @var array<string, mixed>
     */
    private array $saved = [];

    protected function setUp(): void
    {
        // A real-looking key, an Ollama that refuses connections at once, the editor chosen from the key.
        foreach (['MISTRAL_API_KEY' => self::SECRET, 'OLLAMA_HOST_URL' => 'http://127.0.0.1:9', 'CRIEUR_EDITOR' => 'auto', 'CRIEUR_API_TOKEN' => self::API_TOKEN] as $name => $value) {
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
        self::assertStringNotContainsString(self::API_TOKEN, $content);
        self::assertSelectorTextContains('#extension', 'http://localhost');
        self::assertSelectorTextContains('#extension', 'Set (never shown)');
        self::assertSelectorExists('#bookmarklet a.bookmarklet[href^="javascript:"]');
        self::assertSelectorTextContains('#podcast', 'http://localhost/feed/');
    }

    public function testTheVoiceEngineAndTheEditorAreChosenOnThePage(): void
    {
        $this->client->request('POST', '/settings/engines', ['voice_engine' => 'voxtral', 'editor' => 'mistral', '_token' => 'csrf-token'], server: self::sameOrigin());

        self::assertResponseRedirects('/settings#voice', 303);
        $settings = self::getContainer()->get(EngineSettings::class);
        self::assertSame('voxtral', $settings->voiceEngine());
        self::assertSame('mistral', $settings->editor());
        self::assertSame('voxtral', self::getContainer()->get(VoiceCatalog::class)->defaultEngine()->name);
        self::assertSame('mistral', self::getContainer()->get(SpeechEditor::class)->backend());

        $this->client->followRedirect();
        self::assertSelectorExists('input[name="voice_engine"][value="voxtral"][checked]');
        self::assertSelectorExists('input[name="editor"][value="mistral"][checked]');
        self::assertSelectorTextContains('.engine-form__saved', 'Saved');
    }

    public function testAnUnknownEngineIsRefused(): void
    {
        $this->client->request('POST', '/settings/engines', ['voice_engine' => 'nobody', '_token' => 'csrf-token'], server: self::sameOrigin());

        self::assertResponseStatusCodeSame(400);
        self::assertSame('phonon', self::getContainer()->get(EngineSettings::class)->voiceEngine());
    }

    public function testAnEngineThatCannotWorkIsRefused(): void
    {
        // Without a key, Voxtral and the Mistral editor would stop every reading of a shared Crieur.
        $_ENV['MISTRAL_API_KEY'] = $_SERVER['MISTRAL_API_KEY'] = '';
        self::ensureKernelShutdown();
        $this->client = static::createClient();

        $this->client->request('POST', '/settings/engines', ['voice_engine' => 'voxtral', '_token' => 'csrf-token'], server: self::sameOrigin());
        self::assertResponseStatusCodeSame(400);
        $this->client->request('POST', '/settings/engines', ['editor' => 'mistral', '_token' => 'csrf-token'], server: self::sameOrigin());
        self::assertResponseStatusCodeSame(400);

        self::assertSame('phonon', self::getContainer()->get(EngineSettings::class)->voiceEngine());
        self::assertSame('auto', self::getContainer()->get(EngineSettings::class)->editor());
    }

    public function testTheChoiceNeedsAFormOfThisSite(): void
    {
        $this->client->request('POST', '/settings/engines', ['voice_engine' => 'voxtral', '_token' => 'csrf-token'], server: self::crossSite());

        self::assertResponseStatusCodeSame(403);
        self::assertSame('phonon', self::getContainer()->get(EngineSettings::class)->voiceEngine());
    }

    public function testWithoutAChoiceTheEnvironmentDecides(): void
    {
        $settings = self::getContainer()->get(EngineSettings::class);

        self::assertSame('phonon', $settings->voiceEngine());
        self::assertSame('auto', $settings->editor());
    }
}
