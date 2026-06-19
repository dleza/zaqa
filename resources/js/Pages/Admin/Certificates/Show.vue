<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import AdminActionModal from '@/Components/AdminActionModal.vue'
import InlineDocumentPreview from '@/Components/Admin/InlineDocumentPreview.vue'
import { Link, useForm, usePage } from '@inertiajs/vue3'
import { BadgeCheck, Ban, ExternalLink, FileDown, ArrowLeft } from 'lucide-vue-next'
import { computed, ref } from 'vue'
import type { InlinePreviewDocument } from '@/lib/inlineDocumentPreview'

const props = defineProps<{
  certificate: {
    id: number
    certificate_number: string
    status: string
    status_label: string
    certificate_type_label: string
    issued_at: string | null
    issued_by_name: string | null
    qualification_title: string | null
    qualification_id: number | null
    holder_name: string | null
    application_number: string | null
    revoked_at: string | null
    revoked_by_name: string | null
    revocation_reason: string | null
    revocation_public_note: string | null
    download_url: string
    preview_url: string
    verification_url: string
    revoke_url: string | null
    verification_task_url: string | null
  }
  preview_document: InlinePreviewDocument
  can: { revoke?: boolean; open_verification_task?: boolean }
}>()

const page = usePage()
const revokeOpen = ref(false)

const revokeForm = useForm({
  revocation_reason: '',
  revocation_public_note: '',
  confirm: false,
})

const isRevoked = computed(() => props.certificate.status === 'revoked')

const statusBadgeClass = computed(() => {
  const s = props.certificate.status
  if (s === 'issued') return 'zaqa-badge-success'
  if (s === 'reissued') return 'zaqa-badge-warning'
  if (s === 'revoked') return 'zaqa-badge-danger'
  return 'zaqa-badge-secondary'
})

function formatDateTime(iso: string | null | undefined) {
  if (!iso) return '—'
  try {
    return new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
  } catch {
    return iso
  }
}

function openRevokeModal() {
  if (!props.certificate.revoke_url || !props.can?.revoke) return
  revokeForm.reset()
  revokeForm.clearErrors()
  revokeOpen.value = true
}

function submitRevoke() {
  if (!props.certificate.revoke_url) return
  revokeForm.post(props.certificate.revoke_url, {
    preserveScroll: true,
    onSuccess: () => {
      revokeOpen.value = false
      revokeForm.reset()
    },
  })
}
</script>

