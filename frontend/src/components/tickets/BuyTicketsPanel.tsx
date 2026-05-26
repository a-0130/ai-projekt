import { useEffect, useRef, useState } from 'react'
import type { FormEvent } from 'react'
import { formatPrice } from './types'
import type { ApiRequestFn, TicketType } from './types'

type BuyTicketsPanelProps = {
  apiRequest: ApiRequestFn
  onPurchased: () => void
  onMessage: (message: string) => void
  onError: (error: string) => void
  loading: boolean
  setLoading: (loading: boolean) => void
}

type DiscountValidation = {
  valid: boolean
  discount_code: {
    code: string
    discount_percent: number
    discount_amount: string
    final_price: string
    expires_at: string | null
    achievement_name: string | null
  }
}

function todayIsoDate(): string {
  const now = new Date()
  const year = now.getFullYear()
  const month = String(now.getMonth() + 1).padStart(2, '0')
  const day = String(now.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

export function BuyTicketsPanel({
  apiRequest,
  onPurchased,
  onMessage,
  onError,
  loading,
  setLoading,
}: BuyTicketsPanelProps) {
  const apiRequestRef = useRef(apiRequest)
  apiRequestRef.current = apiRequest

  const [types, setTypes] = useState<TicketType[]>([])
  const [typesLoading, setTypesLoading] = useState(true)
  const [selectedTypeId, setSelectedTypeId] = useState<number | null>(null)
  const [validFrom, setValidFrom] = useState(todayIsoDate())
  const [paymentConfirmed, setPaymentConfirmed] = useState(false)
  const [discountCode, setDiscountCode] = useState('')
  const [validatedDiscount, setValidatedDiscount] = useState<DiscountValidation['discount_code'] | null>(null)
  const [discountLoading, setDiscountLoading] = useState(false)

  const selectedType = types.find((type) => type.id === selectedTypeId) ?? null
  const displayedPrice = validatedDiscount?.final_price ?? selectedType?.price ?? '0'

  useEffect(() => {
    let cancelled = false

    async function loadTypes() {
      try {
        setTypesLoading(true)
        const response = await apiRequestRef.current<{ data: TicketType[] }>('/ticket-types')
        if (cancelled) return

        setTypes(response.data)
        setSelectedTypeId((current) => {
          if (current !== null && response.data.some((type) => type.id === current)) {
            return current
          }
          return response.data[0]?.id ?? null
        })
      } catch (err) {
        if (!cancelled) {
          onError(err instanceof Error ? err.message : 'Nie udalo sie pobrac typow biletow')
        }
      } finally {
        if (!cancelled) {
          setTypesLoading(false)
        }
      }
    }

    void loadTypes()

    return () => {
      cancelled = true
    }
  }, [onError])

  async function handlePurchase(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!selectedType) return

    onMessage('')
    onError('')

    if (!paymentConfirmed) {
      onError('Potwierdz symulowana platnosc przed zakupem.')
      return
    }

    try {
      setLoading(true)
      const body: Record<string, unknown> = { ticket_type_id: selectedType.id }
      if (selectedType.is_long_term) {
        body.valid_from = validFrom
      }
      if (discountCode.trim()) {
        body.discount_code = discountCode.trim().toUpperCase()
      }

      const response = await apiRequest<{ message: string }>('/tickets/purchase', {
        method: 'POST',
        body: JSON.stringify(body),
      })

      onMessage(response.message)
      setPaymentConfirmed(false)
      setDiscountCode('')
      setValidatedDiscount(null)
      onPurchased()
    } catch (err) {
      onError(err instanceof Error ? err.message : 'Zakup nieudany')
    } finally {
      setLoading(false)
    }
  }

  async function handleValidateDiscount() {
    if (!selectedType || !discountCode.trim()) {
      setValidatedDiscount(null)
      return
    }

    onMessage('')
    onError('')

    try {
      setDiscountLoading(true)
      const response = await apiRequest<DiscountValidation>('/discount-codes/validate', {
        method: 'POST',
        body: JSON.stringify({
          code: discountCode.trim().toUpperCase(),
          ticket_type_id: selectedType.id,
        }),
      })
      setValidatedDiscount(response.discount_code)
    } catch (err) {
      setValidatedDiscount(null)
      onError(err instanceof Error ? err.message : 'Kod rabatowy jest nieprawidlowy')
    } finally {
      setDiscountLoading(false)
    }
  }

  const isBusy = loading || typesLoading || discountLoading

  return (
    <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
      <h2 className="mb-4 text-lg font-semibold">Kupno biletow</h2>

      {typesLoading ? (
        <p className="text-sm text-slate-500">Ladowanie typow biletow...</p>
      ) : types.length === 0 ? (
        <p className="text-sm text-slate-500">Brak dostepnych typow biletow.</p>
      ) : (
        <form onSubmit={(event) => void handlePurchase(event)} className="space-y-4">
          <label className="block">
            <span className="mb-1 block text-sm font-medium text-slate-700">Typ biletu</span>
            <select
              value={selectedTypeId ?? ''}
              onChange={(event) => {
                setSelectedTypeId(Number(event.target.value))
                setValidatedDiscount(null)
              }}
              disabled={isBusy}
              className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-[#1754d8] focus:ring-2 focus:ring-[#1754d8]/20 disabled:opacity-60"
            >
              {types.map((type) => (
                <option key={type.id} value={type.id}>
                  {type.name} — {formatPrice(type.price)}
                </option>
              ))}
            </select>
          </label>

          {selectedType && (
            <div className="rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
              {selectedType.is_long_term ? (
                <p>
                  Bilet dlugoterminowy — aktywny od wybranej daty przez{' '}
                  {Math.round(selectedType.validity_minutes / 1440)} dni.
                </p>
              ) : (
                <p>
                  Bilet 60-minutowy — wymaga recznej aktywacji po zakupie. Wazny 60 minut od aktywacji.
                </p>
              )}
            </div>
          )}

          {selectedType?.is_long_term && (
            <label className="block">
              <span className="mb-1 block text-sm font-medium text-slate-700">Aktywny od</span>
              <input
                type="date"
                value={validFrom}
                min={todayIsoDate()}
                onChange={(event) => setValidFrom(event.target.value)}
                required
                disabled={isBusy}
                className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-[#1754d8] focus:ring-2 focus:ring-[#1754d8]/20 disabled:opacity-60"
              />
            </label>
          )}

          <div className="rounded-lg border border-slate-200 bg-slate-50 p-4">
            <label className="block">
              <span className="mb-1 block text-sm font-medium text-slate-700">Kod rabatowy</span>
              <div className="flex flex-col gap-2 sm:flex-row">
                <input
                  type="text"
                  value={discountCode}
                  onChange={(event) => {
                    setDiscountCode(event.target.value.toUpperCase())
                    setValidatedDiscount(null)
                  }}
                  disabled={isBusy}
                  className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 font-mono text-sm uppercase outline-none focus:border-[#1754d8] focus:ring-2 focus:ring-[#1754d8]/20 disabled:opacity-60"
                  placeholder="NP. START-ABC12345"
                />
                <button
                  type="button"
                  onClick={() => void handleValidateDiscount()}
                  disabled={isBusy || !selectedType || !discountCode.trim()}
                  className="rounded-lg border border-[#1754d8] px-4 py-2 text-sm font-medium text-[#1754d8] transition hover:bg-[#1754d8]/5 disabled:cursor-not-allowed disabled:opacity-60"
                >
                  Sprawdz
                </button>
              </div>
            </label>
            {validatedDiscount ? (
              <div className="mt-3 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-700">
                Rabat {validatedDiscount.discount_percent}%: -{formatPrice(validatedDiscount.discount_amount)}. Do zaplaty:{' '}
                {formatPrice(validatedDiscount.final_price)}
              </div>
            ) : null}
          </div>

          <label className="flex items-center gap-2 text-sm text-slate-700">
            <input
              type="checkbox"
              checked={paymentConfirmed}
              onChange={(event) => setPaymentConfirmed(event.target.checked)}
              disabled={isBusy}
              className="rounded border-slate-300 text-[#1754d8] focus:ring-[#1754d8]/20 disabled:opacity-60"
            />
            Potwierdzam symulowana platnosc ({selectedType ? formatPrice(displayedPrice) : '-'})
          </label>

          <button
            type="submit"
            disabled={isBusy || !selectedType}
            className="rounded-lg bg-[#1754d8] px-4 py-2 text-sm font-medium text-white transition hover:bg-[#1549bc] disabled:cursor-not-allowed disabled:opacity-60"
          >
            Kup bilet
          </button>
        </form>
      )}
    </section>
  )
}
