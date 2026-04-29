import { useState, useEffect, useRef } from 'react'
import { useAnalytics } from '@/hooks'
import { analyticsApi } from '@/services/api'
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Skeleton } from '@/components/ui/skeleton'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { StatCard } from '@/components/common/StatCard'
import { Heading2, Heading4, SmallText, Caption } from '@/components/common/Typography'
import { Stack } from '@/components/common/Container'
import { Search, Clock, TrendingUp, MousePointerClick, RefreshCw, Activity, Users, Timer, Inbox } from 'lucide-react'
import { formatters } from '@/lib/formatters'
import { ScrollArea } from '@/components/ui/scroll-area'

const PERIODS = [
  { value: '1h', label: 'Last Hour' }, { value: '6h', label: 'Last 6 Hours' },
  { value: '12h', label: 'Last 12 Hours' }, { value: '24h', label: 'Last 24 Hours' },
  { value: '7d', label: 'Last 7 Days' }, { value: '30d', label: 'Last 30 Days' },
]

const AUTO_REFRESH_OPTIONS = [
  { value: 0, label: 'Off' },
  { value: 15, label: '15s' },
  { value: 30, label: '30s' },
  { value: 60, label: '1m' },
]

const TimingRow = ({ label, value, highlight }: { label: string; value: number | null; highlight?: boolean }) =>
  value == null ? null : (
    <div className={`flex justify-between items-center ${highlight ? 'font-medium' : ''}`}>
      <SmallText>{label}</SmallText>
      <Badge variant={highlight ? 'default' : 'outline'}>{value.toFixed(1)}ms</Badge>
    </div>
  )

const EmptyState = ({ icon: Icon, message }: { icon: typeof Inbox; message: string }) => (
  <div className="flex flex-col items-center justify-center py-8 text-muted-foreground">
    <Icon className="h-8 w-8 mb-2 opacity-40" />
    <SmallText>{message}</SmallText>
  </div>
)

const AnalyticsSkeleton = () => (
  <div className="p-6 space-y-6">
    <div className="flex justify-between"><Skeleton className="h-8 w-48" /><Skeleton className="h-10 w-40" /></div>
    <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">{[1, 2, 3, 4].map(i => <Skeleton key={i} className="h-24 rounded-lg" />)}</div>
    <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">{[1, 2, 3, 4].map(i => <Skeleton key={i} className="h-64 rounded-lg" />)}</div>
  </div>
)


