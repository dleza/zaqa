<script setup lang="ts">
import ApplicantLayout from '@/Layouts/ApplicantLayout.vue'
import { Link } from '@inertiajs/vue3'
import { computed } from 'vue'
import {
  BadgeCheck,
  CheckCircle2,
  CircleAlert,
  Clock,
  CreditCard,
  FileEdit,
  RefreshCcw,
  Send,
  ShieldCheck,
  XCircle,
} from 'lucide-vue-next'

const props = defineProps<{
  application: any
  events: Array<any>
  statusHistoryFallback: Array<any>
}>()

function money(cents: number, currency: string) {
  return new Intl.NumberFormat(undefined, { style: 'currency', currency: currency || 'ZMW' }).format((cents ?? 0) / 100)
}

const stageSteps = computed(() => {
  const s = (props.application.current_status ?? '').toString()
  const steps = [
    { key: 'draft', label: 'Draft', done: s !== 'draft', icon: FileEdit },
    { key: 'payment', label: 'Payment', done: ['submitted', 'resubmitted', 'sent_back', 'approved', 'rejected'].includes(s), icon: CreditCard },
    { key: 'submitted', label: 'Submitted', done: ['submitted', 'resubmitted', 'sent_back', 'approved', 'rejected'].includes(s), icon: Send },
    { key: 'review', label: 'Under review', done: ['approved', 'rejected'].includes(s), icon: ShieldCheck },
    { key: 'decision', label: 'Decision', done: ['approved', 'rejected'].includes(s), icon: BadgeCheck },
  ]
  return steps
})

function statusBadgeClass(status: string) {
  const s = (status ?? '').toString()
  if (s === 'draft') return 'zaqa-badge zaqa-badge-warning'
  if (s === 'sent_back') return 'zaqa-badge zaqa-badge-warning'
  if (s === 'submitted' || s === 'resubmitted') return 'zaqa-badge zaqa-badge-info'
  if (s === 'approved') return 'zaqa-badge zaqa-badge-success'
  if (s === 'rejected') return 'zaqa-badge zaqa-badge-danger'
  return 'zaqa-badge'
}

function eventIcon(code: string) {
  const c = (code ?? '').toString()
  if (c.startsWith('draft.')) return FileEdit
  if (c.startsWith('wizard.')) return RefreshCcw
  if (c.startsWith('payment.')) return CreditCard
  if (c.startsWith('submission.')) return Send
  return Clock
}

const timeline = computed(() => {
  if ((props.events ?? []).length > 0) return props.events
  return (props.statusHistoryFallback ?? []).map((h) => ({
    id: `status-${h.id}`,
    title: h.from_status ? `${h.from_status} → ${h.to_status}` : `Status: ${h.to_status}`,
    description: h.comment ?? null,
    occurred_at: h.changed_at,
    event_code: 'status.fallback',
  }))
})
</script>

