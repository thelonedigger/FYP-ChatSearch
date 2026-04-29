<?php

namespace App\Services\Documents\Processing\Strategies;

use App\Services\Documents\Processing\Contracts\ChunkingStrategyInterface;
use App\Services\Documents\Processing\EmbeddingService;

/**
 * Semantic chunking strategy (D).
 *
 * Embeds each sentence individually and measures cosine similarity between
 * consecutive sentences. A sharp drop in similarity signals a topic
 * boundary — that's where chunks are split.
 *
 * Best suited for unstructured text (OCR output, transcripts) where
 * paragraph structure is unreliable. Costs more embedding API calls
 * than structural chunking, so it is offered as an opt-in toggle.
 */
class SemanticChunkingStrategy implements ChunkingStrategyInterface
{
    private int $chunkSize;
    private int $minChunkSize;
    private float $similarityThreshold;
    private int $overlapSentences;

    public function __construct(
        private EmbeddingService $embeddingService,
        private SentenceTokenizer $tokenizer,
    ) {
        $this->chunkSize = config('text_processing.chunking.chunk_size', 1000);
        $this->minChunkSize = config('text_processing.chunking.min_chunk_size', 100);
        $this->similarityThreshold = config('text_processing.chunking.semantic_threshold', 0.45);
        $this->overlapSentences = config('text_processing.chunking.overlap_sentences', 2);
    }

    public function chunk(string $text): array
    {
        $paragraphs = preg_split('/\n\s*\n/', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        $sentences = [];

        foreach ($paragraphs as $paragraph) {
            $cleaned = trim(preg_replace('/[ \t]+/', ' ', $paragraph));
            if ($cleaned !== '') {
                array_push($sentences, ...$this->tokenizer->tokenize($cleaned));
            }
        }

        if (empty($sentences)) {
            return [];
        }
        if (count($sentences) <= 3) {
            return [$this->makeChunkData(implode(' ', $sentences), 0, 0)];
        }

        $boundaries = $this->detectTopicBoundaries($sentences);

        return $this->assembleChunks($sentences, $boundaries);
    }

    public function getName(): string
    {
        return 'semantic';
    }

    /**
     * Embed all sentences, compare consecutive pairs, and return
     * the indices where similarity drops below the threshold.
     *
     * @return int[] Sentence indices where a new chunk should begin.
     */
    private function detectTopicBoundaries(array $sentences): array
    {
        $embeddings = $this->embeddingService->generateEmbeddings($sentences, 'retrieval.passage');

        if (count($embeddings) !== count($sentences)) {
            return [];
        }

        $boundaries = [];

        for ($i = 1, $len = count($embeddings); $i < $len; $i++) {
            $similarity = $this->cosineSimilarity($embeddings[$i - 1], $embeddings[$i]);

            if ($similarity < $this->similarityThreshold) {
                $boundaries[] = $i;
            }
        }

        return $boundaries;
    }

    /**
     * Group sentences by the detected topic boundaries, then enforce
     * the chunk size limit and add sentence-based overlap.
     */
    private function assembleChunks(array $sentences, array $boundaries): array
    {
        $groups = [];
        $start = 0;

        foreach ($boundaries as $boundary) {
            $groups[] = array_slice($sentences, $start, $boundary - $start);
            $start = $boundary;
        }
        $groups[] = array_slice($sentences, $start);
        $chunks = [];
        $buffer = [];
        $bufferLength = 0;
        $chunkIndex = 0;
        $position = 0;

        foreach ($groups as $group) {
            $groupText = implode(' ', $group);
            $groupLength = strlen($groupText);
            if ($groupLength > $this->chunkSize) {
                if (!empty($buffer)) {
                    $content = implode(' ', $buffer);
                    if (strlen($content) >= $this->minChunkSize) {
                        $chunks[] = $this->makeChunkData($content, $chunkIndex++, $position);
                        $position += strlen($content);
                    }
                    $buffer = [];
                    $bufferLength = 0;
                }
                foreach ($group as $sentence) {
                    $sentLen = strlen($sentence);
                    $cost = $bufferLength > 0 ? 1 : 0;

                    if ($bufferLength + $cost + $sentLen <= $this->chunkSize) {
                        $buffer[] = $sentence;
                        $bufferLength += $cost + $sentLen;
                        continue;
                    }

                    if (!empty($buffer)) {
                        $content = implode(' ', $buffer);
                        if (strlen($content) >= $this->minChunkSize) {
                            $chunks[] = $this->makeChunkData($content, $chunkIndex++, $position);
                            $position += strlen($content);
                        }

                        $overlap = array_slice($buffer, -$this->overlapSentences);
                        $buffer = $overlap;
                        $bufferLength = strlen(implode(' ', $buffer));
                    }

                    $buffer[] = $sentence;
                    $bufferLength += ($bufferLength > 0 ? 1 : 0) + $sentLen;
                }

                continue;
            }
            $cost = $bufferLength > 0 ? 1 : 0;

            if ($bufferLength + $cost + $groupLength <= $this->chunkSize) {
                array_push($buffer, ...$group);
                $bufferLength += $cost + $groupLength;
                continue;
            }
            if (!empty($buffer)) {
                $content = implode(' ', $buffer);
                if (strlen($content) >= $this->minChunkSize) {
                    $chunks[] = $this->makeChunkData($content, $chunkIndex++, $position);
                    $position += strlen($content);
                }

                $overlap = array_slice($buffer, -$this->overlapSentences);
                $buffer = $overlap;
                $bufferLength = strlen(implode(' ', $buffer));
            }

            array_push($buffer, ...$group);
            $bufferLength += ($bufferLength > 0 ? 1 : 0) + $groupLength;
        }
        if (!empty($buffer)) {
            $content = implode(' ', $buffer);
            if (strlen($content) >= $this->minChunkSize) {
                $chunks[] = $this->makeChunkData($content, $chunkIndex, $position);
            }
        }

        return $chunks;
    }

    private function cosineSimilarity(array $a, array $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0, $len = count($a); $i < $len; $i++) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        $denominator = sqrt($normA) * sqrt($normB);

        return $denominator > 0.0 ? $dot / $denominator : 0.0;
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