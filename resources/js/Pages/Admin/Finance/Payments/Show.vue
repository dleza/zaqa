<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import AdminActionModal from '@/Components/AdminActionModal.vue'
import { Link, router } from '@inertiajs/vue3'
import { Banknote, FileText } from 'lucide-vue-next'
import { formatMoneyFromCents } from '@/utils/money'
import { computed, ref } from 'vue'

const props = defineProps<{
  payment: any
  webhooks: Array<any>
  can: {
    correct: boolean
    view_applicant: boolean
    view_qualifications: boolean
  }
  correction: {
    enabled: boolean
    disabled_reason: string | null
    status_options: Array<{ value: string; label: string }>
  }
  navigation: {
    applicant: { name: string | null; href: string | null } | null
    qualifications: Array<{
      id: number
      title: string | null
      holder_name: string | null
      is_foreign: boolean
      href: string | null
    }>
  }
  history: Array<any>
}>()

const correctionOpen = ref(false)
const correctionStatus = ref(props.correction.status_options[0]?.value ?? props.payment.status ?? '')
const correctionNote = ref('')
const correctionProviderTransactionId = ref(props.payment.provider_transaction_id ?? '')
const confirmingPayment = computed(() => correctionStatus.value === 'confirmed')
const hasNavigationLinks = computed(() => Boolean(props.navigation.applicant?.href) || props.navigation.qualifications.some((qualification) => Boolean(qualification.href)))

function badgeClass(s: string) {
  if (s === 'confirmed') return 'zaqa-badge-success'
  if (s === 'rejected' || s === 'failed' || s === 'expired' || s === 'unknown') return 'zaqa-badge-danger'
  if (s === 'awaiting_finance_review' || s === 'pending_confirmation' || s === 'initiated' || s === 'pending') return 'zaqa-badge-warning'
  return 'zaqa-badge-secondary'
}

function correctionSummary(entry: any) {
  const parts: string[] = []

  if ((entry.before_status ?? null) !== (entry.after_status ?? null) && entry.after_status) {
    parts.push(`${entry.before_status ?? '—'} -> ${entry.after_status}`)
  }

  if ((entry.before_provider_transaction_id ?? null) !== (entry.after_provider_transaction_id ?? null)) {
    parts.push(`TX: ${entry.before_provider_transaction_id ?? '—'} -> ${entry.after_provider_transaction_id ?? '—'}`)
  }

  return parts.length > 0 ? parts.join(' · ') : 'Recorded finance action'
}
</script>