<template>
  <AdminLayout>
    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
      <div class="min-w-0">
        <Link href="/admin/certificates" class="inline-flex items-center gap-1.5 text-sm font-medium text-[#0076BD] hover:underline">
          <ArrowLeft class="h-4 w-4" aria-hidden="true" />
          Back to CVEQ registry
        </Link>
        <div class="mt-3 inline-flex items-center gap-2 text-xs font-semibold text-text-muted">
          <BadgeCheck class="h-4 w-4" aria-hidden="true" />
          Certificate detail
        </div>
        <h1 class="mt-2 font-mono text-2xl font-semibold tracking-tight text-text-primary">{{ certificate.certificate_number }}</h1>
        <div class="mt-2 flex flex-wrap items-center gap-2">
          <span class="zaqa-badge zaqa-badge-secondary">{{ certificate.certificate_type_label }}</span>
          <span class="zaqa-badge" :class="statusBadgeClass">{{ certificate.status_label }}</span>
        </div>
      </div>

      <div class="flex shrink-0 flex-wrap gap-2">
        <a
          :href="certificate.download_url"
          class="zaqa-btn zaqa-btn-secondary inline-flex items-center gap-1.5 px-4 py-2 text-sm"
        >
          <FileDown class="h-4 w-4" aria-hidden="true" />
          Download PDF
        </a>
        <a
          v-if="certificate.verification_url"
          :href="certificate.verification_url"
          target="_blank"
          rel="noopener"
          class="zaqa-btn zaqa-btn-secondary inline-flex items-center gap-1.5 px-4 py-2 text-sm"
        >
          <ExternalLink class="h-4 w-4" aria-hidden="true" />
          Verify page
        </a>
        <Link
          v-if="can.open_verification_task && certificate.verification_task_url"
          :href="certificate.verification_task_url"
          class="zaqa-btn zaqa-btn-secondary inline-flex items-center gap-1.5 px-4 py-2 text-sm"
        >
          Go to qualification
        </Link>
        <button
          v-if="certificate.revoke_url && can.revoke"
          type="button"
          class="zaqa-btn zaqa-btn-danger inline-flex items-center gap-1.5 px-4 py-2 text-sm"
          @click="openRevokeModal"
        >
          <Ban class="h-4 w-4" aria-hidden="true" />
          Revoke certificate
        </button>
      </div>
    </div>

    <div
      v-if="(page.props.flash as any)?.success"
      class="mt-4 rounded-xl border border-success/30 bg-success/10 px-4 py-3 text-sm text-success"
    >
      {{ (page.props.flash as any).success }}
    </div>

    <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-3">
      <div class="space-y-6 xl:col-span-1">
        <section class="rounded-2xl border border-border bg-surface p-5 shadow-sm">
          <h2 class="text-sm font-semibold uppercase tracking-wider text-text-muted">Certificate information</h2>
          <dl class="mt-4 space-y-4 text-sm">
            <div>
              <dt class="text-xs font-semibold uppercase tracking-wide text-text-muted">CVEQ number</dt>
              <dd class="mt-1 font-mono font-semibold text-text-primary">{{ certificate.certificate_number }}</dd>
            </div>
            <div>
              <dt class="text-xs font-semibold uppercase tracking-wide text-text-muted">Type</dt>
              <dd class="mt-1 text-text-primary">{{ certificate.certificate_type_label }}</dd>
            </div>
            <div>
              <dt class="text-xs font-semibold uppercase tracking-wide text-text-muted">Status</dt>
              <dd class="mt-1">
                <span class="zaqa-badge" :class="statusBadgeClass">{{ certificate.status_label }}</span>
              </dd>
            </div>
            <div>
              <dt class="text-xs font-semibold uppercase tracking-wide text-text-muted">Qualification</dt>
              <dd class="mt-1 text-text-primary">{{ certificate.qualification_title ?? '—' }}</dd>
            </div>
            <div>
              <dt class="text-xs font-semibold uppercase tracking-wide text-text-muted">Holder</dt>
              <dd class="mt-1 text-text-primary">{{ certificate.holder_name ?? '—' }}</dd>
            </div>
            <div>
              <dt class="text-xs font-semibold uppercase tracking-wide text-text-muted">Issued date</dt>
              <dd class="mt-1 text-text-primary">{{ formatDateTime(certificate.issued_at) }}</dd>
            </div>
          </dl>
        </section>

        <section v-if="isRevoked" class="rounded-2xl border border-danger/30 bg-danger/5 p-5 shadow-sm">
          <h2 class="text-sm font-semibold uppercase tracking-wider text-danger">Revocation information</h2>
          <dl class="mt-4 space-y-4 text-sm">
            <div>
              <dt class="text-xs font-semibold uppercase tracking-wide text-text-muted">Revoked date</dt>
              <dd class="mt-1 text-text-primary">{{ formatDateTime(certificate.revoked_at) }}</dd>
            </div>
            <div>
              <dt class="text-xs font-semibold uppercase tracking-wide text-text-muted">Revoked by</dt>
              <dd class="mt-1 text-text-primary">{{ certificate.revoked_by_name ?? '—' }}</dd>
            </div>
            <div>
              <dt class="text-xs font-semibold uppercase tracking-wide text-text-muted">Revocation reason</dt>
              <dd class="mt-1 whitespace-pre-wrap text-text-primary">{{ certificate.revocation_reason ?? '—' }}</dd>
            </div>
            <div v-if="certificate.revocation_public_note">
              <dt class="text-xs font-semibold uppercase tracking-wide text-text-muted">Public note</dt>
              <dd class="mt-1 whitespace-pre-wrap text-text-primary">{{ certificate.revocation_public_note }}</dd>
            </div>
          </dl>
        </section>
      </div>

      <section class="xl:col-span-2">
        <h2 class="text-sm font-semibold uppercase tracking-wider text-text-muted">Certificate preview</h2>
        <p class="mt-1 text-xs text-text-muted">Rendered from the stored certificate PDF.</p>
        <InlineDocumentPreview :document="preview_document" />
      </section>
    </div>

    <AdminActionModal
      v-model="revokeOpen"
      title="Revoke certificate"
      :description="`Revoke CVEQ ${certificate.certificate_number}. The certificate will remain in history but will no longer verify as valid publicly.`"
    >
      <div class="space-y-4">
        <div>
          <label class="text-sm font-semibold text-text-primary" for="revocation_reason">Internal reason (required)</label>
          <textarea
            id="revocation_reason"
            v-model="revokeForm.revocation_reason"
            rows="3"
            class="zaqa-input mt-2 w-full"
            placeholder="Why is this certificate being revoked?"
          />
          <div v-if="revokeForm.errors.revocation_reason" class="mt-1 text-xs text-danger">{{ revokeForm.errors.revocation_reason }}</div>
        </div>
        <div>
          <label class="text-sm font-semibold text-text-primary" for="revocation_public_note">Public note (optional)</label>
          <textarea
            id="revocation_public_note"
            v-model="revokeForm.revocation_public_note"
            rows="2"
            class="zaqa-input mt-2 w-full"
            placeholder="Shown on the public verification page if provided."
          />
          <div v-if="revokeForm.errors.revocation_public_note" class="mt-1 text-xs text-danger">{{ revokeForm.errors.revocation_public_note }}</div>
        </div>
        <label class="flex items-start gap-2 text-sm text-text-secondary">
          <input v-model="revokeForm.confirm" type="checkbox" class="mt-1 rounded border-border" />
          <span>I understand this certificate will no longer verify as valid publicly.</span>
        </label>
        <div v-if="revokeForm.errors.confirm" class="text-xs text-danger">{{ revokeForm.errors.confirm }}</div>
        <div v-if="revokeForm.errors.certificate" class="text-xs text-danger">{{ revokeForm.errors.certificate }}</div>
      </div>
      <template #footer>
        <button type="button" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm" @click="revokeOpen = false">Cancel</button>
        <button
          type="button"
          class="zaqa-btn zaqa-btn-danger px-4 py-2 text-sm"
          :disabled="revokeForm.processing"
          @click="submitRevoke"
        >
          Revoke certificate
        </button>
      </template>
    </AdminActionModal>
  </AdminLayout>
</template>
