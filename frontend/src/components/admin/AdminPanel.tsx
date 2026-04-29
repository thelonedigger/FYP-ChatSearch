import { useState } from 'react'
import { cn } from '@/lib/utils'
import { Server, Layers, MessageSquareText } from 'lucide-react'
import { LlmSettingsTab } from './tabs/LlmSettingsTab'
import { ChunkingSettingsTab } from './tabs/ChunkingSettingsTab'
import { PromptSettingsTab } from './tabs/PromptSettingsTab'

type AdminTab = 'llm' | 'chunking' | 'prompts'

const ADMIN_TABS: { key: AdminTab; label: string; icon: typeof Server }[] = [
  { key: 'llm', label: 'LLM', icon: Server },
  { key: 'chunking', label: 'Document Chunking', icon: Layers },
  { key: 'prompts', label: 'Prompts', icon: MessageSquareText },
]

export function AdminPanel() {
  const [activeTab, setActiveTab] = useState<AdminTab>('llm')

  return (
    <div className="flex flex-col h-full">
      {}
      <div className="border-b border-border bg-muted/30 px-6 pt-4">
        <div className="flex gap-1">
          {ADMIN_TABS.map(({ key, label, icon: Icon }) => (
            <button
              key={key}
              onClick={() => setActiveTab(key)}
              className={cn(
                'flex items-center gap-2 px-4 py-2.5 text-sm font-medium rounded-t-lg transition-colors border border-transparent -mb-px',
                activeTab === key
                  ? 'bg-background text-foreground border-border border-b-background'
                  : 'text-muted-foreground hover:text-foreground hover:bg-muted/50'
              )}
            >
              <Icon className="h-4 w-4" />
              {label}
            </button>
          ))}
        </div>
      </div>

      {}
      <div className="flex-1 overflow-hidden">
        {activeTab === 'llm' && <LlmSettingsTab />}
        {activeTab === 'chunking' && <ChunkingSettingsTab />}
        {activeTab === 'prompts' && <PromptSettingsTab />}
      </div>
    </div>
  )
}