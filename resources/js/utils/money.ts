export function formatMoneyFromCents(cents: number | null | undefined, currency: string | null | undefined) {
  if (cents === null || cents === undefined) return '—'
  const value = (cents ?? 0) / 100
  const ccy = (currency || 'ZMW').toUpperCase()
  return new Intl.NumberFormat(undefined, { style: 'currency', currency: ccy }).format(value)
}

export function amountFromCents(cents: number | null | undefined) {
  if (cents === null || cents === undefined) return ''
  return (cents / 100).toFixed(2)
}

