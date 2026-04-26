<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import AdminActionModal from '@/Components/AdminActionModal.vue'
import AdminPagination from '@/Components/AdminPagination.vue'
import { Link, router } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue'
import { Banknote, CheckCircle2, FileText, Search, XCircle } from 'lucide-vue-next'
import { formatMoneyFromCents } from '@/utils/money'

type Paged<T> = {
  data: T[]
  links?: Array<any>
  meta?: { total?: number }
  total?: number
}

const props = defineProps<{
  filters: any
  payments: Paged<any>
}>()

const q = ref<string>(props.filters?.q ?? '')
const status = ref<string>(props.filters?.status ?? 'awaiting_finance_review')
const method = ref<string>(props.filters?.method ?? '')
const isForeign = ref<string>(props.filters?.is_foreign ?? '')
const uploadedFrom = ref<string>(props.filters?.uploaded_from ?? '')
const uploadedTo = ref<string>(props.filters?.uploaded_to ?? '')
const amountMin = ref<string>(props.filters?.amount_min ?? '')
const amountMax = ref<string>(props.filters?.amount_max ?? '')

watch([q, status, method, isForeign, uploadedFrom, uploadedTo, amountMin, amountMax], () => {
  router.get(
    '/admin/finance/payment-proofs',
    {
      q: q.value || null,
      status: status.value || null,
      method: method.value || null,
      is_foreign: isForeign.value || null,
      uploaded_from: uploadedFrom.value || null,
      uploaded_to: uploadedTo.value || null,
      amount_min: amountMin.value || null,
      amount_max: amountMax.value || null,
    },
    { preserveScroll: true, preserveState: true, replace: true },
  )
})

function badgeClass(s: string) {
  if (s === 'confirmed') return 'zaqa-badge-success'
  if (s === 'rejected' || s === 'failed') return 'zaqa-badge-danger'
  if (s === 'awaiting_finance_review') return 'zaqa-badge-warning'
  return 'zaqa-badge-secondary'
}

const totalCount = computed(() => {
  const p: any = props.payments as any
  const total = p?.meta?.total ?? p?.total
  return typeof total === 'number' ? total : Array.isArray(p?.data) ? p.data.length : 0
})

const approveOpen = ref(false)
const rejectOpen = ref(false)
const selected = ref<any | null>(null)
const approveComment = ref<string>('')
const rejectReason = ref<string>('')

function openApprove(p: any) {
  selected.value = p
  approveComment.value = ''
  approveOpen.value = true
}

function openReject(p: any) {
  selected.value = p
  rejectReason.value = ''
  rejectOpen.value = true
}

const canAct = computed(() => (p: any) => p.status === 'awaiting_finance_review')
</script>

