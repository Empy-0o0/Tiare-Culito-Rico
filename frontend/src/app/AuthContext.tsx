import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from 'react'
import { ApiError } from '../services/apiClient'
import { authApi } from '../services/publicProcurement'
import type { Profile } from '../types/api'

interface AuthState {
  profile: Profile | null
  loading: boolean
  login: (email: string, password: string) => Promise<void>
  logout: () => Promise<void>
  /** Vuelve a leer el perfil (p. ej. tras cambiar la contraseña de inicio). */
  refresh: () => Promise<void>
  can: (permission: string) => boolean
}

const AuthContext = createContext<AuthState | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const [profile, setProfile] = useState<Profile | null>(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    authApi
      .me()
      .then((r) => setProfile(r.data))
      .catch((e) => {
        if (!(e instanceof ApiError) || e.status !== 401) console.error(e)
        setProfile(null)
      })
      .finally(() => setLoading(false))
  }, [])

  const login = useCallback(async (email: string, password: string) => {
    const r = await authApi.login(email, password)
    setProfile(r.data)
  }, [])

  const logout = useCallback(async () => {
    try {
      await authApi.logout()
    } finally {
      setProfile(null)
    }
  }, [])

  const refresh = useCallback(async () => {
    const r = await authApi.me()
    setProfile(r.data)
  }, [])

  // Solo para mostrar/ocultar UI. La autorización real ocurre en el backend.
  const can = useCallback((permission: string) => profile?.permissions.includes(permission) ?? false, [profile])

  return <AuthContext.Provider value={{ profile, loading, login, logout, refresh, can }}>{children}</AuthContext.Provider>
}

export function useAuth(): AuthState {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth debe usarse dentro de AuthProvider')
  return ctx
}
