export type InlinePreviewDocument = {
  label: string
  filename: string
  mime_type?: string | null
  preview_url: string
  download_url: string
}

export function inferDocumentMimeType(filename: string, mimeType?: string | null): string {
  const mime = (mimeType ?? '').trim().toLowerCase()
  if (mime) {
    return mime
  }

  const extension = filename.includes('.') ? filename.split('.').pop()?.toLowerCase() ?? '' : ''
  const byExtension: Record<string, string> = {
    pdf: 'application/pdf',
    jpg: 'image/jpeg',
    jpeg: 'image/jpeg',
    png: 'image/png',
    webp: 'image/webp',
  }

  return byExtension[extension] ?? ''
}

export function resolvePreviewKind(
  document: Pick<InlinePreviewDocument, 'filename' | 'mime_type'>,
): 'image' | 'pdf' | 'unsupported' {
  const mime = inferDocumentMimeType(document.filename, document.mime_type)

  if (mime.startsWith('image/')) {
    return 'image'
  }
  if (mime === 'application/pdf') {
    return 'pdf'
  }

  return 'unsupported'
}
