<?php

namespace App\Services\Documents\Processing\Strategies;

/**
 * Robust sentence tokenizer that handles abbreviations, decimals,
 * initials, and other edge cases that naive regex splitters break on.
 *
 * Uses a placeholder-protection strategy: known non-sentence-ending
 * periods are temporarily masked before splitting, then restored.
 */
class SentenceTokenizer
{
    private const DOT_PLACEHOLDER = '{{__DOT__}}';
    private const ELLIPSIS_PLACEHOLDER = '{{__ELLIPSIS__}}';

    
    private const ABBREVIATIONS = [
        'Mr.', 'Mrs.', 'Ms.', 'Dr.', 'Prof.', 'Sr.', 'Jr.', 'St.',
        'Inc.', 'Ltd.', 'Corp.', 'Co.', 'vs.', 'etc.', 'approx.',
        'Dept.', 'Est.', 'Govt.', 'Vol.', 'Rev.', 'Gen.', 'Gov.',
        'Sgt.', 'Cpl.', 'Lt.', 'Col.', 'Maj.', 'Capt.', 'Fig.',
        'Eq.', 'Sec.', 'Ch.', 'No.', 'Ave.', 'Blvd.', 'Dist.',
        'Pn.', 'Tn.', 'Awg.', 'Dyg.', 'Ak.', 'Dk.', 'Pg.',
    ];

    /**
     * Split text into individual sentences while preserving abbreviations,
     * decimal numbers, initials, and ellipses intact.
     *
     * @return string[]
     */
    public function tokenize(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        $protected = $this->protectNonBoundaryPeriods($text);

        $sentences = preg_split(
            '/(?<=[.!?])\s+/',
            $protected,
            -1,
            PREG_SPLIT_NO_EMPTY,
        );

        return array_values(array_filter(
            array_map(fn(string $s) => trim($this->restorePlaceholders($s)), $sentences),
            fn(string $s) => $s !== '',
        ));
    }

    /**
     * Mask periods that should NOT trigger a sentence split.
     */
    private function protectNonBoundaryPeriods(string $text): string
    {
        $text = str_replace('...', self::ELLIPSIS_PLACEHOLDER, $text);
        $text = preg_replace(
            '/\b(e\.g|i\.e|a\.m|p\.m|U\.S|U\.K|U\.N)\./i',
            '$1' . self::DOT_PLACEHOLDER,
            $text,
        );
        $text = preg_replace(
            '/\b([A-Z])\.\s*(?=[A-Z])/u',
            '$1' . self::DOT_PLACEHOLDER . ' ',
            $text,
        );
        foreach (self::ABBREVIATIONS as $abbr) {
            $text = str_replace($abbr, str_replace('.', self::DOT_PLACEHOLDER, $abbr), $text);
        }
        $text = preg_replace('/(\d)\.(\d)/', '$1' . self::DOT_PLACEHOLDER . '$2', $text);

        return $text;
    }

    private function restorePlaceholders(string $text): string
    {
        return str_replace(
            [self::DOT_PLACEHOLDER, self::ELLIPSIS_PLACEHOLDER],
            ['.', '...'],
            $text,
        );
    }
}