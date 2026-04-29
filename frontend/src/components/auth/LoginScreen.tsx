import { useState, useEffect } from 'react'
import { authApi } from '@/services/api'
import { Button } from '@/components/ui/button'

interface DevUser {
  id: number
  name: string
  email: string
}

interface LoginScreenProps {
  onLogin: (email: string) => Promise<void>
}

export function LoginScreen({ onLogin }: LoginScreenProps) {
  const [users, setUsers] = useState<DevUser[]>([])
  const [loading, setLoading] = useState(true)
  const [loggingIn, setLoggingIn] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    authApi.getDevUsers()
      .then((res) => setUsers(res.users))
      .catch(() => setError('Failed to load users. Is the backend running?'))
      .finally(() => setLoading(false))
  }, [])

  const handleLogin = async (email: string) => {
    setLoggingIn(email)
    setError(null)
    try {
      await onLogin(email)
    } catch {
      setError('Login failed. Please try again.')
      setLoggingIn(null)
    }
  }

  return (
    <div className="flex min-h-screen items-center justify-center bg-background">
      <div className="w-full max-w-sm space-y-6 p-6">
        <div className="text-center space-y-2">
          <h1 className="text-2xl font-semibold tracking-tight">Welcome</h1>
          <p className="text-sm text-muted-foreground">Select a user to continue</p>
        </div>

        {error && (
          <div className="rounded-md bg-destructive/10 p-3 text-sm text-destructive">
            {error}
          </div>
        )}

        {loading ? (
          <div className="text-center text-sm text-muted-foreground">Loading users...</div>
        ) : (
          <div className="space-y-2">
            {users.map((u) => (
              <Button
                key={u.id}
                variant="secondary"
                className="w-full justify-start gap-3 h-auto py-3"
                disabled={loggingIn !== null}
                onClick={() => handleLogin(u.email)}
              >
                <span className="flex h-8 w-8 items-center justify-center rounded-full bg-primary/10 text-sm font-medium text-primary">
                  {u.name.slice(-1)}
                </span>
                <span className="flex flex-col items-start">
                  <span className="font-medium">{u.name}</span>
                  <span className="text-xs text-muted-foreground">{u.email}</span>
                </span>
                {loggingIn === u.email && (
                  <span className="ml-auto text-xs text-muted-foreground">Signing in...</span>
                )}
              </Button>
            ))}
          </div>
        )}
      </div>
    </div>
  )
}