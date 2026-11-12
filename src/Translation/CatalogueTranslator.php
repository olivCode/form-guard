<?php

declare(strict_types=1);

namespace FormGuard\Translation;

final class CatalogueTranslator implements TranslatorInterface
{
    /** @var array<string, array<string, string>> */
    private array $catalogues;

    /** @param array<string, array<string, string>> $messages */
    public function __construct(array $messages = [], private readonly string $fallbackLocale = 'en')
    {
        $this->catalogues = [
            'en' => [
                'form_unavailable' => 'The form is unavailable or has already been submitted. Please reload it.',
                'form_expired' => 'The form has expired. Please reload it and try again.',
                'invalid_token' => 'The form could not be verified. Please reload it.',
                'honeypot' => 'The submission could not be verified. Please try again.',
                'too_fast' => 'Please wait another {seconds} s before submitting the form.',
                'invalid_signals' => 'The submission could not be verified. Please try again.',
                'behavior_rejected' => 'The submission could not be verified. Please try again.',
                'rate_limited' => 'Too many attempts. Try again in {retry_after} s.',
                'custom_rule' => 'The submission could not be verified. Please try again.',
            ],
            'fr' => [
                'form_unavailable' => 'Le formulaire est indisponible ou a déjà été envoyé. Merci de le recharger.',
                'form_expired' => 'Le formulaire a expiré. Merci de le recharger et de réessayer.',
                'invalid_token' => 'Le formulaire n’a pas pu être vérifié. Merci de le recharger.',
                'honeypot' => 'La demande n’a pas pu être vérifiée. Merci de réessayer.',
                'too_fast' => 'Merci de patienter encore {seconds} s avant d’envoyer votre demande.',
                'invalid_signals' => 'La demande n’a pas pu être vérifiée. Merci de réessayer.',
                'behavior_rejected' => 'La demande n’a pas pu être vérifiée. Merci de réessayer.',
                'rate_limited' => 'Trop de tentatives. Réessayez dans {retry_after} s.',
                'custom_rule' => 'La demande n’a pas pu être vérifiée. Merci de réessayer.',
            ],
        ];
        foreach ($messages as $locale => $catalogue) {
            $locale = self::normalize($locale);
            $this->catalogues[$locale] = array_replace($this->catalogues[$locale] ?? [], $catalogue);
        }
    }

    public static function normalize(string $locale): string
    {
        return strtolower(str_replace('_', '-', $locale));
    }

    /** @param array<string, int|string> $parameters */
    public function translate(string $key, array $parameters, string $locale): string
    {
        $requested = self::normalize($locale);
        $fallback = self::normalize($this->fallbackLocale);
        $candidates = array_unique([$requested, explode('-', $requested)[0], $fallback, explode('-', $fallback)[0], 'en']);
        $message = $key;
        foreach ($candidates as $candidate) {
            if (isset($this->catalogues[$candidate][$key])) {
                $message = $this->catalogues[$candidate][$key];
                break;
            }
        }
        $replace = [];
        foreach ($parameters as $name => $value) {
            $replace['{' . $name . '}'] = (string) $value;
        }
        return strtr($message, $replace);
    }
}
