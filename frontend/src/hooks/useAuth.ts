import { useState, useEffect, useCallback } from 'react'
import { authApi } from '@/services/api'

interface User {
  id: number
  name: string
  email: string
}

const TOKEN_KEY = 'auth_token'
const USER_KEY = 'auth_user'

export function useAuth() {
  const [user, setUser] = useState<User | null>(() => {
    const stored = localStorage.getItem(USER_KEY)
    return stored ? JSON.parse(stored) : null
  })
  const [loading, setLoading] = useState(true)
  useEffect(() => {
    const token = localStorage.getItem(TOKEN_KEY)

    if (!token) {
      setUser(null)
      setLoading(false)
      return
    }

    authApi.getUser()
      .then((u) => {
        setUser(u)
        localStorage.setItem(USER_KEY, JSON.stringify(u))
      })
      .catch(() => {
        localStorage.removeItem(TOKEN_KEY)
        localStorage.removeItem(USER_KEY)
        setUser(null)
      })
      .finally(() => setLoading(false))
  }, [])

  const login = useCallback(async (email: string) => {
    const { user: u, token } = await authApi.login(email)
    localStorage.setItem(TOKEN_KEY, token)
    localStorage.setItem(USER_KEY, JSON.stringify(u))
    setUser(u)
    return u
  }, [])

  const logout = useCallback(async () => {
    try {
      await authApi.logout()
    } catch {
    }
    localStorage.removeItem(TOKEN_KEY)
    localStorage.removeItem(USER_KEY)
    setUser(null)
  }, [])

  return { user, loading, login, logout, isAuthenticated: !!user }
}