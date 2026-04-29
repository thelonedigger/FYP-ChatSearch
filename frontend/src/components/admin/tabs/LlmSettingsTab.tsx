import { useState, useEffect, useCallback } from 'react'
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Skeleton } from '@/components/ui/skeleton'
import { Heading2, SmallText, Caption } from '@/components/common/Typography'
import { Stack } from '@/components/common/Container'
import { RefreshCw, Server, Cloud, ArrowRightLeft, Flame, Power, CircleDot } from 'lucide-react'
import { adminApi } from '@/services/api'
import { ScrollArea } from '@/components/ui/scroll-area'

interface ProfileDetail {
  provider: string
  model: string
  timeout: number
}

interface OllamaModel {
  name: string
  loaded: boolean
  size_vram: number | null
  expires_at: string | null
}

interface OllamaStatus {
  available: boolean
  models: OllamaModel[]
}

interface LlmSettings {
  mode: 'local' | 'cloud'
  profiles: {
    local: { llm: ProfileDetail; intent_classification: ProfileDetail }
    cloud: { llm: ProfileDetail; intent_classification: ProfileDetail }
  }
  ollama: OllamaStatus
}

const formatBytes = (bytes: number): string => {
  if (bytes === 0) return '0 B'
  const units = ['B', 'KB', 'MB', 'GB']
  const i = Math.floor(Math.log(bytes) / Math.log(1024))
  return `${(bytes / Math.pow(1024, i)).toFixed(1)} ${units[i]}`
}

const ProfileCard = ({ label, icon: Icon, profile, active }: {
  label: string
  icon: typeof Server
  profile: { llm: ProfileDetail; intent_classification: ProfileDetail }
  active: boolean
}) => (
  <div className={`rounded-lg border p-4 space-y-3 transition-colors ${active ? 'border-primary bg-primary/5' : 'border-border opacity-60'}`}>
    <div className="flex items-center justify-between">
      <div className="flex items-center gap-2">
        <Icon className="h-4 w-4" />
        <span className="font-medium text-sm">{label}</span>
      </div>
      {active && <Badge>Active</Badge>}
    </div>
    <div className="space-y-2">
      <div>
        <Caption>Answer Generation</Caption>
        <SmallText>{profile.llm.provider} / {profile.llm.model}</SmallText>
        <Caption>Timeout: {profile.llm.timeout}s</Caption>
      </div>
      <div>
        <Caption>Intent Classification</Caption>
        <SmallText>{profile.intent_classification.provider} / {profile.intent_classification.model}</SmallText>
        <Caption>Timeout: {profile.intent_classification.timeout}s</Caption>
      </div>
    </div>
  </div>
)

const TabSkeleton = () => (
  <div className="p-6 space-y-6">
    <Skeleton className="h-8 w-48" />
    <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <Skeleton className="h-64 rounded-lg" />
      <Skeleton className="h-48 rounded-lg" />
    </div>
  </div>
)

