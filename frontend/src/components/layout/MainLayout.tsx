import type { ReactNode } from 'react'
import { TopBar } from './TopBar'
import { Sidebar } from './Sidebar'

type ActiveView = 'chat' | 'analytics' | 'admin'

interface MainLayoutProps {
  children: ReactNode
  currentConversationId?: number
  onSelectConversation: (id: number) => void
  onNewConversation: () => void
  activeView: ActiveView
  onChangeView: (view: ActiveView) => void
  conversations: Array<{ id: number; title: string; lastMessage: string; timestamp: string; messageCount: number }>
  onDeleteConversation: (id: number) => void
  userName?: string
  onLogout: () => void
}

export function MainLayout({ 
  children, 
  currentConversationId, 
  onSelectConversation, 
  onNewConversation, 
  activeView, 
  onChangeView,
  conversations,
  onDeleteConversation,
  userName,
  onLogout,
}: MainLayoutProps) {
  return (
    <div className="flex flex-col h-screen bg-background">
      <TopBar
        activeView={activeView}
        onChangeView={onChangeView}
        userName={userName}
        onLogout={onLogout}
        conversationCount={conversations.length}
      />
      <div className="flex flex-1 overflow-hidden">
        {activeView === 'chat' && (
          <Sidebar 
            currentConversationId={currentConversationId} 
            onSelectConversation={onSelectConversation} 
            onNewConversation={onNewConversation}
            conversations={conversations}
            onDeleteConversation={onDeleteConversation}
          />
        )}
        <main className="flex-1 overflow-hidden">{children}</main>
      </div>
    </div>
  )
}