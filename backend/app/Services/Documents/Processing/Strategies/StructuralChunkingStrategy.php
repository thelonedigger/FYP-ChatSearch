<?php

namespace App\Services\Documents\Processing\Strategies;

use App\Services\Documents\Processing\Contracts\ChunkingStrategyInterface;

/**
 * Structure-aware chunking strategy (A + B + C).
 *
 * Respects the natural hierarchy of document text:
 *   A) Splits by paragraph boundaries first, only falling back to
 *      sentence-level splitting when a single paragraph exceeds the chunk size.
 *   B) Uses sentence-based overlap instead of character-based, so every chunk
 *      starts and ends on clean sentence boundaries.
 *   C) Leverages the robust SentenceTokenizer to handle abbreviations, decimals,
 *      initials, and other edge cases.
 */
class StructuralChunkingStrategy implements ChunkingStrategyInterface
{
    private int $chunkSize;
    private int $minChunkSize;
    private int $overlapSentences;

    public function __construct(private SentenceTokenizer $tokenizer)
    {
        $this->chunkSize = config('text_processing.chunking.chunk_size', 1000);
        $this->minChunkSize = config('text_processing.chunking.min_chunk_size', 100);
        $this->overlapSentences = config('text_processing.chunking.overlap_sentences', 2);
    }

    public function chunk(string $text): array
    {
        $paragraphs = $this->splitIntoParagraphs($text);

        if (empty($paragraphs)) {
            return [];
        }

        return $this->buildChunksFromParagraphs($paragraphs);
    }

    public function getName(): string
    {
        return 'structural';
    }

    /**
     * Split on double-newline paragraph boundaries and normalise
     * internal whitespace within each paragraph.
     *
     * @return string[]
     */
    private function splitIntoParagraphs(string $text): array
    {
        $paragraphs = preg_split('/\n\s*\n/', trim($text), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_filter(
            array_map(fn(string $p) => trim(preg_replace('/[ \t]+/', ' ', $p)), $paragraphs),
            fn(string $p) => $p !== '',
        ));
    }

    private function buildChunksFromParagraphs(array $paragraphs): array
    {
        $chunks = [];
        $buffer = [];          // paragraphs accumulated for the current chunk
        $bufferLength = 0;
        $chunkIndex = 0;
        $position = 0;

        foreach ($paragraphs as $paragraph) {
            $paraLength = strlen($paragraph);
            if ($paraLength > $this->chunkSize) {
                [$chunks, $chunkIndex, $position] = $this->flushBuffer(
                    $buffer, $bufferLength, $chunks, $chunkIndex, $position,
                );
                $buffer = [];
                $bufferLength = 0;

                $sentences = $this->tokenizer->tokenize($paragraph);
                $sentenceChunks = $this->buildChunksFromSentences($sentences, $chunkIndex, $position);

                foreach ($sentenceChunks as $chunk) {
                    $chunks[] = $chunk;
                }

                if (!empty($sentenceChunks)) {
                    $last = end($sentenceChunks);
                    $chunkIndex = $last['chunk_index'] + 1;
                    $position = $last['end_position'];
                }

                continue;
            }
            $separatorCost = $bufferLength > 0 ? 2 : 0; // "\n\n" between paragraphs
            $newLength = $bufferLength + $separatorCost + $paraLength;

            if ($newLength <= $this->chunkSize) {
                $buffer[] = $paragraph;
                $bufferLength = $newLength;
                continue;
            }
            $flushedContent = implode("\n\n", $buffer);

            if (strlen($flushedContent) >= $this->minChunkSize) {
                $chunks[] = $this->makeChunkData($flushedContent, $chunkIndex++, $position);
                $position += strlen($flushedContent);
            }
            $overlap = $this->extractTrailingSentences($flushedContent);

            $buffer = [];
            $bufferLength = 0;

            if ($overlap !== '') {
                $buffer[] = $overlap;
                $bufferLength = strlen($overlap);
            }

            $buffer[] = $paragraph;
            $bufferLength += ($bufferLength > 0 ? 2 : 0) + $paraLength;
        }
        [$chunks] = $this->flushBuffer($buffer, $bufferLength, $chunks, $chunkIndex, $position);

        return $chunks;
    }

    /**
     * Flush the paragraph buffer into a chunk if it meets the minimum size.
     *
     * @return array{0: array, 1: int, 2: int} [$chunks, $chunkIndex, $position]
     */
    private function flushBuffer(
        array $buffer,
        int $bufferLength,
        array $chunks,
        int $chunkIndex,
        int $position,
    ): array {
        if (empty($buffer)) {
            return [$chunks, $chunkIndex, $position];
        }

        $content = implode("\n\n", $buffer);

        if (strlen($content) >= $this->minChunkSize) {
            $chunks[] = $this->makeChunkData($content, $chunkIndex++, $position);
            $position += strlen($content);
        }

        return [$chunks, $chunkIndex, $position];
    }

    private function buildChunksFromSentences(array $sentences, int $startIndex, int $startPosition): array
    {
        $chunks = [];
        $currentSentences = [];
        $currentLength = 0;
        $chunkIndex = $startIndex;
        $position = $startPosition;

        foreach ($sentences as $sentence) {
            $sentenceLength = strlen($sentence);
            $separatorCost = $currentLength > 0 ? 1 : 0;
            $newLength = $currentLength + $separatorCost + $sentenceLength;

            if ($newLength <= $this->chunkSize) {
                $currentSentences[] = $sentence;
                $currentLength = $newLength;
                continue;
            }
            if (!empty($currentSentences)) {
                $content = implode(' ', $currentSentences);

                if (strlen($content) >= $this->minChunkSize) {
                    $chunks[] = $this->makeChunkData($content, $chunkIndex++, $position);
                    $position += strlen($content);
                }
                $overlapSentences = array_slice($currentSentences, -$this->overlapSentences);
                $currentSentences = $overlapSentences;
                $currentLength = strlen(implode(' ', $currentSentences));
            }

            $currentSentences[] = $sentence;
            $currentLength += ($currentLength > 0 ? 1 : 0) + $sentenceLength;
        }
        if (!empty($currentSentences)) {
            $content = implode(' ', $currentSentences);

            if (strlen($content) >= $this->minChunkSize) {
                $chunks[] = $this->makeChunkData($content, $chunkIndex, $position);
            }
        }

        return $chunks;
    }

    /**
     * Extract the last N sentences from a chunk's content for overlap.
     */
    private function extractTrailingSentences(string $content): string
    {
        if ($this->overlapSentences <= 0) {
            return '';
        }

        $sentences = $this->tokenizer->tokenize($content);
        $trailing = array_slice($sentences, -$this->overlapSentences);

        return implode(' ', $trailing);
    }

    private function makeChunkData(string $content, int $index, int $startPosition): array
    {
        $content = trim($content);

        return [
            'content' => $content,
            'chunk_index' => $index,
            'start_position' => $startPosition,
            'end_position' => $startPosition + strlen($content),
            'metadata' => [
                'word_count' => str_word_count($content),
                'character_count' => strlen($content),
                'chunking_strategy' => $this->getName(),
            ],
        ];
    }
}