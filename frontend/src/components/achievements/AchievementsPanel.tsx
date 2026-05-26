import { useEffect, useRef, useState } from 'react'
import type { ApiRequestFn } from '../tickets/types'

type AchievementStats = {
  rides_count: number
  unique_routes_count: number
  current_streak_days: number
  reports_count: number
  route_coverage_percent: number
  total_minutes: number
  long_rides_count: number
  active_days_count: number
  morning_rides_count: number
}

type DiscountCode = {
  id: number
  code: string
  discount_percent: number
  expires_at: string | null
  used_at: string | null
  is_active: boolean
  achievement_name: string | null
}

type Achievement = {
  key: string
  variant_key: string
  name: string
  description: string
  threshold: number
  value: number
  progress_percent: number
  earned_at: string | null
  discount_code: DiscountCode | null
}

type AchievementsResponse = {
  stats: AchievementStats
  achievements: Achievement[]
  discount_codes: DiscountCode[]
}

type AchievementsPanelProps = {
  apiRequest: ApiRequestFn
  onError: (error: string) => void
}

function formatDate(value: string | null): string {
  if (!value) return 'Bez terminu'
  return new Date(value).toLocaleDateString('pl-PL')
}

export function AchievementsPanel({ apiRequest, onError }: AchievementsPanelProps) {
  const apiRequestRef = useRef(apiRequest)
  apiRequestRef.current = apiRequest

  const [data, setData] = useState<AchievementsResponse | null>(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    let cancelled = false

    async function loadAchievements() {
      try {
        setLoading(true)
        const response = await apiRequestRef.current<AchievementsResponse>('/achievements')
        if (!cancelled) {
          setData(response)
        }
      } catch (err) {
        if (!cancelled) {
          onError(err instanceof Error ? err.message : 'Nie udalo sie pobrac osiagniec')
        }
      } finally {
        if (!cancelled) {
          setLoading(false)
        }
      }
    }

    void loadAchievements()

    return () => {
      cancelled = true
    }
  }, [onError])

  if (loading) {
    return <p className="text-sm text-slate-500">Ladowanie osiagniec...</p>
  }

  if (!data) {
    return <p className="text-sm text-slate-500">Brak danych osiagniec.</p>
  }

  const activeCodes = data.discount_codes.filter((code) => code.is_active)
  const earnedCount = data.achievements.filter((achievement) => achievement.earned_at).length

  return (
    <div className="space-y-6">
      <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <div className="mb-5 flex flex-wrap items-end justify-between gap-3">
          <div>
            <h2 className="text-lg font-semibold text-slate-900">Osiagniecia</h2>
            <p className="text-sm text-slate-500">
              Zdobyte {earnedCount} z {data.achievements.length}
            </p>
          </div>
          <div className="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-medium text-emerald-700">
            Aktywne kody: {activeCodes.length}
          </div>
        </div>

        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <StatCard label="Przejazdy" value={data.stats.rides_count} />
          <StatCard label="Unikalne trasy" value={data.stats.unique_routes_count} />
          <StatCard label="Streak" value={`${data.stats.current_streak_days} dni`} />
          <StatCard label="Pokrycie tras" value={`${data.stats.route_coverage_percent}%`} />
          <StatCard label="Zgloszenia" value={data.stats.reports_count} />
          <StatCard label="Minuty w trasie" value={data.stats.total_minutes} />
          <StatCard label="Dluzsze przejazdy" value={data.stats.long_rides_count} />
          <StatCard label="Poranne przejazdy" value={data.stats.morning_rides_count} />
        </div>
      </section>

      <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <h3 className="mb-4 text-base font-semibold text-slate-900">Aktywne kody rabatowe</h3>
        {activeCodes.length === 0 ? (
          <p className="text-sm text-slate-500">Brak aktywnych kodow rabatowych.</p>
        ) : (
          <div className="grid gap-3 md:grid-cols-2">
            {activeCodes.map((code) => (
              <div key={code.id} className="rounded-lg border border-emerald-200 bg-emerald-50 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <div>
                    <p className="font-mono text-base font-semibold text-emerald-800">{code.code}</p>
                    <p className="text-sm text-emerald-700">{code.achievement_name}</p>
                  </div>
                  <p className="rounded-md bg-white px-2 py-1 text-sm font-semibold text-emerald-700">
                    -{code.discount_percent}%
                  </p>
                </div>
                <p className="mt-3 text-xs text-emerald-700">Wazny do: {formatDate(code.expires_at)}</p>
              </div>
            ))}
          </div>
        )}
      </section>

      <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <h3 className="mb-4 text-base font-semibold text-slate-900">Lista osiagniec</h3>
        <div className="grid gap-3 md:grid-cols-2">
          {data.achievements.map((achievement) => {
            const earned = Boolean(achievement.earned_at)
            return (
              <article
                key={`${achievement.key}-${achievement.variant_key}`}
                className={`rounded-lg border p-4 ${
                  earned ? 'border-[#1754d8]/20 bg-[#1754d8]/5' : 'border-slate-200 bg-slate-50'
                }`}
              >
                <div className="flex items-start justify-between gap-3">
                  <div>
                    <h4 className="font-medium text-slate-900">{achievement.name}</h4>
                    <p className="mt-1 text-sm text-slate-600">{achievement.description}</p>
                  </div>
                  <span
                    className={`shrink-0 rounded-md px-2 py-1 text-xs font-medium ${
                      earned ? 'bg-[#1754d8] text-white' : 'bg-white text-slate-600'
                    }`}
                  >
                    {earned ? 'Zdobyte' : `${Math.floor(achievement.progress_percent)}%`}
                  </span>
                </div>
                <div className="mt-4 h-2 overflow-hidden rounded-full bg-slate-200">
                  <div
                    className="h-full rounded-full bg-[#1754d8]"
                    style={{ width: `${Math.min(100, achievement.progress_percent)}%` }}
                  />
                </div>
                <div className="mt-2 flex justify-between text-xs text-slate-500">
                  <span>{achievement.value}</span>
                  <span>{achievement.threshold}</span>
                </div>
                {achievement.discount_code ? (
                  <p className="mt-3 text-sm text-slate-600">
                    Kod: <span className="font-mono">{achievement.discount_code.code}</span>, rabat{' '}
                    {achievement.discount_code.discount_percent}%
                  </p>
                ) : null}
              </article>
            )
          })}
        </div>
      </section>
    </div>
  )
}

function StatCard({ label, value }: { label: string; value: string | number }) {
  return (
    <div className="rounded-lg border border-slate-200 bg-slate-50 p-4">
      <p className="text-xs font-medium uppercase text-slate-500">{label}</p>
      <p className="mt-2 text-2xl font-semibold text-slate-900">{value}</p>
    </div>
  )
}
