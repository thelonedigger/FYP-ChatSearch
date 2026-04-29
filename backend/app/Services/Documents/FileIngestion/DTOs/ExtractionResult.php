<?php

namespace App\Services\Documents\FileIngestion\DTOs;

class ExtractionResult
{
    public function __construct(
        public readonly string $content,
        public readonly array $metadata = [],
        public readonly ?array $structure = null
    ) {}
    
    public function hasContent(): bool { return !empty(trim($this->content)); }
    public function getContentLength(): int { return strlen($this->content); }
    public function getWordCount(): int { return str_word_count($this->content); }
}