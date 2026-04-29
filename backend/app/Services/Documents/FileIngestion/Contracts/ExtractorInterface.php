<?php

namespace App\Services\Documents\FileIngestion\Contracts;

use App\Services\Documents\FileIngestion\DTOs\ExtractionResult;
use App\Services\Documents\FileIngestion\FileIngestionException;

/**
 * Contract for file content extractors.
 */
interface ExtractorInterface
{
    /**
     * Extract content and metadata from a file.
     */
    public function extract(string $filePath): ExtractionResult;
    
    /**
     * Check if this extractor supports the given file extension.
     */
    public function supports(string $extension): bool;
    
    /**
     * Get the file extensions this extractor supports.
     */
    public function getSupportedExtensions(): array;
}