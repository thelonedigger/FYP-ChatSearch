<?php

namespace App\Services\Documents\FileIngestion\Extractors;

use App\Services\Documents\FileIngestion\Contracts\ExtractorInterface;
use App\Services\Documents\FileIngestion\DTOs\ExtractionResult;
use App\Services\Documents\FileIngestion\FileIngestionException;
use Smalot\PdfParser\Parser as PdfParser;

class PdfExtractor implements ExtractorInterface
{
    private array $supportedExtensions = ['pdf'];
    private int $minContentLength;
    
    public function __construct()
    {
        $this->minContentLength = config('text_processing.extraction.min_pdf_content_length', 50);
    }
    
    public function extract(string $filePath): ExtractionResult
    {
        if (!file_exists($filePath)) throw FileIngestionException::fileNotFound($filePath);
        if (!is_readable($filePath)) throw FileIngestionException::fileNotReadable($filePath);
        
        try {
            $pdf = (new PdfParser())->parseFile($filePath);
            $content = $this->cleanContent($pdf->getText());
            
            if (strlen($content) < $this->minContentLength) {
                throw FileIngestionException::insufficientContent($filePath, strlen($content), $this->minContentLength);
            }
            
            return new ExtractionResult($content, $this->extractMetadata($pdf, $filePath));
        } catch (FileIngestionException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw FileIngestionException::extractionFailed($filePath, $e->getMessage(), $e);
        }
    }
    
    public function supports(string $extension): bool
    {
        return in_array(strtolower($extension), $this->supportedExtensions);
    }
    
    public function getSupportedExtensions(): array
    {
        return $this->supportedExtensions;
    }
    
    private function cleanContent(string $content): string
    {
        $content = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $content);
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $content = preg_replace('/[ \t]+/', ' ', $content);
        $content = preg_replace('/\n{3,}/', "\n\n", $content);
        return trim(mb_convert_encoding($content, 'UTF-8', 'UTF-8'));
    }
    
    private function extractMetadata($pdf, string $filePath): array
    {
        $details = $pdf->getDetails();
        $metadata = array_filter([
            'file_type' => 'pdf',
            'file_size' => filesize($filePath),
            'title' => $details['Title'] ?? null,
            'author' => $details['Author'] ?? null,
            'subject' => $details['Subject'] ?? null,
            'creator' => $details['Creator'] ?? null,
            'producer' => $details['Producer'] ?? null,
            'keywords' => $details['Keywords'] ?? null,
            'creation_date' => isset($details['CreationDate']) ? $this->parsePdfDate($details['CreationDate']) : null,
            'modification_date' => isset($details['ModDate']) ? $this->parsePdfDate($details['ModDate']) : null,
            'pages' => count($pdf->getPages()),
        ], fn($v) => $v !== null);
        
        return $metadata;
    }
    
    private function parsePdfDate(mixed $pdfDate): ?string
    {
        if (is_array($pdfDate)) {
            $pdfDate = !empty($pdfDate) ? reset($pdfDate) : null;
        }
        if (!is_string($pdfDate)) {
            return null;
        }
        if (preg_match('/D:(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})/', $pdfDate, $m)) {
            try {
                return (new \DateTime("{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:{$m[6]}"))->format('Y-m-d H:i:s');
            } catch (\Exception) {
                return null;
            }
        }
        
        return null;
    }
}