function TimeSeriesChart({ data }: { data: Array<{ timestamp: string; search_count: number; avg_time_ms: number }> }) {
  if (!data.length) return <EmptyState icon={Inbox} message="No activity in this period" />

  const maxCount = Math.max(...data.map(d => d.search_count), 1)
  const barWidth = Math.max(4, Math.min(24, Math.floor(560 / data.length) - 2))

  return (
    <div className="space-y-3">
      <div className="flex items-end gap-[2px] h-32 w-full overflow-x-auto">
        {data.map((d, i) => {
          const height = Math.max(2, (d.search_count / maxCount) * 100)
          return (
            <div key={i} className="flex flex-col items-center flex-shrink-0 group relative" style={{ width: barWidth }}>
              <div
                className="bg-primary/70 hover:bg-primary rounded-t transition-colors w-full"
                style={{ height: `${height}%` }}
                title={`${d.search_count} searches · ${d.avg_time_ms.toFixed(0)}ms avg`}
              />
            </div>
          )
        })}
      </div>
      <div className="flex justify-between">
        <Caption>{new Date(data[0].timestamp).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', timeZone: 'UTC' })}</Caption>
        <Caption>{data.reduce((s, d) => s + d.search_count, 0)} total searches</Caption>
        <Caption>{new Date(data[data.length - 1].timestamp).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', timeZone: 'UTC' })}</Caption>
      </div>
    </div>
  )
}

export function AnalyticsDashboard() {
  const { data, loading, error, period, userId, lastRefreshed, changePeriod, changeUser, refresh } = useAnalytics('24h')
  const [users, setUsers] = useState<Array<{ id: number; name: string; email: string }>>([])
  const [autoRefresh, setAutoRefresh] = useState(0)
  const intervalRef = useRef<ReturnType<typeof setInterval> | null>(null)
  useEffect(() => {
    analyticsApi.getUsers().then(r => setUsers(r.users)).catch(() => {})
  }, [])
  useEffect(() => {
    if (intervalRef.current) clearInterval(intervalRef.current)
    if (autoRefresh > 0) {
      intervalRef.current = setInterval(refresh, autoRefresh * 1000)
    }
    return () => { if (intervalRef.current) clearInterval(intervalRef.current) }
  }, [autoRefresh, refresh])

  if (loading && !data) return <AnalyticsSkeleton />
  if (error && !data) return (
    <Card className="m-6 p-8 text-center">
      <SmallText className="text-red-500 mb-4">{error}</SmallText>
      <Button onClick={refresh} variant="secondary"><RefreshCw className="h-4 w-4 mr-2" />Retry</Button>
    </Card>
  )
  if (!data) return null

  const { performance: p, intent_distribution: intents, interactions: inter, popular_queries: pop, time_series: ts } = data
  const isEmpty = p.total_searches === 0

  return (
    <ScrollArea className="h-full">
      <div className="p-6 space-y-6">
        {}
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <Heading2>Performance Analytics</Heading2>
            {lastRefreshed && (
              <Caption className="mt-1">
                Last refreshed {formatters.relativeTime(lastRefreshed)}
                {autoRefresh > 0 && <span className="ml-1.5 text-green-600">(live)</span>}
              </Caption>
            )}
          </div>

          <div className="flex items-center gap-2 flex-wrap">
            {}
            <Select value={userId?.toString() ?? 'all'} onValueChange={v => changeUser(v === 'all' ? undefined : parseInt(v))}>
              <SelectTrigger className="w-44">
                <Users className="h-3.5 w-3.5 mr-1.5 opacity-50" />
                <SelectValue placeholder="All Users" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Users</SelectItem>
                {users.map(u => <SelectItem key={u.id} value={u.id.toString()}>{u.name}</SelectItem>)}
              </SelectContent>
            </Select>

            {}
            <Select value={period} onValueChange={changePeriod}>
              <SelectTrigger className="w-40"><SelectValue /></SelectTrigger>
              <SelectContent>{PERIODS.map(o => <SelectItem key={o.value} value={o.value}>{o.label}</SelectItem>)}</SelectContent>
            </Select>

            {}
            <Select value={autoRefresh.toString()} onValueChange={v => setAutoRefresh(parseInt(v))}>
              <SelectTrigger className="w-24">
                <Timer className="h-3.5 w-3.5 mr-1 opacity-50" />
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {AUTO_REFRESH_OPTIONS.map(o => <SelectItem key={o.value} value={o.value.toString()}>{o.label}</SelectItem>)}
              </SelectContent>
            </Select>

            <Button variant="ghost" size="icon" onClick={refresh} disabled={loading}>
              <RefreshCw className={`h-4 w-4 ${loading ? 'animate-spin' : ''}`} />
            </Button>
          </div>
        </div>

        {}
        {isEmpty ? (
          <Card className="p-12 text-center">
            <div className="flex flex-col items-center gap-3">
              <Inbox className="h-12 w-12 text-muted-foreground/30" />
              <Heading4>No search activity yet</Heading4>
              <SmallText className="text-muted-foreground max-w-sm">
                {userId ? 'This user has no searches in the selected period. Try a different user or time range.' : 'No searches have been recorded in the selected period. Analytics will appear here once users start searching.'}
              </SmallText>
            </div>
          </Card>
        ) : (
          <>
            {}
            <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
              <StatCard icon={Search} label="Total Searches" value={formatters.compactNumber(p.total_searches)} />
              <StatCard icon={Clock} label="Avg Response Time" value={`${p.timing.avg_total_ms.toFixed(0)}ms`} />
              <StatCard icon={MousePointerClick} label="Click-Through Rate" value={`${inter.click_through_rate}%`} />
              <StatCard icon={TrendingUp} label="Avg Relevance" value={formatters.percentage(p.quality.avg_top_relevance)} />
            </div>

            {}
            <Card>
              <CardHeader>
                <CardTitle className="text-lg flex items-center gap-2"><Activity className="h-5 w-5" />Search Activity Over Time</CardTitle>
              </CardHeader>
              <CardContent>
                <TimeSeriesChart data={ts.data} />
              </CardContent>
            </Card>

            {}
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
              <Card>
                <CardHeader><CardTitle className="text-lg flex items-center gap-2"><Clock className="h-5 w-5" />Response Time Breakdown</CardTitle></CardHeader>
                <CardContent><Stack spacing="sm">
                  {[['Vector Search', p.timing.avg_vector_ms], ['Trigram Search', p.timing.avg_trigram_ms], ['Rank Fusion', p.timing.avg_fusion_ms], ['Reranking', p.timing.avg_rerank_ms], ['LLM Generation', p.timing.avg_llm_ms], ['Intent Classification', p.timing.avg_intent_classification_ms]].map(([l, v]) => <TimingRow key={l as string} label={l as string} value={v as number} />)}
                  <div className="border-t pt-2 mt-2">{[['P50', p.timing.p50_ms], ['P95', p.timing.p95_ms], ['P99', p.timing.p99_ms]].map(([l, v]) => <TimingRow key={l as string} label={l as string} value={v as number} highlight />)}</div>
                </Stack></CardContent>
              </Card>

              <Card>
                <CardHeader><CardTitle className="text-lg flex items-center gap-2"><Activity className="h-5 w-5" />Intent Distribution</CardTitle></CardHeader>
                <CardContent>
                  {intents.intents.length === 0 ? <EmptyState icon={Inbox} message="No intent data available" /> : (
                    <Stack spacing="sm">
                      {intents.intents.map(i => (
                        <div key={i.intent} className="flex items-center justify-between">
                          <Badge variant="secondary" className="font-mono text-xs">{formatters.intentLabel(i.intent)}</Badge>
                          <div className="flex items-center gap-4">
                            <Caption>{i.count} searches</Caption>
                            <div className="w-20 bg-muted rounded-full h-2"><div className="bg-primary h-2 rounded-full" style={{ width: `${i.percentage}%` }} /></div>
                            <SmallText className="w-12 text-right">{i.percentage}%</SmallText>
                          </div>
                        </div>
                      ))}
                    </Stack>
                  )}
                </CardContent>
              </Card>

              <Card>
                <CardHeader><CardTitle className="text-lg flex items-center gap-2"><TrendingUp className="h-5 w-5" />Popular Queries</CardTitle></CardHeader>
                <CardContent>
                  {pop.queries.length === 0 ? <EmptyState icon={Inbox} message="No query data available" /> : (
                    <Stack spacing="sm">
                      {pop.queries.slice(0, 8).map((q, i) => (
                        <div key={i} className="flex items-center justify-between py-1">
                          <SmallText className="truncate flex-1 mr-4">{q.query}</SmallText>
                          <div className="flex items-center gap-3 shrink-0"><Badge variant="outline">{q.count}x</Badge><Caption>{q.avg_time_ms.toFixed(0)}ms</Caption></div>
                        </div>
                      ))}
                    </Stack>
                  )}
                </CardContent>
              </Card>

              <Card>
                <CardHeader><CardTitle className="text-lg flex items-center gap-2"><MousePointerClick className="h-5 w-5" />Clicks by Position</CardTitle></CardHeader>
                <CardContent>
                  {inter.clicks_by_position.length === 0 ? <EmptyState icon={Inbox} message="No click data available" /> : (
                    <div className="space-y-2">
                      {inter.clicks_by_position.map(item => {
                        const max = Math.max(...inter.clicks_by_position.map(c => c.count))
                        return (
                          <div key={item.position} className="flex items-center gap-3">
                            <SmallText className="w-16">#{item.position + 1}</SmallText>
                            <div className="flex-1 bg-muted rounded-full h-4">
                              <div className="bg-primary/70 h-4 rounded-full flex items-center justify-end pr-2" style={{ width: `${Math.max(max > 0 ? (item.count / max) * 100 : 0, 10)}%` }}>
                                <span className="text-xs text-primary-foreground font-medium">{item.count}</span>
                              </div>
                            </div>
                          </div>
                        )
                      })}
                    </div>
                  )}
                </CardContent>
              </Card>
            </div>

            {}
            <Card>
              <CardHeader><CardTitle className="text-lg">Search Type Performance</CardTitle></CardHeader>
              <CardContent><div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                {Object.entries(p.by_search_type).map(([type, s]) => (
                  <Card key={type} className="bg-muted/30">
                    <CardContent className="pt-4">
                      <Heading4 className="capitalize mb-2">{type}</Heading4>
                      <div className="space-y-1">
                        {[['Searches', s.count], ['Avg Time', `${s.avg_time_ms.toFixed(0)}ms`], ['Avg Results', s.avg_results.toFixed(1)]].map(([l, v]) => (
                          <div key={l as string} className="flex justify-between"><Caption>{l}</Caption><SmallText>{v}</SmallText></div>
                        ))}
                      </div>
                    </CardContent>
                  </Card>
                ))}
              </div></CardContent>
            </Card>

            <div className="text-center"><Caption>Data generated at {formatters.dateTime(data.generated_at)}</Caption></div>
          </>
        )}
      </div>
    </ScrollArea>
  )
}