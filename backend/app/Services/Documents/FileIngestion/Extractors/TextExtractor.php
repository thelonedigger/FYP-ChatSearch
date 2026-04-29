<?php

namespace App\Services\Documents\FileIngestion\Extractors;

use App\Services\Documents\FileIngestion\Contracts\ExtractorInterface;
use App\Services\Documents\FileIngestion\DTOs\ExtractionResult;
use App\Services\Documents\FileIngestion\FileIngestionException;

class TextExtractor implements ExtractorInterface
{
    private array $supportedExtensions = ['txt', 'text'];
    
    public function extract(string $filePath): ExtractionResult
    {
        if (!file_exists($filePath)) throw FileIngestionException::fileNotFound($filePath);
        if (!is_readable($filePath)) throw FileIngestionException::fileNotReadable($filePath);
        
        $content = file_get_contents($filePath);
        if ($content === false) throw FileIngestionException::extractionFailed($filePath, 'Failed to read file');
        
        $content = $this->ensureUtf8($content);
        
        return new ExtractionResult($content, [
            'file_type' => 'text',
            'file_size' => filesize($filePath),
            'encoding' => mb_detect_encoding($content, ['UTF-8', 'ISO-8859-1', 'ASCII'], true),
            'line_count' => substr_count($content, "\n") + 1,
        ]);
    }
    
    public function supports(string $extension): bool
    {
        return in_array(strtolower($extension), $this->supportedExtensions);
    }
    
    public function getSupportedExtensions(): array
    {
        return $this->supportedExtensions;
    }
    
    private function ensureUtf8(string $content): string
    {
        $encoding = mb_detect_encoding($content, ['UTF-8', 'ISO-8859-1', 'Windows-1252', 'ASCII'], true);
        if ($encoding && $encoding !== 'UTF-8') {
            $content = mb_convert_encoding($content, 'UTF-8', $encoding);
        }
        return mb_scrub($content, 'UTF-8');
    }
}