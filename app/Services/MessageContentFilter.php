<?php

namespace App\Services;

class MessageContentFilter
{
    /**
     * Inspect one or more pieces of message text.
     *
     * This filter is intentionally server-side so it cannot be bypassed by
     * calling the API directly. It normalizes common obfuscation first, then
     * checks profanity, sexual content, threats, harassment, self-harm abuse,
     * and discriminatory/hateful terms.
     *
     * @param  string|null  ...$values
     * @return array{allowed: bool, category: string|null}
     */
    public function inspect(
        ?string ...$values,
    ): array {
        $text =
            trim(
                implode(
                    ' ',
                    array_filter(
                        array_map(
                            static fn ($value) =>
                                trim((string) $value),
                            $values,
                        ),
                        static fn ($value) =>
                            $value !== '',
                    ),
                ),
            );

        if ($text === '') {
            return [
                'allowed' => true,
                'category' => null,
            ];
        }

        $normalized =
            $this->normalize(
                $text,
            );

        foreach (
            $this->patterns() as
            $category => $patterns
        ) {
            foreach ($patterns as $pattern) {
                if (
                    preg_match(
                        $pattern,
                        $normalized,
                    ) === 1
                ) {
                    return [
                        'allowed' => false,
                        'category' => $category,
                    ];
                }
            }
        }

        return [
            'allowed' => true,
            'category' => null,
        ];
    }

    /**
     * Normalize common attempts to disguise objectionable words while keeping
     * enough word boundaries to avoid broad substring false positives.
     */
    private function normalize(
        string $text,
    ): string {
        $text =
            html_entity_decode(
                strip_tags(
                    $text,
                ),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8',
            );

        if (class_exists(\Normalizer::class)) {
            $normalizedUnicode =
                \Normalizer::normalize(
                    $text,
                    \Normalizer::FORM_KC,
                );

            if (is_string($normalizedUnicode)) {
                $text =
                    $normalizedUnicode;
            }
        }

        $text =
            strtr(
                $text,
                [
                    '’' => "'",
                    '‘' => "'",
                    '“' => '"',
                    '”' => '"',
                ],
            );

        $text =
            function_exists('mb_strtolower')
                ? mb_strtolower(
                    $text,
                    'UTF-8',
                )
                : strtolower(
                    $text,
                );

        /*
         * Common leetspeak substitutions. These happen before punctuation is
         * removed so simple evasions such as k1ll, sh1t, b!tch, or @sshole
         * normalize into their ordinary forms.
         */
        $text =
            strtr(
                $text,
                [
                    '0' => 'o',
                    '1' => 'i',
                    '2' => 'z',
                    '3' => 'e',
                    '4' => 'a',
                    '5' => 's',
                    '6' => 'g',
                    '7' => 't',
                    '8' => 'b',
                    '9' => 'g',
                    '@' => 'a',
                    '$' => 's',
                    '!' => 'i',
                ],
            );

        /*
         * Normalize common contractions and chat shorthand so phrase-level
         * abuse/threat checks are consistent.
         */
        $text =
            preg_replace(
                [
                    '/\bi\s*\'\s*ll\b/u',
                    '/\bi\s*\'\s*m\b/u',
                    '/\bwe\s*\'\s*ll\b/u',
                    '/\byou\s*\'\s*re\b/u',
                    '/\byou\s*\'\s*ll\b/u',
                    '/\bcan\s*\'\s*t\b/u',
                    '/\bwon\s*\'\s*t\b/u',
                ],
                [
                    'i will',
                    'i am',
                    'we will',
                    'you are',
                    'you will',
                    'cannot',
                    'will not',
                ],
                $text,
            ) ?? $text;

        $text =
            preg_replace(
                '/[^\p{L}\p{N}\s._*~\-\']+/u',
                ' ',
                $text,
            ) ?? $text;

        return
            preg_replace(
                '/\s+/u',
                ' ',
                trim(
                    $text,
                ),
            ) ?? trim($text);
    }

