import { useState, useEffect, useCallback } from 'react'
import { searchApi, analyticsApi, conversationApi } from '@/services/api'
import type { SystemStats, Conversation } from '@/types/api'
import type { AnalyticsDashboard } from '@/services/api'

export function useStats() {
  const [stats, setStats] = useState<SystemStats | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  const loadStats = async () => {
    try { setLoading(true); setStats(await searchApi.getStats()) }
    catch { setError('Failed to load stats') }
    finally { setLoading(false) }
  }

  useEffect(() => { loadStats() }, [])
  return { stats, loading, error, refreshStats: loadStats }
}

export function useConversations(enabled: boolean = true) {
  const [conversations, setConversations] = useState<Conversation[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [loaded, setLoaded] = useState(false)

  const loadConversations = useCallback(async () => {
    if (!enabled) return   // <-- Add this guard
    try {
      setLoading(true)
      const data = await conversationApi.list()
      setConversations(data)
    } catch (err) {
      console.error('[useConversations] Failed to load:', err)
      setError('Failed to load conversations')
    } finally {
      setLoading(false)
      setLoaded(true)
    }
  }, [enabled])

  useEffect(() => { loadConversations() }, [loadConversations])

  useEffect(() => {
    if (!enabled) {
      setConversations([])
      setLoaded(false)
    }
  }, [enabled])

  const createNewConversation = useCallback(async (): Promise<Conversation> => {
    const conv = await conversationApi.create()
    setConversations(prev => [conv, ...prev])
    return conv
  }, [])

  const updateConversation = useCallback(async (id: number, title: string) => {
    await conversationApi.update(id, title)
    setConversations(prev =>
      prev.map(c => c.id === id ? { ...c, title } : c)
    )
  }, [])

  const deleteConversation = useCallback(async (id: number) => {
    await conversationApi.destroy(id)
    setConversations(prev => prev.filter(c => c.id !== id))
  }, [])

  /**
   * Optimistically move a conversation to the top and update its preview.
   * Called after a new message is persisted so the sidebar reflects activity.
   */
  const touchConversation = useCallback((id: number, patch: Partial<Conversation>) => {
    setConversations(prev => {
      const target = prev.find(c => c.id === id)
      if (!target) return prev
      const updated = { ...target, ...patch }
      return [updated, ...prev.filter(c => c.id !== id)]
    })
  }, [])

  return {
    conversations,
    loading,
    loaded,
    error,
    createNewConversation,
    updateConversation,
    deleteConversation,
    touchConversation,
    refreshConversations: loadConversations,
  }
}

export function useAnalytics(initialPeriod = '24h') {
  const [data, setData] = useState<AnalyticsDashboard | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [period, setPeriod] = useState(initialPeriod)
  const [userId, setUserId] = useState<number | undefined>(undefined)
  const [lastRefreshed, setLastRefreshed] = useState<Date | null>(null)

  const fetchDashboard = useCallback(async () => {
    setLoading(true); setError(null)
    try {
      setData(await analyticsApi.getDashboard(period, userId))
      setLastRefreshed(new Date())
    }
    catch (e) { setError(e instanceof Error ? e.message : 'Failed to load analytics') }
    finally { setLoading(false) }
  }, [period, userId])

  useEffect(() => { fetchDashboard() }, [fetchDashboard])

  return {
    data, loading, error, period, userId, lastRefreshed,
    changePeriod: setPeriod,
    changeUser: setUserId,
    refresh: fetchDashboard,
  }
}