<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { router, useForm } from '@inertiajs/vue3'
import { FilePenLine, PenLine, Receipt, ScrollText } from 'lucide-vue-next'
import { ref } from 'vue'

type SignatureCard = {
  id: number
  type: string
  display_name: string | null
  is_active: boolean
  uploaded_at: string | null
  uploaded_by: string | null
  preview_url: string
} | null

const props = defineProps<{
  signatures: {
    certificate: SignatureCard
    receipt: SignatureCard
  }
  can: { manage: boolean }
}>()

const certificateFile = ref<HTMLInputElement | null>(null)
const receiptFile = ref<HTMLInputElement | null>(null)

const certificateForm = useForm<{ type: string; display_name: string; file: File | null }>({
  type: 'certificate',
  display_name: '',
  file: null,
})
const receiptForm = useForm<{ type: string; display_name: string; file: File | null }>({
  type: 'receipt',
  display_name: '',
  file: null,
})

function submitCertificate() {
  if (!certificateForm.file) return
  certificateForm.post('/admin/settings/document-signatures', {
    forceFormData: true,
    preserveScroll: true,
    onSuccess: () => {
      certificateForm.reset()
      if (certificateFile.value) certificateFile.value.value = ''
    },
  })
}

function submitReceipt() {
  if (!receiptForm.file) return
  receiptForm.post('/admin/settings/document-signatures', {
    forceFormData: true,
    preserveScroll: true,
    onSuccess: () => {
      receiptForm.reset()
      if (receiptFile.value) receiptFile.value.value = ''
    },
  })
}

function deactivate(id: number) {
  if (!confirm('Deactivate this signature? PDFs will fall back to the signature line only.')) return
  router.post(`/admin/settings/document-signatures/${id}/deactivate`, {}, { preserveScroll: true })
}

function remove(id: number) {
  if (!confirm('Remove this signature file permanently?')) return
  router.delete(`/admin/settings/document-signatures/${id}`, { preserveScroll: true })
}

function formatWhen(iso: string | null | undefined) {
  if (!iso) return '—'
  try {
    return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso))
  } catch {
    return iso
  }
}
</script>

