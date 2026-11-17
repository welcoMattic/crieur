<?php

declare(strict_types=1);

namespace App\Controller;

use App\Enum\ArticleSource;
use App\Library\ArticleQueue;
use App\Library\InvalidArticleUrl;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The entry point of the bookmarklet and of the share sheet of the installed web app.
 *
 * A GET that queues an article is a change of state, so it is accepted only as a top-level
 * navigation of the browser (Fetch Metadata: Sec-Fetch-Mode "navigate", Sec-Fetch-Dest "document"),
 * which is what a bookmarklet or a share sheet does. An image, a script, a fetch() or a frame from
 * another site gets a confirmation page instead, whose button is a CSRF-protected POST, and that
 * page refuses to be framed. At worst another site can send the listener here with a link they
 * then see in their library: no data leaves Crieur, and pages on private networks are refused.
 */
final class ShareController extends AbstractController
{
    #[Route('/share', name: 'share', methods: ['GET', 'POST'])]
    public function share(Request $request, ArticleQueue $queue): Response
    {
        $url = self::sharedUrl($request);
        $confirmed = $request->isMethod('POST');

        if ($confirmed && !$this->isCsrfTokenValid(ArticleController::CSRF_TOKEN_ID, $request->getPayload()->getString('_token'))) {
            return $this->confirmation($url, 'share.expired', Response::HTTP_FORBIDDEN);
        }

        if ($confirmed || self::isTopLevelNavigation($request)) {
            try {
                $article = $queue->queue($url, ArticleSource::Bookmarklet);
            } catch (InvalidArticleUrl $exception) {
                return $this->confirmation($url, 'url.'.$exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return $this->redirectToRoute('article_show', ['id' => $article->getId(), 'play' => 1], Response::HTTP_SEE_OTHER);
        }

        return $this->confirmation($url);
    }

    private function confirmation(string $url, ?string $error = null, int $status = Response::HTTP_OK): Response
    {
        $response = $this->render('share/confirm.html.twig', ['url' => $url, 'error' => $error], new Response(status: $status));
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Content-Security-Policy', "frame-ancestors 'none'");

        return $response;
    }

    private static function isTopLevelNavigation(Request $request): bool
    {
        return 'navigate' === $request->headers->get('Sec-Fetch-Mode')
            && 'document' === $request->headers->get('Sec-Fetch-Dest');
    }

    /**
     * The "url" parameter, else the first web address of "text": share sheets on phones often put
     * the link in the text.
     */
    private static function sharedUrl(Request $request): string
    {
        $parameters = $request->isMethod('POST') ? $request->getPayload() : $request->query;
        $url = trim($parameters->getString('url'));
        if ('' === $url && 1 === preg_match('~https?://\S+~i', $parameters->getString('text'), $match)) {
            $url = $match[0];
        }

        return $url;
    }
}