<template>
  <AdminLayout>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <div class="inline-flex items-center gap-2 text-xs font-semibold text-text-muted">
          <Banknote class="h-4 w-4" aria-hidden="true" />
          Finance
        </div>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">Payment proof review</h1>
        <p class="mt-1 text-sm text-text-muted">Review bank deposit and bank transfer proof uploads.</p>
      </div>
      <div class="flex items-center gap-2">
        <Link href="/admin/finance" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm">Back to finance</Link>
        <Link href="/admin/finance/payments" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm">Processed payments</Link>
      </div>
    </div>

    <div class="mt-6 grid gap-3 rounded-2xl border border-border bg-surface p-4 shadow-sm lg:grid-cols-6">
      <label class="lg:col-span-2">
        <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Search</div>
        <div class="mt-2 flex items-center gap-2 rounded-xl border border-border bg-surface-muted px-3 py-2">
          <Search class="h-4 w-4 text-text-muted" aria-hidden="true" />
          <input v-model="q" class="w-full bg-transparent text-sm outline-none" placeholder="Applicant, application #, invoice #, reference…" />
        </div>
      </label>
      <label>
        <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Status</div>
        <select v-model="status" class="zaqa-input mt-2 h-10">
          <option value="awaiting_finance_review">Awaiting review</option>
          <option value="confirmed">Approved/confirmed</option>
          <option value="rejected">Rejected</option>
        </select>
      </label>
      <label>
        <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Method</div>
        <select v-model="method" class="zaqa-input mt-2 h-10">
          <option value="">All</option>
          <option value="bank_deposit">Bank deposit</option>
          <option value="bank_transfer">Bank transfer</option>
        </select>
      </label>
      <label>
        <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Local/Foreign</div>
        <select v-model="isForeign" class="zaqa-input mt-2 h-10">
          <option value="">All</option>
          <option value="0">Local</option>
          <option value="1">Foreign</option>
        </select>
      </label>
      <label>
        <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Uploaded from</div>
        <input v-model="uploadedFrom" type="date" class="zaqa-input mt-2 h-10" />
      </label>
      <label>
        <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Uploaded to</div>
        <input v-model="uploadedTo" type="date" class="zaqa-input mt-2 h-10" />
      </label>
      <label>
        <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Amount min (ZMW)</div>
        <input v-model="amountMin" inputmode="decimal" class="zaqa-input mt-2 h-10" placeholder="e.g. 50.00" />
      </label>
      <label>
        <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Amount max (ZMW)</div>
        <input v-model="amountMax" inputmode="decimal" class="zaqa-input mt-2 h-10" placeholder="e.g. 500.00" />
      </label>
      <div class="lg:col-span-6 text-xs text-text-muted self-end">Showing {{ totalCount }} record(s).</div>
    </div>

    <div class="mt-6 overflow-hidden rounded-2xl border border-border bg-surface shadow-sm">
      <div v-if="payments.data.length === 0" class="p-6 text-sm text-text-muted">No proofs found for the selected filters.</div>
      <div v-else class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-surface-muted text-xs font-semibold text-text-muted">
            <tr>
              <th class="px-5 py-3 text-left">Application</th>
              <th class="px-5 py-3 text-left">Applicant</th>
              <th class="px-5 py-3 text-left">Invoice</th>
              <th class="px-5 py-3 text-left">Method</th>
              <th class="px-5 py-3 text-right">Amount</th>
              <th class="px-5 py-3 text-left">Proof</th>
              <th class="px-5 py-3 text-left">Status</th>
              <th class="px-5 py-3 text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border/60">
            <tr v-for="p in payments.data" :key="p.id">
              <td class="px-5 py-3">
                <div class="font-semibold text-text-primary">{{ p.application?.application_number ?? `#${p.application?.id ?? p.id}` }}</div>
                <div class="mt-0.5 text-xs text-text-muted">{{ p.application?.is_foreign ? 'Foreign' : 'Local' }}</div>
              </td>
              <td class="px-5 py-3">
                <div class="font-semibold text-text-primary">{{ p.applicant?.name ?? '—' }}</div>
                <div class="mt-0.5 text-xs text-text-muted">{{ p.applicant?.email ?? p.applicant?.phone ?? '—' }}</div>
              </td>
              <td class="px-5 py-3">
                <div class="font-semibold text-text-primary">{{ p.invoice?.invoice_number ?? '—' }}</div>
                <div v-if="p.invoice?.status" class="mt-0.5 text-xs text-text-muted">Invoice: {{ p.invoice.status }}</div>
              </td>
              <td class="px-5 py-3 text-text-primary">{{ (p.method ?? '').replaceAll('_', ' ') }}</td>
              <td class="px-5 py-3 text-right font-semibold text-text-primary">{{ formatMoneyFromCents(p.amount_cents, p.currency) }}</td>
              <td class="px-5 py-3">
                <div v-if="p.proof_document" class="flex flex-wrap items-center gap-2">
                  <a :href="p.proof_document.preview_url" target="_blank" rel="noopener" class="zaqa-btn zaqa-btn-secondary h-9 px-3 py-2 text-xs">
                    <FileText class="h-4 w-4" aria-hidden="true" />
                    Preview
                  </a>
                  <a :href="p.proof_document.download_url" class="zaqa-btn zaqa-btn-secondary h-9 px-3 py-2 text-xs">Download</a>
                </div>
                <span v-else class="text-xs text-text-muted">—</span>
              </td>
              <td class="px-5 py-3">
                <span class="zaqa-badge" :class="badgeClass(p.status)">{{ p.status }}</span>
                <div v-if="p.reviewed_by" class="mt-1 text-xs text-text-muted">By {{ p.reviewed_by }}</div>
              </td>
              <td class="px-5 py-3 text-right">
                <div class="inline-flex flex-wrap justify-end gap-2">
                  <Link :href="`/admin/finance/payment-proofs/${p.id}`" class="zaqa-btn zaqa-btn-secondary h-9 px-3 py-2 text-xs">View</Link>
                  <button
                    v-if="canAct(p)"
                    type="button"
                    class="zaqa-btn h-9 border border-emerald-300/40 bg-emerald-500/15 px-3 py-2 text-xs font-semibold text-emerald-900 hover:bg-emerald-500/20"
                    @click="openApprove(p)"
                  >
                    <CheckCircle2 class="h-4 w-4" aria-hidden="true" />
                    Approve
                  </button>
                  <button
                    v-if="canAct(p)"
                    type="button"
                    class="zaqa-btn h-9 border border-red-300/40 bg-red-500/15 px-3 py-2 text-xs font-semibold text-red-900 hover:bg-red-500/20"
                    @click="openReject(p)"
                  >
                    <XCircle class="h-4 w-4" aria-hidden="true" />
                    Reject
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <AdminPagination :links="payments.links ?? []" />

    <AdminActionModal v-model="approveOpen" title="Approve payment proof" description="This will confirm payment and settle the invoice.">
      <div class="space-y-4">
        <div class="rounded-xl border border-border bg-surface-muted p-4 text-sm">
          <div class="font-semibold text-text-primary">Application {{ selected?.application?.application_number ?? '—' }}</div>
          <div class="mt-1 text-xs text-text-muted">Invoice {{ selected?.invoice?.invoice_number ?? '—' }} · {{ formatMoneyFromCents(selected?.amount_cents, selected?.currency) }}</div>
        </div>
        <div>
          <label class="text-sm font-semibold text-text-primary">Comment (optional)</label>
          <textarea v-model="approveComment" class="zaqa-input mt-2 h-auto min-h-[6rem] py-3" placeholder="Optional note for audit/history." />
        </div>
      </div>
      <template #footer>
        <button type="button" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm" @click="approveOpen = false">Cancel</button>
        <button
          type="button"
          class="zaqa-btn zaqa-btn-primary px-4 py-2 text-sm"
          @click="
            router.post(`/admin/finance/payment-proofs/${selected.id}/approve`, { comment: approveComment || null }, { preserveScroll: true, onSuccess: () => (approveOpen = false) })
          "
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
          @click="
            router.post(`/admin/finance/payment-proofs/${selected.id}/reject`, { reason: rejectReason }, { preserveScroll: true, onSuccess: () => (rejectOpen = false) })
          "
        >
          Reject
        </button>
      </template>
    </AdminActionModal>
  </AdminLayout>
</template>