<template>
  <AdminLayout>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <div class="inline-flex items-center gap-2 text-xs font-semibold text-text-muted">
          <Banknote class="h-4 w-4" aria-hidden="true" />
          Finance
        </div>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">Payment #{{ payment.id }}</h1>
        <p class="mt-1 text-sm text-text-muted">Provider status, invoice linkage, and proof (if manual).</p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <Link href="/admin/finance/payments" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm">Back to payments</Link>
        <Link v-if="payment.method === 'bank_deposit' || payment.method === 'bank_transfer'" :href="`/admin/finance/payment-proofs/${payment.id}`" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm">
          Proof detail
        </Link>
        <button
          v-if="can.correct && correction.enabled"
          type="button"
          class="zaqa-btn zaqa-btn-primary px-4 py-2 text-sm"
          @click="correctionOpen = true"
        >
          Update payment
        </button>
      </div>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
      <div class="lg:col-span-2 space-y-6">
        <div class="rounded-2xl border border-border bg-surface p-6 shadow-sm">
          <div class="flex items-start justify-between gap-4">
            <div>
              <div class="text-sm font-semibold text-text-primary">Summary</div>
              <div class="mt-1 text-lg font-semibold text-text-primary">{{ formatMoneyFromCents(payment.amount_cents, payment.currency) }}</div>
              <div class="mt-1 text-xs text-text-muted">Method: {{ payment.method.replaceAll('_', ' ') }} · Provider: {{ payment.provider ?? '—' }}</div>
            </div>
            <span class="zaqa-badge" :class="badgeClass(payment.status)">{{ payment.status }}</span>
          </div>

          <div class="mt-4 grid gap-3 sm:grid-cols-2 text-sm">
            <div class="rounded-xl border border-border bg-surface-muted p-4">
              <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Application</div>
              <div class="mt-1 font-semibold text-text-primary">{{ payment.application?.application_number ?? '—' }}</div>
              <div class="mt-1 text-xs text-text-muted">{{ payment.application?.is_foreign ? 'Foreign' : 'Local' }}</div>
            </div>
            <div class="rounded-xl border border-border bg-surface-muted p-4">
              <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Invoice</div>
              <div class="mt-1 font-semibold text-text-primary">{{ payment.invoice?.invoice_number ?? '—' }}</div>
              <div class="mt-1 text-xs text-text-muted">Invoice status: {{ payment.invoice?.status ?? '—' }}</div>
            </div>
            <div class="rounded-xl border border-border bg-surface-muted p-4">
              <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Applicant</div>
              <div class="mt-1 font-semibold text-text-primary">{{ payment.applicant?.name ?? '—' }}</div>
              <div class="mt-1 text-xs text-text-muted">{{ payment.applicant?.email ?? payment.applicant?.phone ?? '—' }}</div>
              <div v-if="navigation.applicant?.href" class="mt-3">
                <Link :href="navigation.applicant.href" class="zaqa-btn zaqa-btn-secondary h-9 px-3 py-2 text-xs">
                  Open applicant
                </Link>
              </div>
            </div>
            <div class="rounded-xl border border-border bg-surface-muted p-4">
              <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">References</div>
              <div class="mt-1 text-xs text-text-muted">Ref: {{ payment.provider_reference ?? '—' }}</div>
              <div class="mt-1 text-xs text-text-muted">TX: {{ payment.provider_transaction_id ?? '—' }}</div>
            </div>
          </div>

          <div v-if="hasNavigationLinks" class="mt-4 rounded-xl border border-border bg-surface-muted p-4">
            <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Qualifications</div>
            <div v-if="navigation.qualifications.length === 0" class="mt-2 text-sm text-text-muted">No qualifications linked to this application.</div>
            <div v-else class="mt-3 space-y-2">
              <div
                v-for="qualification in navigation.qualifications"
                :key="qualification.id"
                class="flex flex-col gap-2 rounded-lg border border-border/80 bg-surface px-3 py-3 sm:flex-row sm:items-center sm:justify-between"
              >
                <div>
                  <div class="text-sm font-semibold text-text-primary">{{ qualification.title ?? `Qualification #${qualification.id}` }}</div>
                  <div class="mt-1 text-xs text-text-muted">
                    {{ qualification.is_foreign ? 'Foreign' : 'Local' }} · {{ qualification.holder_name ?? '—' }}
                  </div>
                </div>
                <Link
                  v-if="qualification.href"
                  :href="qualification.href"
                  class="zaqa-btn zaqa-btn-secondary h-9 px-3 py-2 text-xs"
                >
                  Open qualification
                </Link>
              </div>
            </div>
          </div>
        </div>

        <div class="rounded-2xl border border-border bg-surface p-6 shadow-sm">
          <div class="text-sm font-semibold text-text-primary">Timeline (payment)</div>
          <div class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
            <div class="rounded-xl border border-border bg-surface-muted p-4">
              <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Initiated</div>
              <div class="mt-1 font-semibold text-text-primary">{{ payment.initiated_at ? new Date(payment.initiated_at).toLocaleString() : '—' }}</div>
            </div>
            <div class="rounded-xl border border-border bg-surface-muted p-4">
              <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Confirmed</div>
              <div class="mt-1 font-semibold text-text-primary">{{ payment.confirmed_at ? new Date(payment.confirmed_at).toLocaleString() : '—' }}</div>
            </div>
            <div class="rounded-xl border border-border bg-surface-muted p-4">
              <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Failed</div>
              <div class="mt-1 font-semibold text-text-primary">{{ payment.failed_at ? new Date(payment.failed_at).toLocaleString() : '—' }}</div>
            </div>
            <div class="rounded-xl border border-border bg-surface-muted p-4">
              <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Rejected</div>
              <div class="mt-1 font-semibold text-text-primary">{{ payment.rejected_at ? new Date(payment.rejected_at).toLocaleString() : '—' }}</div>
            </div>
          </div>

          <div v-if="payment.reviewed_at || payment.review_comment || payment.rejection_reason" class="mt-4 rounded-xl border border-border bg-surface-muted p-4 text-sm">
            <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Manual review</div>
            <div class="mt-1 text-sm text-text-primary">Reviewed at: {{ payment.reviewed_at ? new Date(payment.reviewed_at).toLocaleString() : '—' }}</div>
            <div class="mt-1 text-sm text-text-primary">Reviewer: {{ payment.reviewed_by ?? '—' }}</div>
            <div v-if="payment.review_comment" class="mt-2 whitespace-pre-wrap text-sm text-text-primary">Comment: {{ payment.review_comment }}</div>
            <div v-if="payment.rejection_reason" class="mt-2 whitespace-pre-wrap text-sm text-danger">Reason: {{ payment.rejection_reason }}</div>
          </div>

          <div v-if="can.correct && correction.disabled_reason" class="mt-4 rounded-xl border border-amber-300/40 bg-amber-500/10 p-4 text-sm text-text-primary">
            <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Correction unavailable</div>
            <div class="mt-2">{{ correction.disabled_reason }}</div>
          </div>
        </div>

        <div v-if="(payment.attempts ?? []).length > 0" class="rounded-2xl border border-border bg-surface p-6 shadow-sm">
          <div class="text-sm font-semibold text-text-primary">Gateway attempts</div>
          <div class="mt-3 overflow-x-auto">
            <table class="min-w-full text-sm">
              <thead class="bg-surface-muted text-xs font-semibold text-text-muted">
                <tr>
                  <th class="px-4 py-3 text-left">ID</th>
                  <th class="px-4 py-3 text-left">Gateway</th>
                  <th class="px-4 py-3 text-left">Reference</th>
                  <th class="px-4 py-3 text-left">Mobile</th>
                  <th class="px-4 py-3 text-left">Status</th>
                  <th class="px-4 py-3 text-left">Code</th>
                  <th class="px-4 py-3 text-left">Message</th>
                  <th class="px-4 py-3 text-left">Queried</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-border/60">
                <tr v-for="a in payment.attempts" :key="a.id">
                  <td class="px-4 py-3 font-semibold text-text-primary">#{{ a.id }}</td>
                  <td class="px-4 py-3 text-text-primary">{{ a.gateway }}</td>
                  <td class="px-4 py-3 text-xs text-text-muted">{{ a.payment_reference ?? '—' }}</td>
                  <td class="px-4 py-3 text-xs text-text-muted">{{ a.mobile_number ?? '—' }}</td>
                  <td class="px-4 py-3">
                    <span class="zaqa-badge" :class="badgeClass(a.status)">{{ a.status }}</span>
                  </td>
                  <td class="px-4 py-3 text-xs text-text-muted">{{ a.response_code ?? '—' }}</td>
                  <td class="px-4 py-3 text-xs text-text-muted">
                    <span v-if="a.response_message">{{ a.response_message }}</span>
                    <span v-else>—</span>
                  </td>
                  <td class="px-4 py-3 text-xs text-text-muted">
                    {{ a.last_queried_at ? new Date(a.last_queried_at).toLocaleString() : '—' }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div class="rounded-2xl border border-border bg-surface p-6 shadow-sm">
          <div class="text-sm font-semibold text-text-primary">Webhook / return events</div>
          <div v-if="webhooks.length === 0" class="mt-3 text-sm text-text-muted">No webhook/return logs recorded for this payment.</div>
          <div v-else class="mt-3 overflow-x-auto">
            <table class="min-w-full text-sm">
              <thead class="bg-surface-muted text-xs font-semibold text-text-muted">
                <tr>
                  <th class="px-4 py-3 text-left">Provider</th>
                  <th class="px-4 py-3 text-left">Event</th>
                  <th class="px-4 py-3 text-left">Status</th>
                  <th class="px-4 py-3 text-left">Received</th>
                  <th class="px-4 py-3 text-left">Processed</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-border/60">
                <tr v-for="w in webhooks" :key="w.id">
                  <td class="px-4 py-3 font-semibold text-text-primary">{{ w.provider }}</td>
                  <td class="px-4 py-3 text-text-primary">{{ w.event_type }}</td>
                  <td class="px-4 py-3 text-text-primary">{{ w.process_status }}</td>
                  <td class="px-4 py-3 text-xs text-text-muted">{{ w.received_at ? new Date(w.received_at).toLocaleString() : '—' }}</td>
                  <td class="px-4 py-3 text-xs text-text-muted">{{ w.processed_at ? new Date(w.processed_at).toLocaleString() : '—' }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div class="rounded-2xl border border-border bg-surface p-6 shadow-sm">
          <div class="text-sm font-semibold text-text-primary">Finance action history</div>
          <div v-if="history.length === 0" class="mt-3 text-sm text-text-muted">No finance status corrections or review actions recorded yet.</div>
          <div v-else class="mt-4 space-y-3">
            <div
              v-for="entry in history"
              :key="entry.id"
              class="rounded-xl border border-border bg-surface-muted p-4"
            >
              <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                <div>
                  <div class="text-sm font-semibold text-text-primary">{{ entry.message }}</div>
                  <div class="mt-1 text-xs text-text-muted">
                    {{ correctionSummary(entry) }}
                  </div>
                </div>
                <div class="text-xs text-text-muted sm:text-right">
                  <div>{{ entry.actor_name ?? 'System' }}</div>
                  <div>{{ entry.created_at ? new Date(entry.created_at).toLocaleString() : '—' }}</div>
                </div>
              </div>
              <div v-if="entry.note" class="mt-3 rounded-lg border border-border/80 bg-surface px-3 py-2 text-sm text-text-primary">
                {{ entry.note }}
              </div>
            </div>
          </div>
        </div>
      </div>

      <aside class="space-y-6">
        <div class="rounded-2xl border border-border bg-surface p-6 shadow-sm">
          <div class="text-sm font-semibold text-text-primary">Proof (manual)</div>
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
          <div v-else class="mt-3 text-sm text-text-muted">No proof document for this payment.</div>
        </div>

        <div class="rounded-2xl border border-border bg-surface p-6 shadow-sm">
          <div class="text-sm font-semibold text-text-primary">Raw payload</div>
          <div v-if="payment.raw_payload" class="mt-3 overflow-hidden rounded-xl border border-border bg-surface-muted">
            <pre class="max-h-[22rem] overflow-auto p-4 text-xs text-text-primary">{{ JSON.stringify(payment.raw_payload, null, 2) }}</pre>
          </div>
          <div v-else class="mt-3 text-sm text-text-muted">No raw payload stored.</div>
        </div>
      </aside>
    </div>

    <AdminActionModal
      v-model="correctionOpen"
      title="Update payment"
      description="Manually update the recorded payment status or transaction ID. Confirming a payment requires the provider transaction ID and will move the application into the submission flow."
    >
      <div class="space-y-4">
        <div>
          <label class="text-sm font-semibold text-text-primary">Target status</label>
          <select v-model="correctionStatus" class="zaqa-input mt-2 h-11">
            <option v-for="option in correction.status_options" :key="option.value" :value="option.value">
              {{ option.label }}
            </option>
          </select>
        </div>

        <div>
          <label class="text-sm font-semibold text-text-primary">
            Provider transaction ID
            <span v-if="confirmingPayment" class="text-danger">*</span>
          </label>
          <input
            v-model="correctionProviderTransactionId"
            type="text"
            class="zaqa-input mt-2 h-11"
            :placeholder="confirmingPayment ? 'Required when confirming this payment' : 'Optional transaction ID override'"
          />
          <div v-if="confirmingPayment" class="mt-2 text-xs text-text-muted">
            This is required before the payment can be marked as confirmed.
          </div>
        </div>

        <div>
          <label class="text-sm font-semibold text-text-primary">Correction note</label>
          <textarea
            v-model="correctionNote"
            class="zaqa-input mt-2 h-auto min-h-[8rem] py-3"
            placeholder="Explain why this payment record is being corrected."
          />
        </div>
      </div>

      <template #footer>
        <button type="button" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm" @click="correctionOpen = false">Cancel</button>
        <button
          type="button"
          class="zaqa-btn zaqa-btn-primary px-4 py-2 text-sm"
          :disabled="!correctionStatus || !correctionNote.trim() || (confirmingPayment && !correctionProviderTransactionId.trim())"
          @click="router.post(`/admin/finance/payments/${payment.id}/correct`, {
            status: correctionStatus,
            note: correctionNote,
            provider_transaction_id: correctionProviderTransactionId || null,
          }, {
            preserveScroll: true,
            onSuccess: () => {
              correctionOpen = false
            },
          })"
        >
          Save correction
        </button>
      </template>
    </AdminActionModal>
  </AdminLayout>
</template>
