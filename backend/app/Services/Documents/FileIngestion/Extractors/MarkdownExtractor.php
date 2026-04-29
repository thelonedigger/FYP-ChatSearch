<?php

namespace App\Services\Documents\FileIngestion\Extractors;

use App\Services\Documents\FileIngestion\Contracts\ExtractorInterface;
use App\Services\Documents\FileIngestion\DTOs\ExtractionResult;
use App\Services\Documents\FileIngestion\FileIngestionException;

class MarkdownExtractor implements ExtractorInterface
{
    private array $supportedExtensions = ['md', 'markdown'];
    private int $minContentLength;
    private bool $stripMarkdownSyntax;
    private bool $extractFrontmatter;
    
    public function __construct()
    {
        $this->minContentLength = config('text_processing.extraction.min_markdown_content_length', 50);
        $this->stripMarkdownSyntax = config('text_processing.extraction.markdown_strip_syntax', false);
        $this->extractFrontmatter = config('text_processing.extraction.markdown_extract_frontmatter', true);
    }
    
    public function extract(string $filePath): ExtractionResult
    {
        if (!file_exists($filePath)) throw FileIngestionException::fileNotFound($filePath);
        if (!is_readable($filePath)) throw FileIngestionException::fileNotReadable($filePath);
        
        try {
            $rawContent = file_get_contents($filePath);
            if ($rawContent === false) throw FileIngestionException::extractionFailed($filePath, 'Failed to read file');
            
            $rawContent = $this->ensureUtf8($rawContent);
            [$content, $frontmatter] = $this->extractFrontmatter ? $this->extractFrontmatterMetadata($rawContent) : [$rawContent, []];
            
            if ($this->stripMarkdownSyntax) $content = $this->stripMarkdown($content);
            $content = $this->cleanContent($content);
            
            if (strlen($content) < $this->minContentLength) {
                throw FileIngestionException::insufficientContent($filePath, strlen($content), $this->minContentLength);
            }
            
            return new ExtractionResult($content, $this->extractMetadata($rawContent, $filePath, $frontmatter));
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
    
    private function extractFrontmatterMetadata(string $content): array
    {
        if (!preg_match('/^---\s*\n(.*?)\n---\s*\n/s', $content, $matches)) {
            return [$content, []];
        }
        
        $frontmatter = [];
        foreach (explode("\n", $matches[1]) as $line) {
            $line = trim($line);
            if (empty($line) || str_starts_with($line, '#')) continue;
            
            if (preg_match('/^([^:]+):\s*(.*)$/', $line, $m)) {
                $value = trim(trim($m[2]), '"\'');
                if (preg_match('/^\[(.*)\]$/', $value, $arr)) {
                    $value = array_map(fn($v) => trim(trim($v), '"\''), explode(',', $arr[1]));
                }
                $frontmatter[trim($m[1])] = $value;
            }
        }
        
        return [substr($content, strlen($matches[0])), $frontmatter];
    }
    
    private function stripMarkdown(string $content): string
    {
        $patterns = [
            '/^#{1,6}\s+(.+)$/m' => '$1',
            '/\*\*(.+?)\*\*/' => '$1',
            '/\*(.+?)\*/' => '$1',
            '/__(.+?)__/' => '$1',
            '/_(.+?)_/' => '$1',
            '/\[([^\]]+)\]\([^\)]+\)/' => '$1',
            '/!\[([^\]]*)\]\([^\)]+\)/' => '$1',
            '/`([^`]+)`/' => '$1',
            '/```[^`]*```/s' => '',
            '/^>\s+(.+)$/m' => '$1',
            '/^[\-\*_]{3,}$/m' => '',
            '/^\s*[\-\*\+]\s+/m' => '',
            '/^\s*\d+\.\s+/m' => '',
        ];
        
        foreach ($patterns as $pattern => $replacement) {
            $content = preg_replace($pattern, $replacement, $content);
        }
        return $content;
    }
    
    private function cleanContent(string $content): string
    {
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $content = preg_replace('/[ \t]+/', ' ', $content);
        $content = preg_replace('/\n{3,}/', "\n\n", $content);
        return trim($content);
    }
    
    private function ensureUtf8(string $content): string
    {
        $encoding = mb_detect_encoding($content, ['UTF-8', 'ISO-8859-1', 'Windows-1252', 'ASCII'], true);
        if ($encoding && $encoding !== 'UTF-8') $content = mb_convert_encoding($content, 'UTF-8', $encoding);
        return mb_scrub($content, 'UTF-8');
    }
    
    private function extractMetadata(string $content, string $filePath, array $frontmatter): array
    {
        $metadata = array_filter([
            'file_type' => 'markdown',
            'file_size' => filesize($filePath),
            'line_count' => substr_count($content, "\n") + 1,
            'title' => $frontmatter['title'] ?? $this->extractFirstHeading($content),
            'author' => $frontmatter['author'] ?? null,
            'date' => $frontmatter['date'] ?? null,
            'description' => $frontmatter['description'] ?? null,
            'tags' => $frontmatter['tags'] ?? null,
            'categories' => $frontmatter['categories'] ?? null,
            'frontmatter' => !empty($frontmatter) ? $frontmatter : null,
            'headings_count' => preg_match_all('/^#{1,6}\s+.+$/m', $content),
            'code_blocks_count' => preg_match_all('/```[^`]*```/s', $content),
            'links_count' => preg_match_all('/\[([^\]]+)\]\([^\)]+\)/', $content),
            'images_count' => preg_match_all('/!\[([^\]]*)\]\([^\)]+\)/', $content),
        ], fn($v) => $v !== null);
        
        return $metadata;
    }
    
    private function extractFirstHeading(string $content): ?string
    {
        return preg_match('/^#{1,6}\s+(.+)$/m', $content, $m) ? trim($m[1]) : null;
    }
}