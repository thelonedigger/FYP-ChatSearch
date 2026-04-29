import { useState, useEffect, useCallback } from 'react'
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Skeleton } from '@/components/ui/skeleton'
import { Heading2, SmallText, Caption } from '@/components/common/Typography'
import { Stack } from '@/components/common/Container'
import { RefreshCw, Layers } from 'lucide-react'
import { adminApi } from '@/services/api'
import { ScrollArea } from '@/components/ui/scroll-area'
import { DocumentUpload } from '../DocumentUpload'

interface ChunkingStrategy {
  key: string
  label: string
  description: string
}

interface ChunkingSettings {
  strategy: string
  available_strategies: ChunkingStrategy[]
  config: {
    chunk_size: number
    min_chunk_size: number
    overlap_sentences: number
    semantic_threshold: number
  }
}

const TabSkeleton = () => (
  <div className="p-6 space-y-6">
    <Skeleton className="h-8 w-48" />
    <Skeleton className="h-48 rounded-lg" />
    <Skeleton className="h-64 rounded-lg" />
  </div>
)

export function ChunkingSettingsTab() {
  const [settings, setSettings] = useState<ChunkingSettings | null>(null)
  const [loading, setLoading] = useState(true)
  const [switching, setSwitching] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const fetchSettings = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      setSettings(await adminApi.getChunkingSettings())
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Failed to load chunking settings')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { fetchSettings() }, [fetchSettings])

  const handleStrategySwitch = async (newStrategy: string) => {
    if (!settings || settings.strategy === newStrategy) return
    setSwitching(true)
    setError(null)
    try {
      await adminApi.updateChunkingStrategy(newStrategy as 'structural' | 'semantic')
      setSettings(prev => prev ? { ...prev, strategy: newStrategy } : prev)
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Failed to switch chunking strategy')
    } finally {
      setSwitching(false)
    }
  }

  if (loading && !settings) return <TabSkeleton />
  if (error && !settings) return (
    <Card className="m-6 p-8 text-center">
      <SmallText className="text-red-500 mb-4">{error}</SmallText>
      <Button onClick={fetchSettings} variant="secondary"><RefreshCw className="h-4 w-4 mr-2" />Retry</Button>
    </Card>
  )
  if (!settings) return null

  return (
    <ScrollArea className="h-full">
      <div className="p-6 space-y-6">
        <div className="flex items-center justify-between">
          <Heading2>Document Chunking</Heading2>
          <Button variant="ghost" size="icon" onClick={fetchSettings} disabled={loading}>
            <RefreshCw className={`h-4 w-4 ${loading ? 'animate-spin' : ''}`} />
          </Button>
        </div>

        {error && <SmallText className="text-red-500">{error}</SmallText>}

        {}
        <Card>
          <CardHeader>
            <CardTitle className="text-lg flex items-center gap-2">
              <Layers className="h-5 w-5" />
              Chunking Strategy
            </CardTitle>
          </CardHeader>
          <CardContent>
            <Stack spacing="md">
              <div>
                <SmallText>
                  Currently using <span className="font-semibold">{settings.available_strategies.find(s => s.key === settings.strategy)?.label ?? settings.strategy}</span> chunking.
                </SmallText>
                <Caption>Applies to newly processed documents. Existing chunks are not affected.</Caption>
              </div>
              <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                {settings.available_strategies.map(strat => (
                  <button
                    key={strat.key}
                    onClick={() => handleStrategySwitch(strat.key)}
                    disabled={switching}
                    className={`rounded-lg border p-4 text-left space-y-1 transition-colors ${
                      settings.strategy === strat.key
                        ? 'border-primary bg-primary/5'
                        : 'border-border hover:border-muted-foreground/30'
                    }`}
                  >
                    <div className="flex items-center justify-between">
                      <span className="font-medium text-sm">{strat.label}</span>
                      {settings.strategy === strat.key && <Badge>Active</Badge>}
                    </div>
                    <Caption>{strat.description}</Caption>
                  </button>
                ))}
              </div>
              <div className="grid grid-cols-2 md:grid-cols-4 gap-3 pt-2 border-t border-border">
                <div>
                  <Caption>Chunk Size</Caption>
                  <SmallText className="font-medium">{settings.config.chunk_size}</SmallText>
                </div>
                <div>
                  <Caption>Min Size</Caption>
                  <SmallText className="font-medium">{settings.config.min_chunk_size}</SmallText>
                </div>
                <div>
                  <Caption>Overlap Sentences</Caption>
                  <SmallText className="font-medium">{settings.config.overlap_sentences}</SmallText>
                </div>
                <div>
                  <Caption>Semantic Threshold</Caption>
                  <SmallText className="font-medium">{settings.config.semantic_threshold}</SmallText>
                </div>
              </div>
            </Stack>
          </CardContent>
        </Card>

        {}
        <DocumentUpload />
      </div>
    </ScrollArea>
  )
}