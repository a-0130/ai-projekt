export type TicketType = {
  id: number
  name: string
  price: string
  validity_minutes: number
  is_long_term: boolean
}

export type UserTicket = {
  id: number
  ticket_type: TicketType
  purchase_date: string
  discount_amount: string
  final_price: string | null
  valid_from: string | null
  valid_until: string | null
  is_active: boolean
  status: 'inactive' | 'active' | 'expired'
  can_activate: boolean
}

export type ApiRequestFn = <T>(path: string, options?: RequestInit) => Promise<T>

export function formatDate(value: string | null): string {
  if (!value) return '—'
  return new Date(value).toLocaleString('pl-PL', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

export function formatPrice(price: string): string {
  return `${Number(price).toFixed(2)} zł`
}

export function statusLabel(status: UserTicket['status']): string {
  switch (status) {
    case 'active':
      return 'Aktywny'
    case 'inactive':
      return 'Nieaktywny'
    case 'expired':
      return 'Wygasły'
  }
}

export function statusClass(status: UserTicket['status']): string {
  switch (status) {
    case 'active':
      return 'border-emerald-200 bg-emerald-50 text-emerald-700'
    case 'inactive':
      return 'border-amber-200 bg-amber-50 text-amber-700'
    case 'expired':
      return 'border-slate-200 bg-slate-100 text-slate-600'
  }
}