<template>
  <ApplicantLayout>
    <div class="zaqa-wizard-shell">
      <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <div class="text-xs font-semibold text-text-muted">Track application</div>
          <div class="mt-1 flex flex-wrap items-center gap-2">
            <h1 class="text-2xl font-semibold tracking-tight text-text-primary">{{ application.application_number }}</h1>
            <span :class="statusBadgeClass(application.current_status)">{{ application.status_label }}</span>
          </div>
          <div class="mt-1 text-sm text-text-muted">
            {{ application.is_foreign ? 'Foreign' : 'Local' }} • Submitted: {{ application.submitted_at ?? '—' }}
          </div>
        </div>

        <div class="flex flex-wrap gap-2">
          <Link :href="`/applicant/applications/${application.id}`" class="zaqa-btn zaqa-btn-secondary">View details</Link>
          <Link href="/applicant/applications" class="zaqa-btn zaqa-btn-ghost">All applications</Link>
        </div>
      </div>

      <!-- Progress tracker -->
      <div class="mt-6 overflow-hidden rounded-2xl border border-border bg-surface shadow-sm">
        <div class="border-b border-border bg-surface-muted px-5 py-4">
          <div class="text-sm font-semibold text-text-primary">Progress</div>
          <div class="mt-1 text-xs text-text-muted">A high-level view of where your application is in the process.</div>
        </div>
        <div class="px-5 py-5">
          <div class="grid grid-cols-1 gap-3 sm:grid-cols-5">
            <div v-for="step in stageSteps" :key="step.key" class="rounded-2xl border border-border bg-surface-muted px-4 py-4">
              <div class="flex items-center justify-between">
                <component :is="step.icon" class="h-4 w-4" :class="step.done ? 'text-success' : 'text-text-muted'" aria-hidden="true" />
                <component :is="step.done ? CheckCircle2 : CircleAlert" class="h-4 w-4" :class="step.done ? 'text-success' : 'text-warning'" aria-hidden="true" />
              </div>
              <div class="mt-3 text-sm font-semibold text-text-primary">{{ step.label }}</div>
              <div class="mt-1 text-xs" :class="step.done ? 'text-success' : 'text-text-muted'">
                {{ step.done ? 'Completed' : 'Pending' }}
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-3">
        <!-- Timeline -->
        <div class="lg:col-span-2 overflow-hidden rounded-2xl border border-border bg-surface shadow-sm">
          <div class="border-b border-border bg-surface-muted px-5 py-4">
            <div class="text-sm font-semibold text-text-primary">Timeline</div>
            <div class="mt-1 text-xs text-text-muted">What has happened so far.</div>
          </div>

          <div class="px-5 py-5">
            <div v-if="timeline.length === 0" class="rounded-xl border border-border bg-surface-muted px-4 py-3 text-sm text-text-muted">
              No lifecycle events recorded yet.
            </div>

            <ol v-else class="space-y-3">
              <li v-for="ev in timeline" :key="ev.id" class="rounded-2xl border border-border bg-surface-muted px-4 py-3">
                <div class="flex items-start gap-3">
                  <div class="mt-0.5 inline-flex h-9 w-9 items-center justify-center rounded-xl border border-border bg-surface">
                    <component :is="eventIcon(ev.event_code)" class="h-4 w-4 text-brand" aria-hidden="true" />
                  </div>
                  <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                      <div class="text-sm font-semibold text-text-primary">{{ ev.title }}</div>
                      <div class="text-[11px] text-text-muted">{{ ev.occurred_at ?? '—' }}</div>
                    </div>
                    <div v-if="ev.description" class="mt-1 text-xs text-text-muted">{{ ev.description }}</div>
                    <div v-if="ev.comment" class="mt-2 rounded-xl border border-border bg-surface px-3 py-2 text-xs text-text-primary">
                      {{ ev.comment }}
                    </div>
                  </div>
                </div>
              </li>
            </ol>
          </div>
        </div>

        <!-- Summary -->
        <aside class="space-y-4">
          <div class="rounded-2xl border border-border bg-surface p-5 shadow-sm">
            <div class="text-sm font-semibold text-text-primary">Key dates</div>
            <div class="mt-3 grid grid-cols-1 gap-3 text-sm">
              <div class="rounded-xl border border-border bg-surface-muted px-4 py-3">
                <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Created</div>
                <div class="mt-1 font-semibold text-text-primary">{{ application.created_at ?? '—' }}</div>
              </div>
              <div class="rounded-xl border border-border bg-surface-muted px-4 py-3">
                <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Submitted</div>
                <div class="mt-1 font-semibold text-text-primary">{{ application.submitted_at ?? '—' }}</div>
              </div>
              <div class="rounded-xl border border-border bg-surface-muted px-4 py-3">
                <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">SLA deadline</div>
                <div class="mt-1 font-semibold text-text-primary">{{ application.service_deadline_at ?? '—' }}</div>
              </div>
            </div>
          </div>

          <div class="rounded-2xl border border-border bg-surface p-5 shadow-sm">
            <div class="text-sm font-semibold text-text-primary">Payment</div>
            <div class="mt-3 rounded-xl border border-border bg-surface-muted px-4 py-3 text-sm">
              <div class="text-xs text-text-muted">Invoice</div>
              <div class="mt-1 font-semibold text-text-primary">{{ application.invoice?.invoice_number ?? '—' }}</div>
              <div class="mt-2 text-xs text-text-muted">Amount</div>
              <div class="mt-1 font-semibold text-text-primary">
                {{ application.invoice ? money(application.invoice.amount_cents, application.invoice.currency) : '—' }}
              </div>
              <div class="mt-2 text-xs text-text-muted">Payment status</div>
              <div class="mt-1 font-semibold text-text-primary">{{ application.payment?.status ?? '—' }}</div>
            </div>
          </div>
        </aside>
      </div>
    </div>
  </ApplicantLayout>
</template>