export function LlmSettingsTab() {
  const [settings, setSettings] = useState<LlmSettings | null>(null)
  const [loading, setLoading] = useState(true)
  const [switching, setSwitching] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [modelAction, setModelAction] = useState<string | null>(null)

  const fetchSettings = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      setSettings(await adminApi.getLlmSettings())
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Failed to load LLM settings')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { fetchSettings() }, [fetchSettings])

  const handleSwitch = async () => {
    if (!settings) return
    const newMode = settings.mode === 'local' ? 'cloud' : 'local'
    setSwitching(true)
    setError(null)
    try {
      await adminApi.updateLlmSettings(newMode)
      setSettings(prev => prev ? { ...prev, mode: newMode } : prev)
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Failed to switch mode')
    } finally {
      setSwitching(false)
    }
  }

  const handleWarmUp = async (model: string) => {
    setModelAction(model)
    setError(null)
    try {
      await adminApi.warmUpModel(model)
      const status = await adminApi.getOllamaStatus()
      setSettings(prev => prev ? { ...prev, ollama: status } : prev)
    } catch (e) {
      setError(e instanceof Error ? e.message : `Failed to warm up ${model}`)
    } finally {
      setModelAction(null)
    }
  }

  const handleUnload = async (model: string) => {
    setModelAction(model)
    setError(null)
    try {
      await adminApi.unloadModel(model)
      const status = await adminApi.getOllamaStatus()
      setSettings(prev => prev ? { ...prev, ollama: status } : prev)
    } catch (e) {
      setError(e instanceof Error ? e.message : `Failed to unload ${model}`)
    } finally {
      setModelAction(null)
    }
  }

  const handleWarmAll = async () => {
    if (!settings) return
    for (const model of settings.ollama.models.filter(m => !m.loaded)) {
      await handleWarmUp(model.name)
    }
  }

  const handleUnloadAll = async () => {
    if (!settings) return
    for (const model of settings.ollama.models.filter(m => m.loaded)) {
      await handleUnload(model.name)
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

  const allLoaded = settings.ollama.models.every(m => m.loaded)
  const anyLoaded = settings.ollama.models.some(m => m.loaded)

  return (
    <ScrollArea className="h-full">
      <div className="p-6 space-y-6">
        <div className="flex items-center justify-between">
          <Heading2>LLM Settings</Heading2>
          <Button variant="ghost" size="icon" onClick={fetchSettings} disabled={loading}>
            <RefreshCw className={`h-4 w-4 ${loading ? 'animate-spin' : ''}`} />
          </Button>
        </div>

        {error && <SmallText className="text-red-500">{error}</SmallText>}

        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
          {}
          <Card>
            <CardHeader>
              <CardTitle className="text-lg flex items-center gap-2">
                <ArrowRightLeft className="h-5 w-5" />
                LLM Inference Mode
              </CardTitle>
            </CardHeader>
            <CardContent>
              <Stack spacing="md">
                <div className="flex items-center justify-between">
                  <div>
                    <SmallText>
                      Currently using <span className="font-semibold">{settings.mode === 'local' ? 'Local (Ollama)' : 'Cloud (OpenAI)'}</span> inference.
                    </SmallText>
                    <Caption>Switching applies globally to all LLM services immediately.</Caption>
                  </div>
                  <Button onClick={handleSwitch} disabled={switching} variant="secondary" className="shrink-0">
                    {switching ? <RefreshCw className="h-4 w-4 mr-2 animate-spin" /> : <ArrowRightLeft className="h-4 w-4 mr-2" />}
                    Switch to {settings.mode === 'local' ? 'Cloud' : 'Local'}
                  </Button>
                </div>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <ProfileCard label="Local (Ollama)" icon={Server} profile={settings.profiles.local} active={settings.mode === 'local'} />
                  <ProfileCard label="Cloud (OpenAI)" icon={Cloud} profile={settings.profiles.cloud} active={settings.mode === 'cloud'} />
                </div>
              </Stack>
            </CardContent>
          </Card>

          {}
          <Card>
            <CardHeader>
              <div className="flex items-center justify-between">
                <CardTitle className="text-lg flex items-center gap-2">
                  <Server className="h-5 w-5" />
                  Ollama Model Management
                </CardTitle>
                <div className="flex items-center gap-2">
                  {settings.ollama.available ? (
                    <Badge variant="default" className="gap-1"><CircleDot className="h-3 w-3" />Connected</Badge>
                  ) : (
                    <Badge variant="secondary" className="gap-1 bg-red-100 text-red-800"><CircleDot className="h-3 w-3" />Unreachable</Badge>
                  )}
                </div>
              </div>
            </CardHeader>
            <CardContent>
              {!settings.ollama.available ? (
                <SmallText className="text-muted-foreground">
                  Cannot reach the Ollama server. Make sure the container is running.
                </SmallText>
              ) : (
                <Stack spacing="md">
                  <div className="flex items-center justify-between">
                    <Caption>
                      {settings.ollama.models.filter(m => m.loaded).length} of {settings.ollama.models.length} models loaded in memory.
                    </Caption>
                    <div className="flex gap-2">
                      {!allLoaded && (
                        <Button variant="secondary" size="sm" onClick={handleWarmAll} disabled={!!modelAction}>
                          <Flame className="h-3.5 w-3.5 mr-1.5" />Warm All
                        </Button>
                      )}
                      {anyLoaded && (
                        <Button variant="ghost" size="sm" onClick={handleUnloadAll} disabled={!!modelAction}>
                          <Power className="h-3.5 w-3.5 mr-1.5" />Unload All
                        </Button>
                      )}
                    </div>
                  </div>

                  <div className="space-y-2">
                    {settings.ollama.models.map(model => (
                      <div key={model.name} className="flex items-center justify-between rounded-lg border border-border p-3">
                        <div className="flex items-center gap-3">
                          <div className={`h-2.5 w-2.5 rounded-full ${model.loaded ? 'bg-green-500' : 'bg-muted-foreground/30'}`} />
                          <div>
                            <SmallText className="font-medium">{model.name}</SmallText>
                            <Caption>
                              {model.loaded
                                ? `Loaded${model.size_vram ? ` · ${formatBytes(model.size_vram)} VRAM` : ''}`
                                : 'Not loaded'}
                            </Caption>
                          </div>
                        </div>
                        <div>
                          {model.loaded ? (
                            <Button variant="ghost" size="sm" onClick={() => handleUnload(model.name)} disabled={modelAction === model.name}>
                              {modelAction === model.name ? <RefreshCw className="h-3.5 w-3.5 mr-1.5 animate-spin" /> : <Power className="h-3.5 w-3.5 mr-1.5" />}
                              Unload
                            </Button>
                          ) : (
                            <Button variant="secondary" size="sm" onClick={() => handleWarmUp(model.name)} disabled={modelAction === model.name}>
                              {modelAction === model.name ? <RefreshCw className="h-3.5 w-3.5 mr-1.5 animate-spin" /> : <Flame className="h-3.5 w-3.5 mr-1.5" />}
                              Warm Up
                            </Button>
                          )}
                        </div>
                      </div>
                    ))}
                  </div>
                </Stack>
              )}
            </CardContent>
          </Card>
        </div>
      </div>
    </ScrollArea>
  )
}