<template>
  <AdminLayout>
    <div>
      <div class="inline-flex items-center gap-2 text-xs font-semibold text-text-muted">
        <PenLine class="h-4 w-4" aria-hidden="true" />
        System settings
      </div>
      <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">Document Signatures</h1>
      <p class="mt-1 max-w-3xl text-sm text-text-muted">
        Upload PNG signatures used on official receipt and certificate PDFs. Only one active signature is used per document type.
      </p>
    </div>

    <div class="mt-8 grid gap-6 lg:grid-cols-2">
      <article class="overflow-hidden rounded-2xl border border-border bg-surface shadow-sm">
        <div class="border-b border-border bg-surface-muted px-5 py-4">
          <div class="flex items-center gap-2 text-sm font-semibold text-text-primary">
            <ScrollText class="h-4 w-4 text-brand" aria-hidden="true" />
            Certificate signature
          </div>
        </div>
        <div class="space-y-4 p-5">
          <div v-if="signatures.certificate" class="rounded-xl border border-border bg-surface-muted/50 p-4">
            <div class="flex items-start justify-between gap-3">
              <div>
                <div class="text-xs font-semibold uppercase tracking-wide text-text-muted">Active signature</div>
                <div class="mt-1 text-sm font-semibold text-text-primary">{{ signatures.certificate.display_name || 'Certificate signature' }}</div>
                <div class="mt-2 text-xs text-text-muted">Uploaded by {{ signatures.certificate.uploaded_by || '—' }}</div>
                <div class="text-xs text-text-muted">{{ formatWhen(signatures.certificate.uploaded_at) }}</div>
              </div>
              <span class="zaqa-badge zaqa-badge-success text-[10px]">Active</span>
            </div>
            <img :src="signatures.certificate.preview_url" alt="Certificate signature preview" class="mt-4 max-h-20 object-contain" />
            <div v-if="can.manage" class="mt-4 flex flex-wrap gap-2">
              <button type="button" class="zaqa-btn zaqa-btn-secondary px-3 py-1.5 text-xs" @click="deactivate(signatures.certificate!.id)">Deactivate</button>
              <button type="button" class="zaqa-btn zaqa-btn-secondary px-3 py-1.5 text-xs" @click="remove(signatures.certificate!.id)">Remove</button>
            </div>
          </div>
          <div v-else class="rounded-xl border border-dashed border-border bg-surface-muted/30 px-4 py-6 text-center text-sm text-text-muted">
            No active certificate signature uploaded.
          </div>

          <form v-if="can.manage" class="space-y-3 border-t border-border pt-4" @submit.prevent="submitCertificate">
            <div class="text-xs font-semibold uppercase tracking-wide text-text-muted">Upload / replace</div>
            <input v-model="certificateForm.display_name" class="zaqa-input h-10 w-full" placeholder="Display name (optional)" />
            <input
              ref="certificateFile"
              type="file"
              accept="image/png"
              class="block w-full text-sm"
              @change="certificateForm.file = ($event.target as HTMLInputElement).files?.[0] ?? null"
            />
            <button type="submit" class="zaqa-btn zaqa-btn-primary px-4 py-2 text-sm" :disabled="certificateForm.processing || !certificateForm.file">
              Upload certificate signature
            </button>
          </form>
        </div>
      </article>

      <article class="overflow-hidden rounded-2xl border border-border bg-surface shadow-sm">
        <div class="border-b border-border bg-surface-muted px-5 py-4">
          <div class="flex items-center gap-2 text-sm font-semibold text-text-primary">
            <Receipt class="h-4 w-4 text-brand" aria-hidden="true" />
            Receipt signature
          </div>
        </div>
        <div class="space-y-4 p-5">
          <div v-if="signatures.receipt" class="rounded-xl border border-border bg-surface-muted/50 p-4">
            <div class="flex items-start justify-between gap-3">
              <div>
                <div class="text-xs font-semibold uppercase tracking-wide text-text-muted">Active signature</div>
                <div class="mt-1 text-sm font-semibold text-text-primary">{{ signatures.receipt.display_name || 'Receipt signature' }}</div>
                <div class="mt-2 text-xs text-text-muted">Uploaded by {{ signatures.receipt.uploaded_by || '—' }}</div>
                <div class="text-xs text-text-muted">{{ formatWhen(signatures.receipt.uploaded_at) }}</div>
              </div>
              <span class="zaqa-badge zaqa-badge-success text-[10px]">Active</span>
            </div>
            <img :src="signatures.receipt.preview_url" alt="Receipt signature preview" class="mt-4 max-h-20 object-contain" />
            <div v-if="can.manage" class="mt-4 flex flex-wrap gap-2">
              <button type="button" class="zaqa-btn zaqa-btn-secondary px-3 py-1.5 text-xs" @click="deactivate(signatures.receipt!.id)">Deactivate</button>
              <button type="button" class="zaqa-btn zaqa-btn-secondary px-3 py-1.5 text-xs" @click="remove(signatures.receipt!.id)">Remove</button>
            </div>
          </div>
          <div v-else class="rounded-xl border border-dashed border-border bg-surface-muted/30 px-4 py-6 text-center text-sm text-text-muted">
            No active receipt signature uploaded.
          </div>

          <form v-if="can.manage" class="space-y-3 border-t border-border pt-4" @submit.prevent="submitReceipt">
            <div class="text-xs font-semibold uppercase tracking-wide text-text-muted">Upload / replace</div>
            <input v-model="receiptForm.display_name" class="zaqa-input h-10 w-full" placeholder="Display name (optional)" />
            <input
              ref="receiptFile"
              type="file"
              accept="image/png"
              class="block w-full text-sm"
              @change="receiptForm.file = ($event.target as HTMLInputElement).files?.[0] ?? null"
            />
            <button type="submit" class="zaqa-btn zaqa-btn-primary px-4 py-2 text-sm" :disabled="receiptForm.processing || !receiptForm.file">
              Upload receipt signature
            </button>
          </form>
        </div>
      </article>
    </div>
  </AdminLayout>
</template>
