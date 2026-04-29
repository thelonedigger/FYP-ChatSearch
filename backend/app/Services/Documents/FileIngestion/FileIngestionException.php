<?php

namespace App\Services\Documents\FileIngestion;

class FileIngestionException extends \Exception
{
    public static function unsupportedFileType(string $ext, array $supported): self
    {
        return new self("Unsupported file type: .{$ext}. Supported: " . implode(', ', $supported));
    }
    
    public static function extractionFailed(string $filePath, string $reason, ?\Throwable $previous = null): self
    {
        return new self("Failed to extract from " . basename($filePath) . ": {$reason}", 0, $previous);
    }
    
    public static function fileNotFound(string $filePath): self { return new self("File not found: {$filePath}"); }
    public static function fileNotReadable(string $filePath): self { return new self("File not readable: {$filePath}"); }
    
    public static function insufficientContent(string $filePath, int $extracted, int $minimum): self
    {
        return new self("Insufficient text in " . basename($filePath) . ": {$extracted} chars, min: {$minimum}");
    }
}