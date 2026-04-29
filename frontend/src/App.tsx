
import { useState, useEffect, useRef } from 'react'
import type { FormEvent } from 'react'
import { MainLayout } from '@/components/layout/MainLayout'
import { ConversationPanel } from '@/components/layout/ConversationPanel'
import { searchApi, conversationApi } from '@/services/api'
import { useConversations } from '@/hooks'
import { useAuth } from '@/hooks/useAuth'
import { LoginScreen } from '@/components/auth/LoginScreen'
import type { SearchResponse, Message } from '@/types/api'
import { AnalyticsDashboard } from '@/components/analytics/AnalyticsDashboard'
import { AdminPanel } from '@/components/admin/AdminPanel'

const formatResponse = (r: SearchResponse): string => {
  if (r.message) return r.message
  if (r.summary) return r.summary
  const n = r.total_results
  const map: Record<string, string> = {
    document_search_results: n > 0 ? `Found ${n} document(s).` : 'No documents found.',
    refined_results: `Found ${n} result(s) with refined search.`
  }
  return map[r.type] || (n > 0 ? `Found ${n} result(s).` : 'No results found.')
}

export default function App() {
  const { user, loading: authLoading, login, logout, isAuthenticated } = useAuth()
  const abortControllerRef = useRef<AbortController | null>(null)
  const [query, setQuery] = useState('')
  const [messages, setMessages] = useState<Message[]>([])
  const [loading, setLoading] = useState(false)
  const [sessionId, setSessionId] = useState<string>()
  const [currentConversationId, setCurrentConversationId] = useState<number>()
  const [activeView, setActiveView] = useState<'chat' | 'analytics' | 'admin'>('chat')
  const [streamStatus, setStreamStatus] = useState<string | null>(null)
  const {
    createNewConversation,
    updateConversation,
    touchConversation,
    conversations,
    deleteConversation,
    loaded,
  } = useConversations(isAuthenticated)
  useEffect(() => {
    if (!loaded || currentConversationId) return

    if (conversations.length > 0) {
      selectConversation(conversations[0].id)
    } else {
      handleNewConversation()
    }
  }, [loaded])

  useEffect(() => {
    setMessages([])
    setCurrentConversationId(undefined)
    setQuery('')
    setSessionId(undefined)
    setActiveView('chat')
  }, [user?.id])

  
  const selectConversation = async (id: number) => {
    try {
      const data = await conversationApi.show(id)
      setMessages(data.messages)
      setCurrentConversationId(id)
    } catch (err) {
      console.error('[App] Failed to load conversation:', err)
      setMessages([])
      setCurrentConversationId(id)
    }
  }

  const handleNewConversation = async () => {
    try {
      const conv = await createNewConversation()
      searchApi.clearConversation(sessionId).catch(() => {})
      setSessionId(undefined)
      setMessages([])
      setCurrentConversationId(conv.id)
    } catch (err) {
      console.error('[App] Failed to create conversation:', err)
    }
  }

  const handleSelectConversation = async (id: number) => {
    if (currentConversationId === id) return
    searchApi.clearConversation(sessionId).catch(() => {})
    setSessionId(undefined)
    await selectConversation(id)
  }

  const handleDeleteConversation = async (id: number) => {
    try {
      await deleteConversation(id)

      if (currentConversationId === id) {
        const remaining = conversations.filter(c => c.id !== id)
        if (remaining.length > 0) {
          await selectConversation(remaining[0].id)
        } else {
          await handleNewConversation()
        }
      }
    } catch (err) {
      console.error('[App] Failed to delete conversation:', err)
    }
  }

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault()
    if (!query.trim() || loading || !currentConversationId) return
    abortControllerRef.current?.abort()
    const abortController = new AbortController()
    abortControllerRef.current = abortController

    const userContent = query
    const ts = new Date().toISOString()
    const userMessage: Message = { type: 'user', content: userContent, metadata: { timestamp: ts } }

    setMessages(prev => [...prev, userMessage])
    setLoading(true)
    setQuery('')

    try {
      await conversationApi.addMessage(currentConversationId, {
        role: 'user',
        content: userContent,
        metadata: { timestamp: ts },
      })

      let searchResponse: SearchResponse | null = null
      let streamedSummary = ''
      const placeholderIndex = messages.length + 1 // +1 for the user message we just added

      await searchApi.streamConversationalSearch(
        userContent,
        {
          onStatus: (phase) => {
            setStreamStatus(phase)
          },
          onMetadata: (metadata) => {
            setStreamStatus(null)
            searchResponse = metadata

            if (metadata.session_id && metadata.session_id !== sessionId) {
              setSessionId(metadata.session_id)
            }

            const assistantMessage: Message = {
              type: 'assistant',
              content: formatResponse(metadata),
              metadata: { turn: metadata.conversation_turn, intent: metadata.intent, timestamp: new Date().toISOString() },
              searchResponse: metadata,
            }
            setMessages(prev => [...prev.slice(0, placeholderIndex), assistantMessage])
          },
          onToken: (token) => {
            streamedSummary += token
            setMessages(prev => {
              const updated = [...prev]
              const msg = updated[placeholderIndex]
              if (msg && msg.type === 'assistant') {
                updated[placeholderIndex] = {
                  ...msg,
                  content: streamedSummary,
                }
              }
              return updated
            })
          },
          onDone: async (data) => {
            const finalSummary = data.summary || streamedSummary
            const finalResponse = searchResponse ? { ...searchResponse, summary: finalSummary } : null
            setMessages(prev => {
              const updated = [...prev]
              const msg = updated[placeholderIndex]
              if (msg && msg.type === 'assistant') {
                updated[placeholderIndex] = {
                  ...msg,
                  content: finalSummary || msg.content,
                  searchResponse: finalResponse || msg.searchResponse,
                }
              }
              return updated
            })
            if (currentConversationId && searchResponse) {
              const persistContent = finalSummary || formatResponse(searchResponse)
              try {
                await conversationApi.addMessage(currentConversationId, {
                  role: 'assistant',
                  content: persistContent,
                  metadata: { turn: searchResponse.conversation_turn, intent: searchResponse.intent, timestamp: new Date().toISOString() },
                  search_response: (finalResponse ?? searchResponse) as unknown as Record<string, unknown>,
                })

                const conv = conversations.find(c => c.id === currentConversationId)
                if (conv?.title === 'New Conversation') {
                  await updateConversation(currentConversationId, userContent.substring(0, 50))
                }

                touchConversation(currentConversationId, {
                  lastMessage: persistContent.substring(0, 100),
                  timestamp: new Date().toISOString(),
                  messageCount: messages.length + 2,
                  ...(conv?.title === 'New Conversation' ? { title: userContent.substring(0, 50) } : {}),
                })
              } catch (err) {
                console.error('[App] Failed to persist assistant message:', err)
              }
            }

            setLoading(false)
          },
          onDebug: (debugData) => {
            setMessages(prev => {
              const updated = [...prev]
              const msg = updated[placeholderIndex]
              if (msg && msg.type === 'assistant') {
                updated[placeholderIndex] = { ...msg, debug: debugData }
              }
              return updated
            })
          },
          onError: (errorMsg) => {
            console.error('[App] Stream error:', errorMsg)
            setMessages(prev => [...prev, {
              type: 'assistant',
              content: `Error: ${errorMsg}`,
            }])
            setLoading(false)
          },
        },
        sessionId,
        abortController.signal,
      )
    } catch (err) {
      if ((err as Error).name === 'AbortError') return
      console.error('[App] Search error:', err)
      setMessages(prev => [...prev, {
        type: 'assistant',
        content: `Error: ${err instanceof Error ? err.message : 'Unknown error'}`,
      }])
      setLoading(false)
    }
  }
  if (authLoading) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-background">
        <div className="text-sm text-muted-foreground">Loading...</div>
      </div>
    )
  }

  if (!isAuthenticated) {
    return <LoginScreen onLogin={login} />
  }

  return (
    <MainLayout
      currentConversationId={currentConversationId}
      onSelectConversation={handleSelectConversation}
      onNewConversation={handleNewConversation}
      activeView={activeView}
      onChangeView={setActiveView}
      conversations={conversations}
      onDeleteConversation={handleDeleteConversation}
      userName={user?.name}
      onLogout={logout}
    >
      {activeView === 'analytics' ? (
        <AnalyticsDashboard />
      ) : activeView === 'admin' ? (
        <AdminPanel />
      ) : (
        <ConversationPanel
          messages={messages}
          loading={loading}
          query={query}
          onQueryChange={setQuery}
          onSubmit={handleSubmit}
          streamStatus={streamStatus}
        />
      )}
    </MainLayout>
  )
}