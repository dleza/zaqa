const ALLOWED_MIME_TYPES = new Set([
  'application/pdf',
  'image/jpeg',
  'image/png',
  'image/webp',
])

const ALLOWED_EXTENSIONS = new Set(['pdf', 'jpg', 'jpeg', 'png', 'webp'])

export const APPLICANT_DOCUMENT_ACCEPT =
  '.pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp'

export const APPLICANT_DOCUMENT_FILE_ERROR =
  'Only PDF files and images (JPG, PNG, WEBP) are allowed.'

export function isAllowedApplicantDocumentFile(file: File): boolean {
  const mime = (file.type ?? '').toLowerCase().trim()
  if (mime && ALLOWED_MIME_TYPES.has(mime)) {
    return true
  }

  const extension = file.name.includes('.') ? file.name.split('.').pop()?.toLowerCase() ?? '' : ''
  return ALLOWED_EXTENSIONS.has(extension)
}
