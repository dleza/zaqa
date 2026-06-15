<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import AdminActionModal from '@/Components/AdminActionModal.vue'
import { Link, router } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import { Banknote, CheckCircle2, FileText, XCircle } from 'lucide-vue-next'
import { formatMoneyFromCents } from '@/utils/money'

const props = defineProps<{
  payment: any
  can: { approve: boolean; reject: boolean }
}>()

function badgeClass(s: string) {
  if (s === 'confirmed') return 'zaqa-badge-success'
  if (s === 'rejected' || s === 'failed') return 'zaqa-badge-danger'
  if (s === 'awaiting_finance_review') return 'zaqa-badge-warning'
  return 'zaqa-badge-secondary'
}

const approveOpen = ref(false)
const rejectOpen = ref(false)
const approveComment = ref('')
const rejectReason = ref('')

const canAct = computed(() => props.payment.status === 'awaiting_finance_review')
</script>

<template>
  <AdminLayout>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <div class="inline-flex items-center gap-2 text-xs font-semibold text-text-muted">
          <Banknote class="h-4 w-4" aria-hidden="true" />
          Finance
        </div>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">Payment proof detail</h1>
        <p class="mt-1 text-sm text-text-muted">Review the proof, invoice, and payment status.</p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <Link href="/admin/finance/payment-proofs" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm">Back to queue</Link>
        <Link :href="`/admin/finance/payments/${payment.id}`" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm">View as payment</Link>
      </div>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
      <div class="lg:col-span-2 space-y-6">
        <div class="rounded-2xl border border-border bg-surface p-6 shadow-sm">
          <div class="flex items-start justify-between gap-4">
            <div>
              <div class="text-sm font-semibold text-text-primary">Application</div>
              <div class="mt-1 text-lg font-semibold text-text-primary">{{ payment.application?.application_number ?? '—' }}</div>
              <div class="mt-1 text-xs text-text-muted">{{ payment.application?.is_foreign ? 'Foreign' : 'Local' }}</div>
            </div>
            <span class="zaqa-badge" :class="badgeClass(payment.status)">{{ payment.status }}</span>
          </div>
        </div>

        <div class="rounded-2xl border border-border bg-surface p-6 shadow-sm">
          <div class="text-sm font-semibold text-text-primary">Applicant</div>
          <div class="mt-3 grid gap-3 sm:grid-cols-2 text-sm">
            <div class="rounded-xl border border-border bg-surface-muted p-4">
              <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Name</div>
              <div class="mt-1 font-semibold text-text-primary">{{ payment.applicant?.name ?? '—' }}</div>
            </div>
            <div class="rounded-xl border border-border bg-surface-muted p-4">
              <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Contact</div>
              <div class="mt-1 text-sm text-text-primary">{{ payment.applicant?.email ?? '—' }}</div>
              <div class="mt-1 text-xs text-text-muted">{{ payment.applicant?.phone ?? '—' }}</div>
            </div>
          </div>
        </div>

        <div class="rounded-2xl border border-border bg-surface p-6 shadow-sm">
          <div class="text-sm font-semibold text-text-primary">Invoice & payment</div>
          <div class="mt-3 grid gap-3 sm:grid-cols-2 text-sm">
            <div class="rounded-xl border border-border bg-surface-muted p-4">
              <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Invoice</div>
              <div class="mt-1 font-semibold text-text-primary">{{ payment.invoice?.invoice_number ?? '—' }}</div>
              <div class="mt-1 text-xs text-text-muted">Status: {{ payment.invoice?.status ?? '—' }}</div>
              <a
                v-if="payment.invoice?.download_url"
                :href="payment.invoice.download_url"
                class="zaqa-btn zaqa-btn-secondary mt-3 inline-flex items-center gap-2 px-3 py-1.5 text-xs"
              >
                Download invoice
              </a>
              <a
                v-if="payment.receipt_download_url"
                :href="payment.receipt_download_url"
                class="zaqa-btn zaqa-btn-secondary mt-3 inline-flex items-center gap-2 px-3 py-1.5 text-xs"
              >
                Download receipt
              </a>
            </div>
            <div class="rounded-xl border border-border bg-surface-muted p-4">
              <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Amount</div>
              <div class="mt-1 font-semibold text-text-primary">{{ formatMoneyFromCents(payment.amount_cents, payment.currency) }}</div>
              <div class="mt-1 text-xs text-text-muted">Method: {{ (payment.method ?? '').replaceAll('_', ' ') }}</div>
            </div>
          </div>
          <div v-if="payment.reviewed_at || payment.rejection_reason || payment.review_comment" class="mt-4 rounded-xl border border-border bg-surface-muted p-4 text-sm">
            <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Review</div>
            <div class="mt-1 text-sm text-text-primary">Reviewed at: {{ payment.reviewed_at ? new Date(payment.reviewed_at).toLocaleString() : '—' }}</div>
            <div class="mt-1 text-sm text-text-primary">Reviewer: {{ payment.reviewed_by ?? '—' }}</div>
            <div v-if="payment.review_comment" class="mt-2 whitespace-pre-wrap text-sm text-text-primary">Comment: {{ payment.review_comment }}</div>
            <div v-if="payment.rejection_reason" class="mt-2 whitespace-pre-wrap text-sm text-danger">Reason: {{ payment.rejection_reason }}</div>
          </div>
        </div>
      </div>

      <aside class="space-y-6">
        <div class="rounded-2xl border border-border bg-surface p-6 shadow-sm">
          <div class="text-sm font-semibold text-text-primary">Proof document</div>
          <div v-if="payment.proof_document" class="mt-3">
            <div class="rounded-xl border border-border bg-surface-muted p-4 text-sm">
              <div class="font-semibold text-text-primary">{{ payment.proof_document.original_name }}</div>
              <div class="mt-3 flex flex-wrap gap-2">
                <a :href="payment.proof_document.preview_url" target="_blank" rel="noopener" class="zaqa-btn zaqa-btn-secondary h-9 px-3 py-2 text-xs">
                  <FileText class="h-4 w-4" aria-hidden="true" />
                  Preview
                </a>
                <a :href="payment.proof_document.download_url" class="zaqa-btn zaqa-btn-secondary h-9 px-3 py-2 text-xs">Download</a>
              </div>
            </div>
          </div>
          <div v-else class="mt-3 text-sm text-text-muted">No proof document attached.</div>
        </div>

        <div class="rounded-2xl border border-border bg-surface p-6 shadow-sm">
          <div class="text-sm font-semibold text-text-primary">Actions</div>
          <div class="mt-4 space-y-2">
            <button
              v-if="can.approve && canAct"
              type="button"
              class="zaqa-btn w-full justify-center border border-emerald-300/40 bg-emerald-500/15 text-emerald-900 hover:bg-emerald-500/20"
              @click="approveOpen = true"
            >
              <CheckCircle2 class="h-4 w-4" aria-hidden="true" />
              Approve proof
            </button>
            <button
              v-if="can.reject && canAct"
              type="button"
              class="zaqa-btn w-full justify-center border border-red-300/40 bg-red-500/15 text-red-900 hover:bg-red-500/20"
              @click="rejectOpen = true"
            >
              <XCircle class="h-4 w-4" aria-hidden="true" />
              Reject proof
            </button>
            <div v-if="!canAct" class="text-xs text-text-muted">This payment is no longer awaiting finance review.</div>
          </div>
        </div>
      </aside>
    </div>

    <AdminActionModal v-model="approveOpen" title="Approve payment proof" description="This will confirm payment and settle the invoice.">
      <div>
        <label class="text-sm font-semibold text-text-primary">Comment (optional)</label>
        <textarea v-model="approveComment" class="zaqa-input mt-2 h-auto min-h-[6rem] py-3" placeholder="Optional note for audit/history." />
      </div>
      <template #footer>
        <button type="button" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm" @click="approveOpen = false">Cancel</button>
        <button
          type="button"
          class="zaqa-btn zaqa-btn-primary px-4 py-2 text-sm"
          @click="router.post(`/admin/finance/payment-proofs/${payment.id}/approve`, { comment: approveComment || null }, { preserveScroll: true, onSuccess: () => (approveOpen = false) })"
        >
          Approve
        </button>
      </template>
    </AdminActionModal>

    <AdminActionModal v-model="rejectOpen" title="Reject payment proof" description="A clear rejection reason is required and will be communicated to the applicant.">
      <div>
        <label class="text-sm font-semibold text-text-primary">Rejection reason</label>
        <textarea v-model="rejectReason" class="zaqa-input mt-2 h-auto min-h-[10rem] py-3" placeholder="Explain what is wrong and what to upload next." />
      </div>
      <template #footer>
        <button type="button" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm" @click="rejectOpen = false">Cancel</button>
        <button
          type="button"
          class="zaqa-btn zaqa-btn-primary px-4 py-2 text-sm"
          :disabled="!rejectReason.trim()"
          @click="router.post(`/admin/finance/payment-proofs/${payment.id}/reject`, { reason: rejectReason }, { preserveScroll: true, onSuccess: () => (rejectOpen = false) })"
        >
          Reject
        </button>
      </template>
    </AdminActionModal>
  </AdminLayout>
</template>

