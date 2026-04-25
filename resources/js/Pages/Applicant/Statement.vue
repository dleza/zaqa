<script setup lang="ts">
import ApplicantLayout from '@/Layouts/ApplicantLayout.vue'
import { Link } from '@inertiajs/vue3'
import { computed } from 'vue'
import { CheckCircle2, AlertCircle, ArrowDownToLine, Eye } from 'lucide-vue-next'

const props = defineProps<{
  payments: Array<{
    id: number
    method: string
    status: string
    currency: string
    amount_cents: number
    provider: string
    provider_reference: string | null
    created_at: string | null
    confirmed_at: string | null
    rejection_reason: string | null
    application: { id: number; application_number: string } | null
    invoice: { id: number; invoice_number: string } | null
    proof_document: { id: number; preview_url: string; download_url: string } | null
  }>
  summary: { total_cents: number; confirmed_cents: number; count: number }
}>()

const hasItems = computed(() => props.payments && props.payments.length > 0)

function money(cents: number, currency: string) {
  const value = (cents ?? 0) / 100
  return new Intl.NumberFormat(undefined, { style: 'currency', currency: currency || 'ZMW' }).format(value)
}
</script>

<template>
  <ApplicantLayout>
    <div>
      <h2 class="text-xl font-semibold">Statement</h2>
      <p class="mt-1 text-sm text-text-muted">Review payments and receipts across your applications.</p>

      <div class="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-3">
        <div class="rounded-xl border border-border bg-surface p-4">
          <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Payments</div>
          <div class="mt-1 text-lg font-semibold text-text-primary">{{ summary.count }}</div>
        </div>
        <div class="rounded-xl border border-border bg-surface p-4">
          <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Confirmed total</div>
          <div class="mt-1 text-lg font-semibold text-text-primary">{{ money(summary.confirmed_cents, 'ZMW') }}</div>
        </div>
        <div class="rounded-xl border border-border bg-surface p-4">
          <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">All attempts total</div>
          <div class="mt-1 text-lg font-semibold text-text-primary">{{ money(summary.total_cents, 'ZMW') }}</div>
        </div>
      </div>

      <div v-if="!hasItems" class="zaqa-card-muted mt-6 text-sm text-text-muted">
        No statement items are available yet.
      </div>

      <div v-else class="mt-6">
        <!-- Desktop table -->
        <div class="hidden overflow-hidden rounded-xl border border-border bg-surface md:block">
          <table class="min-w-full divide-y divide-border/60">
            <thead class="bg-surface-muted">
              <tr class="text-left text-xs font-semibold uppercase tracking-wider text-text-muted">
                <th class="px-4 py-3">Status</th>
                <th class="px-4 py-3">Method</th>
                <th class="px-4 py-3">Amount</th>
                <th class="px-4 py-3">Application</th>
                <th class="px-4 py-3">Invoice</th>
                <th class="px-4 py-3">When</th>
                <th class="px-4 py-3">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border/60">
              <tr v-for="p in payments" :key="p.id" class="hover:bg-surface-muted/60">
                <td class="px-4 py-3 text-sm">
                  <span class="zaqa-badge" :class="p.status === 'confirmed' ? 'zaqa-badge-success' : p.status === 'rejected' || p.status === 'failed' ? 'zaqa-badge-danger' : 'zaqa-badge-warning'">
                    <component :is="p.status === 'confirmed' ? CheckCircle2 : AlertCircle" class="h-4 w-4" aria-hidden="true" />
                    {{ p.status }}
                  </span>
                </td>
                <td class="px-4 py-3 text-sm text-text-primary">{{ p.method }}</td>
                <td class="px-4 py-3 text-sm text-text-primary">{{ money(p.amount_cents, p.currency) }}</td>
                <td class="px-4 py-3 text-sm">
                  <Link v-if="p.application" :href="`/applicant/applications/${p.application.id}`" class="zaqa-link">
                    {{ p.application.application_number }}
                  </Link>
                  <span v-else class="text-text-muted">—</span>
                </td>
                <td class="px-4 py-3 text-sm text-text-muted">{{ p.invoice?.invoice_number ?? '—' }}</td>
                <td class="px-4 py-3 text-sm text-text-muted">{{ p.confirmed_at ? new Date(p.confirmed_at).toLocaleString() : p.created_at ? new Date(p.created_at).toLocaleString() : '—' }}</td>
                <td class="px-4 py-3 text-sm">
                  <div class="flex flex-wrap gap-2">
                    <a v-if="p.proof_document" :href="p.proof_document.preview_url" target="_blank" rel="noopener" class="zaqa-link inline-flex items-center gap-1">
                      <Eye class="h-4 w-4" aria-hidden="true" />
                      Preview
                    </a>
                    <a v-if="p.proof_document" :href="p.proof_document.download_url" target="_blank" rel="noopener" class="zaqa-link inline-flex items-center gap-1">
                      <ArrowDownToLine class="h-4 w-4" aria-hidden="true" />
                      Download
                    </a>
                    <span v-if="p.rejection_reason" class="text-xs text-danger">Rejected: {{ p.rejection_reason }}</span>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Mobile cards -->
        <div class="space-y-3 md:hidden">
          <div v-for="p in payments" :key="p.id" class="rounded-xl border border-border bg-surface p-4">
            <div class="flex items-start justify-between gap-4">
              <div>
                <div class="text-sm font-semibold text-text-primary">{{ p.method }}</div>
                <div class="mt-1 text-xs text-text-muted">{{ p.application?.application_number ?? '—' }}</div>
              </div>
              <span class="zaqa-badge" :class="p.status === 'confirmed' ? 'zaqa-badge-success' : p.status === 'rejected' || p.status === 'failed' ? 'zaqa-badge-danger' : 'zaqa-badge-warning'">
                {{ p.status }}
              </span>
            </div>

            <div class="mt-3 grid grid-cols-2 gap-3 text-xs text-text-muted">
              <div>
                <div class="font-semibold uppercase tracking-wider">Amount</div>
                <div class="mt-1 text-sm text-text-primary">{{ money(p.amount_cents, p.currency) }}</div>
              </div>
              <div>
                <div class="font-semibold uppercase tracking-wider">When</div>
                <div class="mt-1 text-sm text-text-primary">{{ p.confirmed_at ? new Date(p.confirmed_at).toLocaleDateString() : p.created_at ? new Date(p.created_at).toLocaleDateString() : '—' }}</div>
              </div>
            </div>

            <div class="mt-3 flex flex-wrap gap-2">
              <Link v-if="p.application" :href="`/applicant/applications/${p.application.id}`" class="zaqa-btn zaqa-btn-secondary px-3 py-2 text-xs">View application</Link>
              <a v-if="p.proof_document" :href="p.proof_document.preview_url" target="_blank" rel="noopener" class="zaqa-btn zaqa-btn-secondary px-3 py-2 text-xs">Preview proof</a>
              <a v-if="p.proof_document" :href="p.proof_document.download_url" target="_blank" rel="noopener" class="zaqa-btn zaqa-btn-secondary px-3 py-2 text-xs">Download proof</a>
            </div>

            <div v-if="p.rejection_reason" class="mt-3 rounded-lg border border-danger/20 bg-danger/10 px-3 py-2 text-xs text-danger">
              Rejected: {{ p.rejection_reason }}
            </div>
          </div>
        </div>
      </div>
    </div>
  </ApplicantLayout>
</template>

