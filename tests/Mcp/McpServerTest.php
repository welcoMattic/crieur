<?php

declare(strict_types=1);

namespace App\Tests\Mcp;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class McpServerTest extends WebTestCase
{
    #[Test]
    public function itListsTheToolsOfCrieurOverHttp(): void
    {
        $client = static::createClient();
        $session = $this->initialize($client);

        $tools = $this->call($client, $session, 'tools/list');

        $names = array_column($tools['result']['tools'], 'name');
        sort($names);
        static::assertSame(['get_article', 'list_library', 'read_aloud'], $names);

        $readAloud = array_values(array_filter($tools['result']['tools'], static fn (array $tool): bool => 'read_aloud' === $tool['name']))[0];
        static::assertSame(['url'], $readAloud['inputSchema']['required']);
        static::assertSame(['auto', 'fr', 'en'], $readAloud['inputSchema']['properties']['language']['enum']);
        static::assertFalse($readAloud['annotations']['readOnlyHint']);
    }

    #[Test]
    public function itRefusesAnAddressThatIsNotAWebPage(): void
    {
        $client = static::createClient();
        $session = $this->initialize($client);

        $answer = $this->call($client, $session, 'tools/call', ['name' => 'read_aloud', 'arguments' => ['url' => 'file:///etc/passwd']]);

        static::assertTrue($answer['result']['isError']);
        static::assertStringContainsString('http and https', $answer['result']['content'][0]['text']);
    }

    private function initialize(KernelBrowser $client): string
    {
        $client->jsonRequest('POST', '/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'phpunit', 'version' => '1.0']],
        ], ['HTTP_ACCEPT' => 'application/json, text/event-stream']);

        static::assertResponseIsSuccessful();
        $session = $client->getResponse()->headers->get('Mcp-Session-Id');
        static::assertNotNull($session);

        $client->jsonRequest('POST', '/mcp', ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], ['HTTP_ACCEPT' => 'application/json, text/event-stream', 'HTTP_MCP_SESSION_ID' => $session]);

        return $session;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function call(KernelBrowser $client, string $session, string $method, array $params = []): array
    {
        $client->jsonRequest('POST', '/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => $method, 'params' => [] === $params ? new \stdClass() : $params], [
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_MCP_SESSION_ID' => $session,
        ]);

        static::assertResponseIsSuccessful();

        /** @var array<string, mixed> */
        return json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }
}
