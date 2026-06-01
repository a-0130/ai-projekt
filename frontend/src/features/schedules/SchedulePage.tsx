import { useCallback, useEffect, useMemo, useState } from 'react'
import { MapContainer, Marker, Polyline, TileLayer } from 'react-leaflet'
import { Alert } from '../../components/ui/Alert'
import { Spinner } from '../../components/ui/Spinner'
import { shapeToLatLngs } from '../../components/map/geo'
import { fetchRoutePattern, fetchRouteStopTimes, fetchRoutesList, getRoutesPdfUrl } from './api'
import type { DirectionPattern, ListedRoute, PatternStopRow } from './types'

function formatTimeLabel(raw: string): string {
  const parts = raw.split(':')
  if (parts.length >= 2) {
    return `${parts[0]}:${parts[1]}`
  }
  return raw
}

function routeModeSuffix(routeType: number): string {
  switch (routeType) {
    case 0:
      return 'tramwaj'
    case 1:
      return 'metro'
    case 2:
      return 'kolej'
    case 3:
      return 'autobus'
    case 4:
      return 'prom'
    case 11:
      return 'trolejbus'
    default:
      return `typ ${routeType}`
  }
}

export function SchedulePage() {
  const [scheduleDate, setScheduleDate] = useState(() => new Date().toISOString().slice(0, 10))
  const [error, setError] = useState<string | null>(null)

  const [routes, setRoutes] = useState<ListedRoute[]>([])
  const [routesLoading, setRoutesLoading] = useState(true)
  const [routeId, setRouteId] = useState<number | null>(null)
  const [pdfRouteIds, setPdfRouteIds] = useState<number[]>([])
  const [pattern, setPattern] = useState<DirectionPattern[]>([])
  const [endpointSplit, setEndpointSplit] = useState(false)
  const [directionKey, setDirectionKey] = useState<number | null>(null)
  const [patternLoading, setPatternLoading] = useState(false)
  const [lineStopId, setLineStopId] = useState<number | null>(null)
  const [lineTimes, setLineTimes] = useState<string[]>([])
  const [lineTimesLoading, setLineTimesLoading] = useState(false)

  const duplicateShortNames = useMemo(() => {
    const counts = new Map<string, number>()
    for (const r of routes) {
      const k = r.short_name.trim()
      counts.set(k, (counts.get(k) ?? 0) + 1)
    }
    return counts
  }, [routes])

  useEffect(() => {
    let cancelled = false
    setRoutesLoading(true)
    fetchRoutesList()
      .then((list) => {
        if (!cancelled) {
          setRoutes(list)
        }
      })
      .catch((e: unknown) => {
        if (!cancelled) {
          setError(e instanceof Error ? e.message : 'Nie udalo sie zaladowac linii.')
        }
      })
      .finally(() => {
        if (!cancelled) {
          setRoutesLoading(false)
        }
      })
    return () => {
      cancelled = true
    }
  }, [])

  useEffect(() => {
    if (routeId === null) {
      setPattern([])
      setEndpointSplit(false)
      setDirectionKey(null)
      setLineStopId(null)
      setLineTimes([])
      return
    }
    let cancelled = false
    setPatternLoading(true)
    setError(null)
    fetchRoutePattern(routeId)
      .then((res) => {
        if (cancelled) {
          return
        }
        setPattern(res.directions)
        setEndpointSplit(Boolean(res.endpoint_split))
        const first = res.directions[0] ?? null
        setDirectionKey(first ? first.direction_key : null)
        setLineStopId(null)
        setLineTimes([])
      })
      .catch((e: unknown) => {
        if (!cancelled) {
          setError(e instanceof Error ? e.message : 'Nie udalo sie zaladowac trasy linii.')
        }
      })
      .finally(() => {
        if (!cancelled) {
          setPatternLoading(false)
        }
      })
    return () => {
      cancelled = true
    }
  }, [routeId])

  const activeDirection = pattern.find((d) => d.direction_key === directionKey) ?? null

  useEffect(() => {
    if (routeId === null || lineStopId === null || !activeDirection) {
      setLineTimes([])
      return
    }
    let cancelled = false
    setLineTimesLoading(true)
    fetchRouteStopTimes({
      routeId,
      stopId: lineStopId,
      directionKey,
      date: scheduleDate,
      tripPatternId: endpointSplit ? activeDirection.representative_trip_id : undefined,
      useTripEndpoints: endpointSplit,
    })
      .then((res) => {
        if (!cancelled) {
          setLineTimes(res.times)
        }
      })
      .catch((e: unknown) => {
        if (!cancelled) {
          setError(e instanceof Error ? e.message : 'Nie udalo sie zaladowac godzin.')
        }
      })
      .finally(() => {
        if (!cancelled) {
          setLineTimesLoading(false)
        }
      })
    return () => {
      cancelled = true
    }
  }, [routeId, lineStopId, directionKey, scheduleDate, endpointSplit, activeDirection?.representative_trip_id])

  const mapLine = shapeToLatLngs(activeDirection?.shape ?? []) as [number, number][]
  const selectedStopRow: PatternStopRow | undefined = activeDirection?.stops.find((r) => r.stop.id === lineStopId)
  const mapCenter: [number, number] = selectedStopRow
    ? [selectedStopRow.stop.stop_lat, selectedStopRow.stop.stop_lon]
    : mapLine[0]
      ? [mapLine[0][0], mapLine[0][1]]
      : activeDirection?.stops[0]
        ? [activeDirection.stops[0].stop.stop_lat, activeDirection.stops[0].stop.stop_lon]
        : [50.041, 21.999]

  const pickStop = useCallback((row: PatternStopRow) => {
    setLineStopId(row.stop.id)
  }, [])

  const activateRoute = useCallback((id: number) => {
    setRouteId(id)
    setLineStopId(null)
    setPdfRouteIds((current) => (current.includes(id) ? current : [...current, id]))
  }, [])

  const togglePdfRoute = useCallback((id: number) => {
    setPdfRouteIds((current) => (current.includes(id) ? current.filter((route) => route !== id) : [...current, id]))
  }, [])

  const downloadPdf = useCallback(() => {
    if (pdfRouteIds.length === 0) {
      return
    }
    window.location.assign(getRoutesPdfUrl(pdfRouteIds, scheduleDate))
  }, [pdfRouteIds, scheduleDate])

  const cycleDirection = useCallback(() => {
    if (pattern.length < 2) {
      return
    }
    const idx = pattern.findIndex((d) => d.direction_key === directionKey)
    const i = idx >= 0 ? idx : 0
    const next = pattern[(i + 1) % pattern.length]
    setDirectionKey(next.direction_key)
    setLineStopId(null)
  }, [pattern, directionKey])

  return (
    <section className="space-y-6">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold">Rozklad jazdy</h1>
          <p className="mt-2 text-slate-600">Wybierz linie, kierunek i przystanek, aby zobaczyc godziny odjazdow.</p>
        </div>
        <label className="block text-sm">
          <span className="mb-1 block font-medium text-slate-700">Data kursowania</span>
          <input
            type="date"
            value={scheduleDate}
            onChange={(e) => setScheduleDate(e.target.value)}
            className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm"
          />
        </label>
      </div>

      {error ? <Alert>{error}</Alert> : null}

      <div className="grid gap-6 lg:grid-cols-2">
        <div className="space-y-4 rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <h2 className="text-lg font-semibold">Linie</h2>
            <button
              type="button"
              onClick={downloadPdf}
              disabled={pdfRouteIds.length === 0}
              className="rounded-lg bg-emerald-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700 disabled:cursor-not-allowed disabled:bg-slate-300"
            >
              Pobierz PDF
            </button>
          </div>
          {routesLoading ? <Spinner label="Ladowanie linii..." /> : routes.length === 0 ? (
            <p className="text-sm text-slate-500">Brak linii w bazie danych.</p>
          ) : (
            <div className="grid max-h-44 w-full max-w-md grid-cols-[repeat(auto-fit,minmax(5.25rem,1fr))] gap-2 overflow-y-auto overflow-x-hidden p-1">
              {routes.map((r) => {
                const showMode =
                  (duplicateShortNames.get(r.short_name.trim()) ?? 0) > 1
                const checkedForPdf = pdfRouteIds.includes(r.id)
                return (
                  <div
                    key={r.id}
                    title={r.long_name ?? r.route_id}
                    className={`flex min-w-0 items-center gap-2 rounded-lg px-2 py-2 ${
                      routeId === r.id ? 'bg-emerald-50 ring-2 ring-emerald-500' : 'bg-slate-50 ring-1 ring-slate-200'
                    }`}
                  >
                    <input
                      type="checkbox"
                      checked={checkedForPdf}
                      onChange={() => togglePdfRoute(r.id)}
                      className="h-4 w-4 shrink-0 rounded border-slate-300 text-emerald-600"
                      aria-label={`Dodaj linie ${r.short_name} do PDF`}
                    />
                    <button
                      type="button"
                      onClick={() => activateRoute(r.id)}
                      className={`min-w-0 flex-1 truncate rounded-md px-1.5 py-1 text-left text-sm font-semibold ${
                        routeId === r.id ? 'bg-emerald-600 text-white' : 'text-slate-800 hover:bg-slate-100'
                      }`}
                    >
                      {showMode ? `${r.short_name} - ${routeModeSuffix(r.route_type)}` : r.short_name}
                    </button>
                  </div>
                )
              })}
            </div>
          )}
          {pdfRouteIds.length > 0 ? (
            <p className="text-xs text-slate-500">PDF obejmie {pdfRouteIds.length} wybranych linii.</p>
          ) : (
            <p className="text-xs text-slate-500">Zaznacz jedna lub kilka linii, aby pobrac wspolny PDF z mapa i rozkladem.</p>
          )}

          {patternLoading ? <Spinner label="Ladowanie trasy..." /> : null}

          {pattern.length > 1 ? (
            <div>
              <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                <h3 className="text-sm font-medium text-slate-700">Kierunek</h3>
                <button
                  type="button"
                  onClick={cycleDirection}
                  className="rounded-lg bg-slate-800 px-3 py-2 text-xs font-semibold text-white shadow-sm hover:bg-slate-900"
                >
                  Zmien kierunek
                </button>
              </div>
              <div className="flex flex-wrap gap-2">
                {pattern.map((d) => (
                  <button
                    key={d.direction_key}
                    type="button"
                    onClick={() => {
                      setDirectionKey(d.direction_key)
                      setLineStopId(null)
                    }}
                    className={`rounded-lg px-3 py-2 text-left text-sm ${
                      directionKey === d.direction_key
                        ? 'bg-emerald-50 text-emerald-800 ring-2 ring-emerald-500'
                        : 'bg-slate-50 text-slate-700 ring-1 ring-slate-200 hover:bg-slate-100'
                    }`}
                  >
                    <span className="block font-medium">Kierunek: {d.headsign ?? 'koncowy'}</span>
                  </button>
                ))}
              </div>
            </div>
          ) : null}

          {activeDirection ? (
            <div>
              <h3 className="mb-2 text-sm font-medium text-slate-700">Przystanki</h3>
              <ol className="max-h-72 space-y-1 overflow-y-auto text-sm">
                {activeDirection.stops.map((row) => (
                  <li key={row.stop.id}>
                    <button
                      type="button"
                      onClick={() => pickStop(row)}
                      className={`w-full rounded-lg px-3 py-2 text-left ${
                        lineStopId === row.stop.id ? 'bg-emerald-50 font-medium text-emerald-900' : 'hover:bg-slate-50'
                      }`}
                    >
                      {row.stop_sequence}. {row.stop.stop_name}
                    </button>
                  </li>
                ))}
              </ol>
            </div>
          ) : null}

          {lineStopId !== null ? (
            <div>
              <h3 className="mb-2 text-sm font-medium text-slate-700">Odjazdy z przystanku</h3>
              {lineTimesLoading ? <Spinner label="Ladowanie godzin..." /> : lineTimes.length === 0 ? (
                <p className="text-sm text-slate-500">Brak kursow w tym dniu (sprawdz dane kalendarza).</p>
              ) : (
                <div className="flex flex-wrap gap-2">
                  {lineTimes.map((t) => (
                    <span key={t} className="rounded-md bg-slate-100 px-2 py-1 font-mono text-sm">
                      {formatTimeLabel(t)}
                    </span>
                  ))}
                </div>
              )}
            </div>
          ) : null}
        </div>

        <div className="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
          <h2 className="mb-2 text-lg font-semibold">Mapa</h2>
          <MapContainer
            center={mapCenter}
            zoom={13}
            className="h-[420px] w-full rounded-xl"
            key={`${routeId ?? 'x'}-${directionKey ?? 'd'}-${mapCenter[0].toFixed(4)}`}
          >
            <TileLayer url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png" />
            {mapLine.length > 1 ? <Polyline positions={mapLine} color="#059669" weight={5} /> : null}
            {selectedStopRow ? (
              <Marker position={[selectedStopRow.stop.stop_lat, selectedStopRow.stop.stop_lon]} />
            ) : activeDirection?.stops[0] ? (
              <Marker position={[activeDirection.stops[0].stop.stop_lat, activeDirection.stops[0].stop.stop_lon]} />
            ) : null}
          </MapContainer>
        </div>
      </div>
    </section>
  )
}
