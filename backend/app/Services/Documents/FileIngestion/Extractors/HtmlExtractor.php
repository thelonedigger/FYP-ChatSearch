<?php

namespace App\Services\Documents\FileIngestion\Extractors;

use App\Services\Documents\FileIngestion\Contracts\ExtractorInterface;
use App\Services\Documents\FileIngestion\DTOs\ExtractionResult;
use App\Services\Documents\FileIngestion\FileIngestionException;
use DOMDocument;
use DOMXPath;

class HtmlExtractor implements ExtractorInterface
{
    private array $supportedExtensions = ['html', 'htm'];
    private int $minContentLength;
    private bool $includeLinks;
    private bool $preserveStructure;
    private array $excludeTags;
    
    public function __construct()
    {
        $this->minContentLength = config('text_processing.extraction.min_html_content_length', 50);
        $this->includeLinks = config('text_processing.extraction.html_include_links', false);
        $this->preserveStructure = config('text_processing.extraction.html_preserve_structure', true);
        $this->excludeTags = config('text_processing.extraction.html_exclude_tags', ['script', 'style', 'nav', 'header', 'footer', 'aside']);
    }
    
    public function extract(string $filePath): ExtractionResult
    {
        if (!file_exists($filePath)) throw FileIngestionException::fileNotFound($filePath);
        if (!is_readable($filePath)) throw FileIngestionException::fileNotReadable($filePath);
        
        try {
            $rawHtml = file_get_contents($filePath);
            if ($rawHtml === false) throw FileIngestionException::extractionFailed($filePath, 'Failed to read file');
            
            $dom = $this->parseHtml($this->ensureUtf8($rawHtml));
            $content = $this->cleanContent($this->extractContent($dom));
            
            if (strlen($content) < $this->minContentLength) {
                throw FileIngestionException::insufficientContent($filePath, strlen($content), $this->minContentLength);
            }
            
            return new ExtractionResult($content, $this->extractMetadata($dom, $filePath));
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
    
    private function parseHtml(string $html): DOMDocument
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors(false);
        return $dom;
    }
    
    private function extractContent(DOMDocument $dom): string
    {
        $this->removeExcludedTags($dom);
        $xpath = new DOMXPath($dom);
        $bodyNodes = $xpath->query('//body');
        $rootNode = $bodyNodes->length > 0 ? $bodyNodes->item(0) : $dom->documentElement;
        
        if (!$rootNode) return '';
        
        return $this->preserveStructure ? $this->extractWithStructure($rootNode, $xpath) : trim($rootNode->textContent ?? '');
    }
    
    private function removeExcludedTags(DOMDocument $dom): void
    {
        foreach ($this->excludeTags as $tagName) {
            $tags = iterator_to_array($dom->getElementsByTagName($tagName));
            foreach ($tags as $tag) {
                $tag->parentNode?->removeChild($tag);
            }
        }
    }
    
    private function extractWithStructure($node, DOMXPath $xpath): string
    {
        $content = [];
        $elements = $xpath->query('//h1|//h2|//h3|//h4|//h5|//h6|//p|//li|//blockquote|//pre|//table');
        
        foreach ($elements as $element) {
            $text = trim($this->getNodeText($element));
            if (empty($text)) continue;
            
            $content[] = match($element->nodeName) {
                'h1' => "=== {$text} ===",
                'h2' => "== {$text} ==",
                'h3' => "= {$text} =",
                'h4', 'h5', 'h6' => "- {$text}",
                'li' => "• {$text}",
                'blockquote' => "> {$text}",
                'pre' => "```\n{$text}\n```",
                'table' => $this->extractTableText($element),
                default => $text,
            };
        }
        
        return implode("\n\n", array_filter($content));
    }
    
    private function getNodeText($node): string
    {
        $text = '';
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                $text .= $child->textContent;
            } elseif ($child->nodeType === XML_ELEMENT_NODE) {
                if ($child->nodeName === 'a' && $this->includeLinks) {
                    $text .= trim($child->textContent) . ($child->getAttribute('href') ? " ({$child->getAttribute('href')})" : '');
                } else {
                    $text .= $this->getNodeText($child);
                }
            }
        }
        return $text;
    }
    
    private function extractTableText($tableNode): string
    {
        $xpath = new DOMXPath($tableNode->ownerDocument);
        $rows = [];
        
        foreach ($xpath->query('.//tr', $tableNode) as $tr) {
            $cells = array_filter(array_map(fn($c) => trim($this->getNodeText($c)), iterator_to_array($xpath->query('.//td|.//th', $tr))));
            if (!empty($cells)) $rows[] = implode(' | ', $cells);
        }
        
        return !empty($rows) ? "=== Table ===\n" . implode("\n", $rows) . "\n=== End Table ===" : '';
    }
    
    private function cleanContent(string $content): string
    {
        $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $content = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $content);
        $content = preg_replace('/[ \t]+/', ' ', $content);
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $content = preg_replace('/\n{3,}/', "\n\n", $content);
        return trim(implode("\n", array_map('trim', explode("\n", $content))));
    }
    
    private function ensureUtf8(string $content): string
    {
        if (preg_match('/<meta[^>]+charset=["\']?([^"\'>\s]+)/i', $content, $m) && strcasecmp($m[1], 'UTF-8') !== 0) {
            $converted = @mb_convert_encoding($content, 'UTF-8', $m[1]);
            if ($converted !== false) return $converted;
        }
        
        $encoding = mb_detect_encoding($content, ['UTF-8', 'ISO-8859-1', 'Windows-1252', 'ASCII'], true);
        if ($encoding && $encoding !== 'UTF-8') $content = mb_convert_encoding($content, 'UTF-8', $encoding);
        return mb_scrub($content, 'UTF-8');
    }
    
    private function extractMetadata(DOMDocument $dom, string $filePath): array
    {
        $xpath = new DOMXPath($dom);
        $metadata = ['file_type' => 'html', 'file_size' => filesize($filePath)];
        
        if ($titleNodes = $xpath->query('//title') and $titleNodes->length > 0) {
            $metadata['title'] = trim($titleNodes->item(0)->textContent);
        }
        
        $metaTags = [];
        foreach ($xpath->query('//meta[@name or @property]') as $meta) {
            $name = $meta->getAttribute('name') ?: $meta->getAttribute('property');
            $content = $meta->getAttribute('content');
            if ($name && $content) {
                $metaTags[$name] = $content;
                match(strtolower($name)) {
                    'description', 'og:description' => $metadata['description'] ??= $content,
                    'author' => $metadata['author'] = $content,
                    'keywords' => $metadata['keywords'] = $content,
                    'og:title' => $metadata['title'] ??= $content,
                    default => null,
                };
            }
        }
        
        $metadata['meta_tags_count'] = count($metaTags);
        $metadata['meta_tags'] = $metaTags;
        
        if ($htmlNodes = $xpath->query('//html[@lang]') and $htmlNodes->length > 0) {
            $metadata['language'] = $htmlNodes->item(0)->getAttribute('lang');
        }
        
        $metadata['headings_count'] = $xpath->query('//h1|//h2|//h3|//h4|//h5|//h6')->length;
        $metadata['paragraphs_count'] = $xpath->query('//p')->length;
        $metadata['links_count'] = $xpath->query('//a[@href]')->length;
        $metadata['images_count'] = $xpath->query('//img')->length;
        $metadata['tables_count'] = $xpath->query('//table')->length;
        
        return array_filter($metadata, fn($v) => $v !== null && $v !== '');
    }
}