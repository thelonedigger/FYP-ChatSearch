import { useState, useEffect, useCallback } from 'react'
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Skeleton } from '@/components/ui/skeleton'
import { Heading2, SmallText, Caption } from '@/components/common/Typography'
import { Stack } from '@/components/common/Container'
import { RefreshCw, MessageSquareText, RotateCcw, Save, Check } from 'lucide-react'
import { adminApi } from '@/services/api'
import { ScrollArea } from '@/components/ui/scroll-area'

interface PromptSetting {
  key: string
  label: string
  description: string
  placeholders: string[]
  value: string
  is_default: boolean
}

interface PromptSettings {
  prompts: PromptSetting[]
}

const TabSkeleton = () => (
  <div className="p-6 space-y-6">
    <Skeleton className="h-8 w-48" />
    <Skeleton className="h-64 rounded-lg" />
  </div>
)

export function PromptSettingsTab() {
  const [settings, setSettings] = useState<PromptSettings | null>(null)
  const [drafts, setDrafts] = useState<Record<string, string>>({})
  const [loading, setLoading] = useState(true)
  const [savingPrompt, setSavingPrompt] = useState<string | null>(null)
  const [savedPrompt, setSavedPrompt] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)

  const fetchSettings = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      const data = await adminApi.getPromptSettings()
      setSettings(data)
      setDrafts(Object.fromEntries(data.prompts.map((p: PromptSetting) => [p.key, p.value])))
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Failed to load prompt settings')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { fetchSettings() }, [fetchSettings])

  const handleSave = async (key: string) => {
    const value = drafts[key]
    if (!value) return
    setSavingPrompt(key)
    setError(null)
    try {
      const result = await adminApi.updatePromptSetting(key, value)
      setSettings(prev => prev ? {
        ...prev,
        prompts: prev.prompts.map(p => p.key === key ? { ...p, value: result.value, is_default: result.is_default } : p),
      } : prev)
      setSavedPrompt(key)
      setTimeout(() => setSavedPrompt(null), 2000)
    } catch (e: any) {
      setError(e?.response?.data?.error || e?.message || 'Failed to save prompt')
    } finally {
      setSavingPrompt(null)
    }
  }

  const handleReset = async (key: string) => {
    setSavingPrompt(key)
    setError(null)
    try {
      const result = await adminApi.resetPromptSetting(key)
      setDrafts(prev => ({ ...prev, [key]: result.value }))
      setSettings(prev => prev ? {
        ...prev,
        prompts: prev.prompts.map(p => p.key === key ? { ...p, value: result.value, is_default: true } : p),
      } : prev)
      setSavedPrompt(key)
      setTimeout(() => setSavedPrompt(null), 2000)
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Failed to reset prompt')
    } finally {
      setSavingPrompt(null)
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
          <Heading2>Prompt Management</Heading2>
          <Button variant="ghost" size="icon" onClick={fetchSettings} disabled={loading}>
            <RefreshCw className={`h-4 w-4 ${loading ? 'animate-spin' : ''}`} />
          </Button>
        </div>

        {error && <SmallText className="text-red-500">{error}</SmallText>}

        <Card>
          <CardHeader>
            <CardTitle className="text-lg flex items-center gap-2">
              <MessageSquareText className="h-5 w-5" />
              System Prompts
            </CardTitle>
          </CardHeader>
          <CardContent>
            <Stack spacing="lg">
              {settings.prompts.map(prompt => {
                const draft = drafts[prompt.key] ?? prompt.value
                const isDirty = draft !== prompt.value

                return (
                  <div key={prompt.key} className="space-y-3">
                    <div className="flex items-center justify-between">
                      <div>
                        <SmallText className="font-semibold">{prompt.label}</SmallText>
                        <Caption>{prompt.description}</Caption>
                      </div>
                      <div className="flex items-center gap-2 shrink-0">
                        {!prompt.is_default && (
                          <Button variant="ghost" size="sm" onClick={() => handleReset(prompt.key)} disabled={savingPrompt === prompt.key}>
                            <RotateCcw className="h-3.5 w-3.5 mr-1.5" />Reset
                          </Button>
                        )}
                        <Button variant="secondary" size="sm" onClick={() => handleSave(prompt.key)} disabled={!isDirty || savingPrompt === prompt.key}>
                          {savingPrompt === prompt.key ? (
                            <RefreshCw className="h-3.5 w-3.5 mr-1.5 animate-spin" />
                          ) : savedPrompt === prompt.key ? (
                            <Check className="h-3.5 w-3.5 mr-1.5" />
                          ) : (
                            <Save className="h-3.5 w-3.5 mr-1.5" />
                          )}
                          {savedPrompt === prompt.key ? 'Saved' : 'Save'}
                        </Button>
                      </div>
                    </div>

                    <textarea
                      value={draft}
                      onChange={e => setDrafts(prev => ({ ...prev, [prompt.key]: e.target.value }))}
                      rows={prompt.key === 'prompt_nlg_template' ? 10 : 3}
                      className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm font-mono ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 resize-y"
                    />

                    {prompt.placeholders.length > 0 && (
                      <div className="flex items-center gap-2 flex-wrap">
                        <Caption className="shrink-0">Required placeholders:</Caption>
                        {prompt.placeholders.map(p => (
                          <Badge
                            key={p}
                            variant={draft.includes(p) ? 'default' : 'secondary'}
                            className="text-xs font-mono"
                          >
                            {p}
                            {!draft.includes(p) && ' (missing)'}
                          </Badge>
                        ))}
                      </div>
                    )}

                    {isDirty && (
                      <Caption className="text-amber-600">Unsaved changes</Caption>
                    )}
                  </div>
                )
              })}
            </Stack>
          </CardContent>
        </Card>
      </div>
    </ScrollArea>
  )
}