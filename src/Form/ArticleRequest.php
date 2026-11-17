<?php

declare(strict_types=1);

namespace App\Form;

use App\Enum\LanguageSetting;
use App\Enum\VoiceGender;
use App\Library\ArticleQueue;
use App\Library\InvalidArticleUrl;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * What the listener asks for on the home page: a link, and how to read it.
 */
final class ArticleRequest
{
    public ?string $url = null;

    public LanguageSetting $language = LanguageSetting::Auto;

    public VoiceGender $voice = VoiceGender::Female;

    /**
     * The queue owns the rules of a readable address: the form reports its reason codes
     * ("empty", "too_long", "scheme", "host") with the messages of the "errors" domain.
     */
    #[Assert\Callback]
    public function validateUrl(ExecutionContextInterface $context): void
    {
        try {
            ArticleQueue::normalizeUrl($this->url ?? '');
        } catch (InvalidArticleUrl $exception) {
            $context->buildViolation('url.'.$exception->getMessage())
                ->setTranslationDomain('errors')
                ->atPath('url')
                ->addViolation();
        }
    }
}
