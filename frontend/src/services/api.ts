import axios from 'axios'
import type { SearchResponse, EntityListResponse, Conversation, Message, DebugInfo } from '../types/api'

const api = axios.create({ baseURL: `${import.meta.env.VITE_API_URL}/api/v1`, headers: { 'Content-Type': 'application/json' } })

api.interceptors.request.use((config) => {
  const token = localStorage.getItem('auth_token')
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

export const authApi = {
  getDevUsers: async () => (await api.get('/auth/users')).data,
  login: async (email: string) => (await api.post('/auth/login', { email })).data,
  logout: async () => (await api.post('/auth/logout')).data,
  getUser: async () => (await api.get('/user')).data,
}

export const conversationApi = {
  list: async (): Promise<Conversation[]> =>
    (await api.get('/conversations')).data,

  create: async (title?: string): Promise<Conversation> =>
    (await api.post('/conversations', { title })).data,

  show: async (id: number): Promise<{ id: number; title: string; messages: Message[] }> =>
    (await api.get(`/conversations/${id}`)).data,

  update: async (id: number, title: string): Promise<{ id: number; title: string }> =>
    (await api.put(`/conversations/${id}`, { title })).data,

  destroy: async (id: number): Promise<void> => {
    await api.delete(`/conversations/${id}`)
  },

  addMessage: async (conversationId: number, message: {
    role: 'user' | 'assistant'
    content: string
    metadata?: Record<string, unknown>
    search_response?: Record<string, unknown>
  }): Promise<Message> =>
    (await api.post(`/conversations/${conversationId}/messages`, message)).data,
}

export interface AnalyticsDashboard { performance: SearchPerformanceStats; intent_distribution: IntentDistribution; interactions: InteractionStats; popular_queries: PopularQueries; time_series: TimeSeries; generated_at: string }
export interface SearchPerformanceStats { period: string; since: string; total_searches: number; timing: { avg_total_ms: number; avg_vector_ms: number; avg_trigram_ms: number; avg_fusion_ms: number; avg_rerank_ms: number; avg_llm_ms: number; avg_intent_classification_ms: number; min_ms: number; max_ms: number; p50_ms: number; p95_ms: number; p99_ms: number }; quality: { avg_results_count: number; avg_top_relevance: number }; by_search_type: Record<string, { count: number; avg_time_ms: number; avg_results: number }> }
export interface IntentDistribution { period: string; total: number; intents: Array<{ intent: string; count: number; percentage: number; avg_time_ms: number; avg_results: number }> }
export interface InteractionStats { period: string; total_searches: number; total_clicks: number; click_through_rate: number; by_interaction_type: Record<string, { count: number; avg_position: number; avg_relevance: number }>; clicks_by_position: Array<{ position: number; count: number }> }
export interface PopularQueries { period: string; queries: Array<{ query: string; count: number; avg_results: number; avg_time_ms: number }> }
export interface TimeSeries { period: string; interval: string; data: Array<{ timestamp: string; search_count: number; avg_time_ms: number; avg_results: number }> }

export const analyticsApi = {
  getDashboard: async (period = '24h', userId?: number): Promise<AnalyticsDashboard> =>
    (await api.get('/analytics/dashboard', { params: { period, user_id: userId } })).data,
  getSearchPerformance: async (period = '24h', userId?: number): Promise<SearchPerformanceStats> =>
    (await api.get('/analytics/search-performance', { params: { period, user_id: userId } })).data,
  getQueryPopularity: async (period = '7d', limit = 20, userId?: number): Promise<PopularQueries> =>
    (await api.get('/analytics/query-popularity', { params: { period, limit, user_id: userId } })).data,
  getResponseTimeHistogram: async (period = '24h', buckets = 20) =>
    (await api.get('/analytics/response-times', { params: { period, buckets } })).data,
  getIntentDistribution: async (period = '24h', userId?: number): Promise<IntentDistribution> =>
    (await api.get('/analytics/intent-distribution', { params: { period, user_id: userId } })).data,
  getTimeSeries: async (period = '24h', interval = 'hour', userId?: number): Promise<TimeSeries> =>
    (await api.get('/analytics/time-series', { params: { period, interval, user_id: userId } })).data,
  recordInteraction: async (data: { search_metric_id: number; text_chunk_id?: number; document_id?: number; interaction_type?: 'click' | 'expand' | 'copy'; result_position: number; relevance_score?: number }) =>
    (await api.post('/analytics/interactions', data)).data,
  getUsers: async (): Promise<{ users: Array<{ id: number; name: string; email: string }> }> =>
    (await api.get('/auth/users')).data,
}

export const searchApi = {
  conversationalSearch: async (query: string, sessionId?: string, entityType?: string): Promise<SearchResponse> => (await api.post('/conversation/search', { query, session_id: sessionId, entity_type: entityType, include_summary: true })).data,

  /**
   * Stream a conversational search via SSE.
   * Calls onMetadata once with the search results, onToken for each LLM fragment,
   * and onDone when the summary is complete.
   */
  streamConversationalSearch: async (
    query: string,
    callbacks: {
      onStatus: (phase: string) => void
      onMetadata: (data: SearchResponse) => void
      onToken: (token: string) => void
      onDone: (data: { summary?: string }) => void
      onDebug: (data: DebugInfo) => void
      onError: (error: string) => void
    },
    sessionId?: string,
    abortSignal?: AbortSignal,
  ): Promise<void> => {
    const token = localStorage.getItem('auth_token')
    const baseUrl = `${import.meta.env.VITE_API_URL}/api/v1`

    const res = await fetch(`${baseUrl}/conversation/search/stream`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'text/event-stream',
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
      body: JSON.stringify({
        query,
        session_id: sessionId,
      }),
      signal: abortSignal,
    })

    if (!res.ok) {
      callbacks.onError(`Search failed with status ${res.status}`)
      return
    }

    const reader = res.body?.getReader()
    if (!reader) {
      callbacks.onError('No response body')
      return
    }

    const decoder = new TextDecoder()
    let buffer = ''

    try {
      while (true) {
        const { done, value } = await reader.read()
        if (done) break

        buffer += decoder.decode(value, { stream: true })
        const parts = buffer.split('\n\n')
        buffer = parts.pop() ?? ''

        for (const part of parts) {
          let eventType = 'message'
          let eventData = ''

          for (const line of part.split('\n')) {
            if (line.startsWith('event: ')) {
              eventType = line.slice(7).trim()
            } else if (line.startsWith('data: ')) {
              eventData += line.slice(6)
            }
          }

          if (!eventData) continue

          try {
            const parsed = JSON.parse(eventData)
            switch (eventType) {
              case 'status':
                callbacks.onStatus(parsed.phase)
                break
              case 'metadata':
                callbacks.onMetadata(parsed as SearchResponse)
                break
              case 'token':
                callbacks.onToken(parsed.content)
                break
              case 'done':
                callbacks.onDone(parsed)
                break
              case 'error':
                callbacks.onError(parsed.message || 'Stream error')
                break
              case 'debug':
                callbacks.onDebug(parsed)
                break
            }
          } catch {
          }
        }
      }
    } finally {
      reader.releaseLock()
    }
  },

  clearConversation: async (sessionId?: string): Promise<void> => { await api.post('/conversation/clear', { session_id: sessionId }) },
  getEntities: async (): Promise<EntityListResponse> => (await api.get('/entities')).data,
  getEntityConfig: async (entityType: string) => (await api.get(`/entities/${entityType}`)).data,
  getStats: async () => (await api.get('/stats')).data,
  getDocumentStats: async () => (await api.get('/documents-stats')).data,
}

export const adminApi = {
  getLlmSettings: async () => (await api.get('/admin/llm-settings')).data,
  updateLlmSettings: async (mode: 'local' | 'cloud') => (await api.put('/admin/llm-settings', { mode })).data,
  getOllamaStatus: async () => (await api.get('/admin/ollama/status')).data,
  warmUpModel: async (model: string) => (await api.post('/admin/ollama/warm', { model })).data,
  unloadModel: async (model: string) => (await api.post('/admin/ollama/unload', { model })).data,
  getChunkingSettings: async () => (await api.get('/admin/chunking-settings')).data,
  updateChunkingStrategy: async (strategy: 'structural' | 'semantic') =>
    (await api.put('/admin/chunking-settings', { strategy })).data,
  uploadDocument: async (file: File, force = false) => {
    const formData = new FormData()
    formData.append('file', file)
    if (force) formData.append('force', '1')
    return (await api.post('/admin/documents/upload', formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
      timeout: 300_000,
    })).data
  },
  getSupportedTypes: async () =>
    (await api.get('/admin/documents/supported-types')).data,

  getDocumentChunks: async (documentId: number) =>
    (await api.get(`/admin/documents/${documentId}/chunks`)).data,

  getProcessingTasks: async (limit = 20) =>
    (await api.get('/admin/processing-tasks', { params: { limit } })).data,

  getPromptSettings: async () => (await api.get('/admin/prompt-settings')).data,
  updatePromptSetting: async (key: string, value: string) =>
    (await api.put('/admin/prompt-settings', { key, value })).data,
  resetPromptSetting: async (key: string) =>
    (await api.post('/admin/prompt-settings/reset', { key })).data,
}