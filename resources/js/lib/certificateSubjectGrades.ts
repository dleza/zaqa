/** Numeric grades 1–9 and letter grades A–Z (must match config/certificate_subjects.php). */
export const CERTIFICATE_SUBJECT_GRADE_OPTIONS = [
  '1',
  '2',
  '3',
  '4',
  '5',
  '6',
  '7',
  '8',
  '9',
  ...'ABCDEFGHIJKLMNOPQRSTUVWXYZ'.split(''),
] as const

export type CertificateSubjectGradeOption = (typeof CERTIFICATE_SUBJECT_GRADE_OPTIONS)[number]

export function isAllowedCertificateSubjectGrade(grade: unknown): boolean {
  const value = (grade ?? '').toString().trim()
  if (value === '') return false

  return (CERTIFICATE_SUBJECT_GRADE_OPTIONS as readonly string[]).includes(value)
}

export function selectGradeValue(grade: unknown): string {
  const value = (grade ?? '').toString().trim()
  return isAllowedCertificateSubjectGrade(value) ? value : ''
}

export function legacyGradeWarning(grade: unknown): string | null {
  const value = (grade ?? '').toString().trim()
  if (value === '') return null
  if (isAllowedCertificateSubjectGrade(value)) return null

  return `Current saved grade "${value}" is not in the allowed list. Please select a valid grade.`
}
