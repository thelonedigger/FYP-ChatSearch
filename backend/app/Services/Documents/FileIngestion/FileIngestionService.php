<?php

namespace App\Services\Documents\FileIngestion;

use App\Services\Documents\FileIngestion\Contracts\ExtractorInterface;
use App\Services\Documents\FileIngestion\DTOs\ExtractionResult;
use App\Services\Documents\FileIngestion\Extractors\{PdfExtractor, DocxExtractor, MarkdownExtractor, TextExtractor, HtmlExtractor};

class FileIngestionService
{
    
    private array $extractors;
    
    public function __construct()
    {
        $this->extractors = [
            new PdfExtractor(),
            new DocxExtractor(),
            new MarkdownExtractor(),
            new TextExtractor(),
            new HtmlExtractor(),
        ];
    }

    public function ingest(string $filePath): ExtractionResult
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $extractor = $this->findExtractor($extension) 
            ?? throw FileIngestionException::unsupportedFileType($extension, $this->getSupportedExtensions());
        
        return $extractor->extract($filePath);
    }

    public function supports(string $filePath): bool
    {
        return $this->findExtractor(strtolower(pathinfo($filePath, PATHINFO_EXTENSION))) !== null;
    }

    public function getSupportedExtensions(): array
    {
        return array_unique(array_merge(...array_map(fn($e) => $e->getSupportedExtensions(), $this->extractors)));
    }

    private function findExtractor(string $extension): ?ExtractorInterface
    {
        foreach ($this->extractors as $extractor) {
            if ($extractor->supports($extension)) {
                return $extractor;
            }
        }
        return null;
    }
}