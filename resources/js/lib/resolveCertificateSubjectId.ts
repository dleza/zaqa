export function resolveCertificateSubjectId(
  row: { certificate_subject_id?: number | string | null; subject_name?: string | null },
  subjects: Array<{ id: number; name: string }>,
): number | '' {
  const existingId = Number(row.certificate_subject_id ?? 0)
  if (existingId > 0) {
    return existingId
  }

  const name = (row.subject_name ?? '').trim().toLowerCase()
  if (!name) {
    return ''
  }

  const match = subjects.find((subject) => subject.name.trim().toLowerCase() === name)

  return match ? match.id : ''
}
