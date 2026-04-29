
import ReactMarkdown from 'react-markdown'
import remarkGfm from 'remark-gfm'
import type { ReactNode } from 'react'
import type { SourceReference } from '@/types/api'
import { SourceCitation } from './SourceCitation'

interface MarkdownContentProps {
  content: string
  className?: string
  trailing?: ReactNode
  sources?: SourceReference[]
}

/**
 * Split a text string on citation markers like [1], [2], etc.
 * Returns an array of alternating text segments and citation indices.
 */
function renderWithCitations(text: string, sources?: SourceReference[]): ReactNode[] {
  if (!sources?.length) return [text]

  const parts: ReactNode[] = []
  const regex = /\[(?:Source\s*)?(\d+)\]/gi
  let lastIndex = 0
  let match: RegExpExecArray | null

  while ((match = regex.exec(text)) !== null) {
    if (match.index > lastIndex) {
      parts.push(text.slice(lastIndex, match.index))
    }

    const sourceIndex = parseInt(match[1], 10)
    const source = sources.find(s => s.index === sourceIndex)

    parts.push(
      <SourceCitation
        key={`cite-${match.index}`}
        sourceIndex={sourceIndex}
        source={source}
      />
    )

    lastIndex = regex.lastIndex
  }
  if (lastIndex < text.length) {
    parts.push(text.slice(lastIndex))
  }

  return parts
}

export function MarkdownContent({ content, className = '', trailing, sources }: MarkdownContentProps) {
  return (
    <div className={`prose prose-sm dark:prose-invert max-w-none break-words ${className}`}>
      <ReactMarkdown
        remarkPlugins={[remarkGfm]}
        components={{
          p: ({ children, ...props }) => (
            <p {...props}>{processChildren(children, sources)}</p>
          ),
          li: ({ children, ...props }) => (
            <li {...props}>{processChildren(children, sources)}</li>
          ),
          pre: ({ children }) => (
            <pre className="bg-muted rounded-md p-3 overflow-x-auto text-xs">
              {children}
            </pre>
          ),
          code: ({ children, className: codeClassName, ...props }) => {
            const isInline = !codeClassName
            return isInline ? (
              <code className="bg-muted px-1.5 py-0.5 rounded text-xs font-mono" {...props}>
                {children}
              </code>
            ) : (
              <code className={codeClassName} {...props}>
                {children}
              </code>
            )
          },
          a: ({ children, href, ...props }) => (
            <a href={href} target="_blank" rel="noopener noreferrer" className="text-primary underline" {...props}>
              {children}
            </a>
          ),
        }}
      >
        {content}
      </ReactMarkdown>
      {trailing}
    </div>
  )
}

/**
 * Recursively processes React children, replacing citation markers
 * in text nodes with interactive SourceCitation components.
 */
function processChildren(children: ReactNode, sources?: SourceReference[]): ReactNode {
  if (!sources?.length) return children
  if (!Array.isArray(children)) {
    if (typeof children === 'string') {
      const result = renderWithCitations(children, sources)
      return result.length === 1 ? result[0] : <>{result}</>
    }
    return children
  }

  return children.map((child, i) => {
    if (typeof child === 'string') {
      const result = renderWithCitations(child, sources)
      return result.length === 1 ? <span key={i}>{result[0]}</span> : <span key={i}>{result}</span>
    }
    return child
  })
}