<?php

declare(strict_types=1);

namespace App\Form;

use App\Enum\LanguageSetting;
use App\Enum\VoiceGender;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<ArticleRequest>
 */
final class ArticleType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('url', UrlType::class, [
                'label' => 'compose.url.label',
                // The queue completes "example.com/page" itself: no protocol added here.
                'default_protocol' => null,
                'required' => true,
                'attr' => [
                    'placeholder' => 'compose.url.placeholder',
                    'autocomplete' => 'off',
                    'autocapitalize' => 'off',
                    'spellcheck' => 'false',
                    'inputmode' => 'url',
                    'enterkeyhint' => 'go',
                ],
            ])
            ->add('language', EnumType::class, [
                'class' => LanguageSetting::class,
                'expanded' => true,
                'label' => 'compose.language',
                'choice_label' => static fn (LanguageSetting $language): string => 'language.short.'.$language->value,
            ])
            ->add('voice', EnumType::class, [
                'class' => VoiceGender::class,
                'expanded' => true,
                'label' => 'compose.voice',
                'choice_label' => static fn (VoiceGender $gender): string => 'voice.'.$gender->value,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ArticleRequest::class,
            'translation_domain' => 'messages',
            'attr' => ['novalidate' => 'novalidate'],
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'article';
    }
}