    /**
     * Build moderation patterns by category.
     *
     * Apple does not publish an exhaustive banned-word list, so these rules
     * focus on high-confidence objectionable content and abusive behavior while
     * avoiding overly broad matches that would block normal school messages.
     *
     * @return array<string, array<int, string>>
     */
    private function patterns(): array
    {
        return [
            'profanity' =>
                array_merge(
                    $this->termPatterns([
                        'fuck',
                        'fucks',
                        'fucked',
                        'fucker',
                        'fuckers',
                        'fucking',
                        'motherfucker',
                        'motherfuckers',
                        'motherfucking',
                        'shit',
                        'shits',
                        'shitty',
                        'bullshit',
                        'bitch',
                        'bitches',
                        'bitching',
                        'asshole',
                        'assholes',
                        'dumbass',
                        'jackass',
                        'cunt',
                        'cunts',
                        'bastard',
                        'bastards',
                        'putangina',
                        'tangina',
                        'puta',
                        'gago',
                        'gaga',
                        'ulol',
                    ]),
                    [
                        '/\bputang\s+ina\b/ui',
                        '/\bpunyeta\b/ui',
                    ],
                ),

            'sexual_content' =>
                array_merge(
                    $this->termPatterns([
                        'porn',
                        'porno',
                        'pornography',
                        'blowjob',
                        'handjob',
                        'nudes',
                        'sext',
                        'sexting',
                        'masturbate',
                        'masturbation',
                    ]),
                    [
                        '/\bsend\s+(?:me\s+)?nudes?\b/ui',
                        '/\bshow\s+(?:me\s+)?(?:your\s+)?(?:boobs?|breasts?|genitals?)\b/ui',
                        '/\b(?:nude|naked)\s+(?:photo|picture|video|pic)\b/ui',
                        '/\b(?:sex|sexual)\s+(?:video|photo|picture|content|chat)\b/ui',
                        '/\b(?:want|wanna|want\s+to)\s+(?:have\s+)?sex\b/ui',
                    ],
                ),

            'threats_or_violence' => [
                /* First-person threats: target may contain a typo, e.g. "I will kill gyou". */
                '/\b(?:i|we)\s+(?:will|am\s+going\s+to|are\s+going\s+to|gonna|plan\s+to|want\s+to)\s+(?:kill|murder|shoot|stab|hurt|beat|attack|strangle)\b/ui',
                '/\bi(?:ll)?\s+(?:kill|murder|shoot|stab|hurt|beat|attack|strangle)\b/ui',

                /* Direct threats. */
                '/\b(?:kill|murder|shoot|stab|strangle)\s+(?:you|u|him|her|them)\b/ui',
                '/\bbeat\s+(?:you|u|him|her|them)\s+up\b/ui',
                '/\byou\s+(?:will|are\s+going\s+to|gonna)\s+die\b/ui',
                '/\byou\s+are\s+dead\b/ui',
                '/\bwatch\s+your\s+back\b/ui',
                '/\byou\s+better\s+watch\s+your\s+back\b/ui',
                '/\bi\s+know\s+where\s+you\s+live\b/ui',
                '/\bi\s+will\s+find\s+you\b/ui',
            ],

            'harassment_or_abuse' =>
                array_merge(
                    $this->termPatterns([
                        'retard',
                        'retarded',
                        'tanga',
                        'bobo',
                    ]),
                    [
                        '/\b(?:you\s+are|youre|ur)\s+(?:an?\s+)?(?:stupid|idiot|moron|worthless|useless|disgusting|pathetic|ugly|loser)\b/ui',
                        '/\b(?:stupid|idiot|moron|worthless|useless|pathetic|loser)\s+(?:you|boy|girl|student)\b/ui',
                        '/\bhayop\s+ka\b/ui',
                        '/\bwalan(?:g)?\s+hiya\b/ui',
                        '/\b(?:go\s+die|kill\s+yourself|go\s+kill\s+yourself|kys)\b/ui',
                    ],
                ),

            'hate_or_discriminatory_content' =>
                array_merge(
                    $this->termPatterns([
                        'nigger',
                        'nigga',
                        'faggot',
                        'tranny',
                    ]),
                    [
                        '/\bching\s+chong\b/ui',
                    ],
                ),
        ];
    }

    /**
     * Convert blocked terms into Unicode-aware expressions that tolerate
     * spaces, punctuation, leetspeak (after normalization), and repeated
     * letters while preserving word boundaries.
     *
     * Examples caught:
     * - f.u.c.k
     * - f u c k
     * - fuuuck
     * - b!tch -> normalized to bitch
     *
     * @param  array<int, string>  $terms
     * @return array<int, string>
     */
    private function termPatterns(
        array $terms,
    ): array {
        return
            array_map(
                function (string $term) {
                    $characters =
                        preg_split(
                            '//u',
                            $term,
                            -1,
                            PREG_SPLIT_NO_EMPTY,
                        ) ?: [];

                    $runs = [];

                    foreach ($characters as $character) {
                        $lastIndex =
                            count($runs) - 1;

                        if (
                            $lastIndex >= 0 &&
                            $runs[$lastIndex]['character'] === $character
                        ) {
                            $runs[$lastIndex]['count']++;
                            continue;
                        }

                        $runs[] = [
                            'character' => $character,
                            'count' => 1,
                        ];
                    }

                    $runPatterns =
                        array_map(
                            static function (array $run) {
                                $escaped =
                                    preg_quote(
                                        $run['character'],
                                        '/',
                                    );

                                $minimum =
                                    (int) $run['count'];

                                $maximum =
                                    $minimum + 5;

                                return
                                    '(?:'
                                    . $escaped
                                    . '){'
                                    . $minimum
                                    . ','
                                    . $maximum
                                    . '}';
                            },
                            $runs,
                        );

                    return
                        '/(?<![\p{L}\p{N}])'
                        . implode(
                            '[\s._*~\-]*',
                            $runPatterns,
                        )
                        . '(?![\p{L}\p{N}])/ui';
                },
                $terms,
            );
    }
}