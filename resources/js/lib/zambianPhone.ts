export function zambianPhoneLocalPart(stored: string | null | undefined): string {
  if (!stored) {
    return ''
  }

  const digits = stored.replace(/\D/g, '')

  if (digits.startsWith('260') && digits.length >= 12) {
    return digits.slice(3, 12)
  }

  if (digits.startsWith('0') && digits.length >= 10) {
    return digits.slice(1, 10)
  }

  if (digits.length >= 9) {
    return digits.slice(0, 9)
  }

  return digits
}
