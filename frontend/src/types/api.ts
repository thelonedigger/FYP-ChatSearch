export interface SourceReference {
  index: number
  chunk_id: number | null
  filename: string
  chunk_index: number | null
  content: string
  score: number | null
}

export interface LlmDebugCall {
  model?: string
  provider?: string
  prompt?: string
  raw_response?: string
  response_time_ms?: number
  llm_response_time_ms?: number
  response_length?: number
  usage?: { prompt_tokens?: number; completion_tokens?: number; total_tokens?: number } | null
  fallback?: boolean
  error?: string
}

export interface DebugInfo {
  intent?: LlmDebugCall | null
  nlg?: LlmDebugCall | null
  timing?: Record<string, number> | null
}

export interface SearchResponse {
  type: string; query: string; search_type?: string; total_results: number; resources?: TextChunk[]; documents?: DocumentResult[]
  summary?: string; conversation_turn: number; intent: string; session_id: string; entity_type?: string; is_refinement?: boolean
  original_count?: number; filters_applied?: string[]; message?: string; suggestions?: string[]; original_query?: string
  enhanced_query?: string; summarized_result_index?: number; document_scope?: string; search_strategy?: string
  sources?: SourceReference[]
}

export interface DocumentResult {
  id: number; filename: string; file_size: number; total_chunks: number; metadata: any; relevance_score?: number
  preview_chunks?: TextChunk[]; created_at: string; updated_at: string
}

export interface TextChunk {
  id: number; content: string; highlighted_content?: string; chunk_index: number; word_count: number
  relevance_score?: number; search_strategy?: string; fusion_score?: number
  ranking_details?: Array<{ list: number; rank: number; weight: number; rrf_contribution: number }>
  similarity?: number; trigram_similarity?: number; metadata?: any; document: { id: number; filename: string }
}

export interface ConversationHistoryResponse { type: string; history: ConversationTurn[]; session_id: string }
export interface ConversationTurn { query: string; intent: string; turn: number; timestamp: string }
export interface EntityType { type: string; display_name: string; description: string; model: string; searchable_fields: string[] }
export interface EntityListResponse { entities: EntityType[]; total: number }
export interface SystemStats { entities: { [entityType: string]: { total: number; total_chunks: number } }; latest_processed?: string }

export interface Conversation {
  id: number
  title: string
  lastMessage: string
  timestamp: string
  messageCount: number
}

export interface Message {
  id?: number
  type: 'user' | 'assistant'
  content: string
  metadata?: { turn?: number; intent?: string; timestamp?: string }
  searchResponse?: SearchResponse
  debug?: DebugInfo | null
  created_at?: string
}