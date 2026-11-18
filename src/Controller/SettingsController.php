<?php

declare(strict_types=1);

namespace App\Controller;

use App\Library\FeedToken;
use App\Settings\EngineSettings;
use App\Setup\SetupInspector;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The setup page: voice engine and speech editor, AI assistants over MCP, the browser extension,
 * the bookmarklet and the private podcast feed.
 */
final class SettingsController extends AbstractController
{
    #[Route('/settings', name: 'settings', methods: ['GET'])]
    public function index(
        SetupInspector $inspector,
        FeedToken $feedToken,
        #[Autowire(param: 'kernel.project_dir')]
        string $projectDir,
        #[Autowire(env: 'CRIEUR_API_TOKEN')]
        string $apiToken,
    ): Response {
        return $this->render('settings/index.html.twig', [
            'report' => $inspector->inspect(),
            'feed_token' => $feedToken->value(),
            'console' => $projectDir.'/bin/console',
            'api_token_set' => '' !== trim($apiToken),
        ]);
    }

    /**
     * Chooses the voice engine and the speech editor. The workers read them at each article:
     * the change applies to the next articles and voice changes, without a restart.
     */
    #[Route('/settings/engines', name: 'settings_engines', methods: ['POST'])]
    public function engines(Request $request, EngineSettings $settings): Response
    {
        if (!$this->isCsrfTokenValid('submit', $request->getPayload()->getString('_token'))) {
            throw new AccessDeniedHttpException('The form has expired or comes from another site: reload the page and try again.');
        }

        try {
            if ($request->getPayload()->has('voice_engine')) {
                $settings->changeVoiceEngine($request->getPayload()->getString('voice_engine'));
            }
            if ($request->getPayload()->has('editor')) {
                $settings->changeEditor($request->getPayload()->getString('editor'));
            }
        } catch (\InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage(), $e);
        }

        $this->addFlash('success', 'settings.engines.saved');

        return $this->redirect($this->generateUrl('settings').'#voice', Response::HTTP_SEE_OTHER);
    }
}
