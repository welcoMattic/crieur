<?php

declare(strict_types=1);

namespace App\Controller;

use App\Library\FeedToken;
use App\Setup\SetupInspector;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The setup page: voice engine and speech editor, AI assistants over MCP, the bookmarklet and
 * the private podcast feed.
 */
final class SettingsController extends AbstractController
{
    #[Route('/settings', name: 'settings', methods: ['GET'])]
    public function index(
        SetupInspector $inspector,
        FeedToken $feedToken,
        #[Autowire(param: 'kernel.project_dir')]
        string $projectDir,
    ): Response {
        return $this->render('settings/index.html.twig', [
            'report' => $inspector->inspect(),
            'feed_token' => $feedToken->value(),
            'console' => $projectDir.'/bin/console',
        ]);
    }
}
