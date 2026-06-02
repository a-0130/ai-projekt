import { useEffect, useState, type FormEvent } from 'react'
import { NavLink, Navigate, useParams } from 'react-router-dom'
import { AchievementsPanel } from '../components/achievements/AchievementsPanel'
import { ReportsPanel } from '../components/reports/ReportsPanel'
import { RideHistoryPanel } from '../components/ride-history/RideHistoryPanel'
import { BuyTicketsPanel } from '../components/tickets/BuyTicketsPanel'
import { MyTicketsPanel } from '../components/tickets/MyTicketsPanel'
import { useAuth, type AuthUser } from '../contexts/AuthContext'
import { getApiBaseUrl } from '../lib/api'

const SECTIONS = [
  { slug: 'profil', label: 'Profil' },
  { slug: 'bilety', label: 'Moje bilety' },
  { slug: 'kup-bilet', label: 'Kup bilet' },
  { slug: 'osiagniecia', label: 'Osiagniecia' },
  { slug: 'historia', label: 'Historia przejazdow' },
  { slug: 'zgloszenia', label: 'Zgloszenia' },
] as const

type SectionSlug = (typeof SECTIONS)[number]['slug']

export function AccountSettingsPage() {
  const { section } = useParams<{ section?: string }>()
  const {
    token,
    user,
    isAuthenticated,
    loading: authLoading,
    apiRequest,
    apiFormRequest,
    logout,
    refreshUser,
    setSession,
  } = useAuth()

  const activeSection: SectionSlug =
    SECTIONS.some((item) => item.slug === section) ? (section as SectionSlug) : 'profil'

  const [message, setMessage] = useState('')
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(false)
  const [ticketsRefreshKey, setTicketsRefreshKey] = useState(0)
  const [profileForm, setProfileForm] = useState({ name: '', email: '' })
  const [passwordForm, setPasswordForm] = useState({
    current_password: '',
    password: '',
    password_confirmation: '',
  })

  useEffect(() => {
    if (user) {
      setProfileForm({ name: user.name, email: user.email })
    }
  }, [user])

  if (authLoading) {
    return <p className="text-center text-sm text-slate-500">Ladowanie konta...</p>
  }

  if (!isAuthenticated) {
    return <Navigate to="/sign-in" replace />
  }

  if (section && !SECTIONS.some((item) => item.slug === section)) {
    return <Navigate to="/account/profil" replace />
  }

  if (!section) {
    return <Navigate to="/account/profil" replace />
  }

  async function handleLogout() {
    setLoading(true)
    setError('')
    try {
      await logout()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Wylogowanie nieudane')
    } finally {
      setLoading(false)
    }
  }

  async function handleProfileUpdate(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setLoading(true)
    setError('')
    setMessage('')
    try {
      const response = await apiRequest<{ user: AuthUser; message: string }>('/auth/profile', {
        method: 'PATCH',
        body: JSON.stringify(profileForm),
      })
      await refreshUser()
      setProfileForm({ name: response.user.name, email: response.user.email })
      setMessage(response.message)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Nie udalo sie zapisac profilu')
    } finally {
      setLoading(false)
    }
  }

  async function handlePasswordUpdate(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setLoading(true)
    setError('')
    setMessage('')
    try {
      const response = await apiRequest<{ token: string; message: string }>('/auth/password', {
        method: 'PATCH',
        body: JSON.stringify(passwordForm),
      })
      if (user) {
        setSession(response.token, user)
      }
      setPasswordForm({ current_password: '', password: '', password_confirmation: '' })
      setMessage(response.message)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Nie udalo sie zmienic hasla')
    } finally {
      setLoading(false)
    }
  }

  async function handleDataExport() {
    setLoading(true)
    setError('')
    setMessage('')
    try {
      const response = await fetch(`${getApiBaseUrl()}/auth/export-data`, {
        headers: {
          Accept: 'application/json',
          Authorization: `Bearer ${token}`,
        },
      })
      if (!response.ok) {
        const payload = await response.json().catch(() => ({}))
        throw new Error(String(payload?.message ?? `HTTP ${response.status}`))
      }
      const blob = await response.blob()
      const disposition = response.headers.get('Content-Disposition') ?? ''
      const fileNameMatch = disposition.match(/filename="([^"]+)"/)
      const fileName = fileNameMatch?.[1] ?? 'dane-rodo-uzytkownika.pdf'
      const url = URL.createObjectURL(blob)
      const link = document.createElement('a')
      link.href = url
      link.download = fileName
      document.body.appendChild(link)
      link.click()
      link.remove()
      URL.revokeObjectURL(url)
      setMessage('Eksport danych zostal pobrany.')
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Nie udalo sie pobrac eksportu danych')
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="grid gap-8 lg:grid-cols-[220px_minmax(0,1fr)]">
      <aside className="space-y-4">
        <div>
          <p className="text-sm font-medium text-slate-900">{user?.name}</p>
          <p className="text-xs text-slate-500">{user?.email}</p>
        </div>
        <nav className="flex flex-col gap-1">
          {SECTIONS.map((item) => (
            <NavLink
              key={item.slug}
              to={`/account/${item.slug}`}
              className={({ isActive }) =>
                `rounded-lg px-3 py-2 text-sm font-medium ${
                  isActive ? 'bg-[#1754d8] text-white' : 'text-slate-700 hover:bg-slate-100'
                }`
              }
            >
              {item.label}
            </NavLink>
          ))}
        </nav>
        <button
          type="button"
          onClick={() => void handleLogout()}
          disabled={loading}
          className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-60"
        >
          Wyloguj
        </button>
      </aside>

      <div className="min-w-0">
        {error ? (
          <p className="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{error}</p>
        ) : null}
        {message ? (
          <p className="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
            {message}
          </p>
        ) : null}

        {activeSection === 'profil' ? (
          <div className="grid gap-6 md:grid-cols-2">
            <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
              <h2 className="mb-4 text-lg font-semibold">Profil</h2>
              <form onSubmit={handleProfileUpdate} className="space-y-4">
                <Field
                  label="Imie i nazwisko"
                  value={profileForm.name}
                  onChange={(value) => setProfileForm((prev) => ({ ...prev, name: value }))}
                />
                <Field
                  label="Email"
                  type="email"
                  value={profileForm.email}
                  onChange={(value) => setProfileForm((prev) => ({ ...prev, email: value }))}
                />
                <SubmitButton disabled={loading}>Zapisz dane</SubmitButton>
              </form>
            </section>
            <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
              <h2 className="mb-4 text-lg font-semibold">Zmiana hasla</h2>
              <form onSubmit={handlePasswordUpdate} className="space-y-4">
                <Field
                  label="Aktualne haslo"
                  type="password"
                  value={passwordForm.current_password}
                  onChange={(value) => setPasswordForm((prev) => ({ ...prev, current_password: value }))}
                />
                <Field
                  label="Nowe haslo"
                  type="password"
                  value={passwordForm.password}
                  onChange={(value) => setPasswordForm((prev) => ({ ...prev, password: value }))}
                />
                <Field
                  label="Powtorz nowe haslo"
                  type="password"
                  value={passwordForm.password_confirmation}
                  onChange={(value) => setPasswordForm((prev) => ({ ...prev, password_confirmation: value }))}
                />
                <SubmitButton disabled={loading}>Zmien haslo</SubmitButton>
              </form>
            </section>
            <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm md:col-span-2">
              <h2 className="mb-2 text-lg font-semibold">Eksport danych</h2>
              <p className="mb-4 text-sm text-slate-600">
                Pobierz dane konta, biletow, przejazdow, zgloszen, osiagniec i kodow rabatowych w pliku JSON.
              </p>
              <button
                type="button"
                onClick={() => void handleDataExport()}
                disabled={loading}
                className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-60"
              >
                Eksportuj dane (RODO)
              </button>
            </section>
          </div>
        ) : null}

        {activeSection === 'bilety' ? (
          <MyTicketsPanel
            apiRequest={apiRequest}
            refreshKey={ticketsRefreshKey}
            onMessage={setMessage}
            onError={setError}
            loading={loading}
            setLoading={setLoading}
          />
        ) : null}

        {activeSection === 'kup-bilet' ? (
          <BuyTicketsPanel
            apiRequest={apiRequest}
            onPurchased={() => {
              setTicketsRefreshKey((prev) => prev + 1)
            }}
            onMessage={setMessage}
            onError={setError}
            loading={loading}
            setLoading={setLoading}
          />
        ) : null}

        {activeSection === 'historia' ? <RideHistoryPanel apiRequest={apiRequest} onError={setError} /> : null}

        {activeSection === 'osiagniecia' ? <AchievementsPanel apiRequest={apiRequest} onError={setError} /> : null}

        {activeSection === 'zgloszenia' ? (
          <ReportsPanel
            apiRequest={apiRequest}
            apiFormRequest={apiFormRequest}
            onMessage={setMessage}
            onError={setError}
          />
        ) : null}
      </div>
    </div>
  )
}

function Field({
  label,
  value,
  onChange,
  type = 'text',
}: {
  label: string
  value: string
  onChange: (value: string) => void
  type?: 'text' | 'email' | 'password'
}) {
  return (
    <label className="block">
      <span className="mb-1 block text-sm font-medium text-slate-700">{label}</span>
      <input
        type={type}
        value={value}
        onChange={(event) => onChange(event.target.value)}
        required
        className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-[#1754d8] focus:ring-2 focus:ring-[#1754d8]/20"
      />
    </label>
  )
}

function SubmitButton({ disabled, children }: { disabled?: boolean; children: string }) {
  return (
    <button
      type="submit"
      disabled={disabled}
      className="rounded-lg bg-[#1754d8] px-4 py-2 text-sm font-medium text-white hover:bg-[#1549bc] disabled:opacity-60"
    >
      {children}
    </button>
  )
}
