<script setup lang="ts">
import ApplicantLayout from '@/Layouts/ApplicantLayout.vue'
import { Link } from '@inertiajs/vue3'
import { computed } from 'vue'
import { FileText } from 'lucide-vue-next'

const props = defineProps<{
  invoices: Array<{
    id: number
    invoice_number: string
    currency: string
    amount_cents: number
    status: string
    issued_at: string | null
    paid_at: string | null
    application: { id: number; application_number: string; current_status: string } | null
  }>
}>()

const hasInvoices = computed(() => props.invoices && props.invoices.length > 0)

function money(cents: number, currency: string) {
  const value = (cents ?? 0) / 100
  return new Intl.NumberFormat(undefined, { style: 'currency', currency: currency || 'ZMW' }).format(value)
}
</script>

<template>
  <ApplicantLayout>
    <div>
      <h2 class="text-xl font-semibold">Invoices</h2>
      <p class="mt-1 text-sm text-text-muted">View invoices generated for your applications.</p>

      <div v-if="!hasInvoices" class="zaqa-card-muted mt-6 text-sm text-text-muted">
        No invoices are available yet.
      </div>

      <div v-else class="mt-6">
        <!-- Desktop table -->
        <div class="hidden overflow-hidden rounded-xl border border-border bg-surface md:block">
          <table class="min-w-full divide-y divide-border/60">
            <thead class="bg-surface-muted">
              <tr class="text-left text-xs font-semibold uppercase tracking-wider text-text-muted">
                <th class="px-4 py-3">Invoice</th>
                <th class="px-4 py-3">Application</th>
                <th class="px-4 py-3">Amount</th>
                <th class="px-4 py-3">Status</th>
                <th class="px-4 py-3">Issued</th>
                <th class="px-4 py-3">Paid</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border/60">
              <tr v-for="inv in invoices" :key="inv.id" class="hover:bg-surface-muted/60">
                <td class="px-4 py-3 text-sm font-semibold text-text-primary">
                  <div class="flex items-center gap-2">
                    <FileText class="h-4 w-4 text-brand" aria-hidden="true" />
                    <span>{{ inv.invoice_number }}</span>
                  </div>
                </td>
                <td class="px-4 py-3 text-sm">
                  <Link v-if="inv.application" :href="`/applicant/applications/${inv.application.id}`" class="zaqa-link">
                    {{ inv.application.application_number }}
                  </Link>
                  <span v-else class="text-text-muted">—</span>
                </td>
                <td class="px-4 py-3 text-sm text-text-primary">{{ money(inv.amount_cents, inv.currency) }}</td>
                <td class="px-4 py-3 text-sm">
                  <span
                    class="zaqa-badge"
                    :class="inv.status === 'paid' ? 'zaqa-badge-success' : inv.status === 'void' ? 'zaqa-badge-danger' : 'zaqa-badge-warning'"
                  >
                    {{ inv.status }}
                  </span>
                </td>
                <td class="px-4 py-3 text-sm text-text-muted">{{ inv.issued_at ? new Date(inv.issued_at).toLocaleString() : '—' }}</td>
                <td class="px-4 py-3 text-sm text-text-muted">{{ inv.paid_at ? new Date(inv.paid_at).toLocaleString() : '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Mobile cards -->
        <div class="space-y-3 md:hidden">
          <div v-for="inv in invoices" :key="inv.id" class="rounded-xl border border-border bg-surface p-4">
            <div class="flex items-start justify-between gap-4">
              <div>
                <div class="text-sm font-semibold text-text-primary">{{ inv.invoice_number }}</div>
                <div class="mt-1 text-xs text-text-muted">
                  {{ inv.application ? inv.application.application_number : '—' }}
                </div>
              </div>
              <span
                class="zaqa-badge"
                :class="inv.status === 'paid' ? 'zaqa-badge-success' : inv.status === 'void' ? 'zaqa-badge-danger' : 'zaqa-badge-warning'"
              >
                {{ inv.status }}
              </span>
            </div>

            <div class="mt-3 grid grid-cols-2 gap-3 text-xs text-text-muted">
              <div>
                <div class="font-semibold uppercase tracking-wider">Amount</div>
                <div class="mt-1 text-sm text-text-primary">{{ money(inv.amount_cents, inv.currency) }}</div>
              </div>
              <div>
                <div class="font-semibold uppercase tracking-wider">Issued</div>
                <div class="mt-1 text-sm text-text-primary">{{ inv.issued_at ? new Date(inv.issued_at).toLocaleDateString() : '—' }}</div>
              </div>
              <div>
                <div class="font-semibold uppercase tracking-wider">Paid</div>
                <div class="mt-1 text-sm text-text-primary">{{ inv.paid_at ? new Date(inv.paid_at).toLocaleDateString() : '—' }}</div>
              </div>
              <div>
                <div class="font-semibold uppercase tracking-wider">Application</div>
                <div class="mt-1 text-sm">
                  <Link v-if="inv.application" :href="`/applicant/applications/${inv.application.id}`" class="zaqa-link">
                    View
                  </Link>
                  <span v-else class="text-text-primary">—</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </ApplicantLayout>
</template>

