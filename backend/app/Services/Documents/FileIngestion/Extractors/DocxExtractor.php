<?php

namespace App\Services\Documents\FileIngestion\Extractors;

use App\Services\Documents\FileIngestion\Contracts\ExtractorInterface;
use App\Services\Documents\FileIngestion\DTOs\ExtractionResult;
use App\Services\Documents\FileIngestion\FileIngestionException;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Element\{AbstractContainer, Text, TextRun, Table, ListItem};

class DocxExtractor implements ExtractorInterface
{
    private array $supportedExtensions = ['docx', 'doc'];
    private int $minContentLength;
    private bool $includeHeadersFooters;
    private string $tableDelimiter;
    
    public function __construct()
    {
        $this->minContentLength = config('text_processing.extraction.min_docx_content_length', 50);
        $this->includeHeadersFooters = config('text_processing.extraction.docx_include_headers_footers', true);
        $this->tableDelimiter = config('text_processing.extraction.docx_table_delimiter', ' | ');
    }
    
    public function extract(string $filePath): ExtractionResult
    {
        if (!file_exists($filePath)) throw FileIngestionException::fileNotFound($filePath);
        if (!is_readable($filePath)) throw FileIngestionException::fileNotReadable($filePath);
        
        try {
            $phpWord = IOFactory::load($filePath);
            $content = $this->cleanContent($this->extractContent($phpWord));
            
            if (strlen($content) < $this->minContentLength) {
                throw FileIngestionException::insufficientContent($filePath, strlen($content), $this->minContentLength);
            }
            
            return new ExtractionResult($content, $this->extractMetadata($phpWord, $filePath));
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
    
    private function extractContent($phpWord): string
    {
        $allContent = [];
        
        foreach ($phpWord->getSections() as $section) {
            $sectionContent = [];
            
            if ($this->includeHeadersFooters) {
                foreach ($section->getHeaders() as $header) {
                    if ($text = trim($this->extractElementsText($header->getElements()))) {
                        $sectionContent[] = "=== Header ===\n" . $text;
                    }
                }
            }
            
            if ($text = trim($this->extractElementsText($section->getElements()))) {
                $sectionContent[] = $text;
            }
            
            if ($this->includeHeadersFooters) {
                foreach ($section->getFooters() as $footer) {
                    if ($text = trim($this->extractElementsText($footer->getElements()))) {
                        $sectionContent[] = "=== Footer ===\n" . $text;
                    }
                }
            }
            
            if (!empty($sectionContent)) {
                $allContent[] = implode("\n\n", $sectionContent);
            }
        }
        
        return implode("\n\n", $allContent);
    }
    
    private function extractElementsText(array $elements): string
    {
        return implode("\n", array_filter(array_map(fn($e) => trim($this->extractElementText($e)), $elements)));
    }
    
    private function extractElementText($element): string
    {
        if ($element instanceof TextRun) {
            return implode('', array_map(fn($e) => $e instanceof Text ? $e->getText() : '', $element->getElements()));
        }
        if ($element instanceof Text) return $element->getText();
        if ($element instanceof ListItem) return "• " . $this->extractElementText($element->getTextObject());
        if ($element instanceof Table) return $this->extractTableText($element);
        if ($element instanceof AbstractContainer) return $this->extractElementsText($element->getElements());
        return '';
    }
    
    private function extractTableText(Table $table): string
    {
        $rows = [];
        foreach ($table->getRows() as $row) {
            $cells = array_filter(array_map(fn($c) => trim($this->extractElementsText($c->getElements())), $row->getCells()));
            if (!empty($cells)) $rows[] = implode($this->tableDelimiter, $cells);
        }
        return !empty($rows) ? "=== Table ===\n" . implode("\n", $rows) . "\n=== End Table ===" : '';
    }
    
    private function cleanContent(string $content): string
    {
        $content = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $content);
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $content = preg_replace('/[ \t]+/', ' ', $content);
        $content = preg_replace('/\n{3,}/', "\n\n", $content);
        return trim(mb_convert_encoding($content, 'UTF-8', 'UTF-8'));
    }
    
    private function extractMetadata($phpWord, string $filePath): array
    {
        $docInfo = $phpWord->getDocInfo();
        
        return array_filter([
            'file_type' => 'docx',
            'file_size' => filesize($filePath),
            'section_count' => count($phpWord->getSections()),
            'title' => $docInfo->getTitle() ?: null,
            'subject' => $docInfo->getSubject() ?: null,
            'description' => $docInfo->getDescription() ?: null,
            'creator' => $docInfo->getCreator() ?: null,
            'last_modified_by' => $docInfo->getLastModifiedBy() ?: null,
            'keywords' => $docInfo->getKeywords() ?: null,
            'category' => $docInfo->getCategory() ?: null,
            'company' => $docInfo->getCompany() ?: null,
            'creation_date' => $docInfo->getCreated() ? date('Y-m-d H:i:s', $docInfo->getCreated()) : null,
            'modification_date' => $docInfo->getModified() ? date('Y-m-d H:i:s', $docInfo->getModified()) : null,
        ], fn($v) => $v !== null);
    }
}