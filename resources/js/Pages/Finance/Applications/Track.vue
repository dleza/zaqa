<script setup lang="ts">
import FinanceLayout from '@/Layouts/FinanceLayout.vue'
import { Link } from '@inertiajs/vue3'
import { computed } from 'vue'
import { Clock, FileEdit, CreditCard, Send, ShieldCheck, BadgeCheck, User } from 'lucide-vue-next'

const props = defineProps<{
  application: any
  events: Array<any>
  statusHistories: Array<any>
}>()

function eventIcon(code: string) {
  const c = (code ?? '').toString()
  if (c.startsWith('draft.')) return FileEdit
  if (c.startsWith('wizard.')) return FileEdit
  if (c.startsWith('payment.')) return CreditCard
  if (c.startsWith('submission.')) return Send
  if (c.startsWith('review.')) return ShieldCheck
  if (c.startsWith('decision.')) return BadgeCheck
  return Clock
}

const timeline = computed(() => props.events ?? [])
</script>

<template>
  <FinanceLayout>
    <div class="zaqa-wizard-shell">
      <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <div class="text-xs font-semibold text-text-muted">Tracking</div>
          <div class="mt-1 text-2xl font-semibold tracking-tight text-text-primary">
            {{ application.application_number }}
          </div>
          <div class="mt-1 text-sm text-text-muted">
            {{ application.qualification_type ? `${application.qualification_type.level_label} — ${application.qualification_type.name}` : '—' }}
            • {{ application.is_foreign ? 'Foreign' : 'Local' }}
          </div>
        </div>

        <div class="flex flex-wrap gap-2">
          <Link href="/finance/payment-proofs" class="zaqa-btn zaqa-btn-secondary">Payment proofs</Link>
        </div>
      </div>

      <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-3">
        <div class="lg:col-span-2 overflow-hidden rounded-2xl border border-border bg-surface shadow-sm">
          <div class="border-b border-border bg-surface-muted px-5 py-4">
            <div class="text-sm font-semibold text-text-primary">Lifecycle timeline</div>
            <div class="mt-1 text-xs text-text-muted">Business-readable lifecycle events (internal + applicant-visible).</div>
          </div>
          <div class="px-5 py-5">
            <div v-if="timeline.length === 0" class="text-sm text-text-muted">No lifecycle events recorded yet.</div>
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
                    <div class="mt-2 flex flex-wrap items-center gap-2 text-[11px] text-text-muted">
                      <span class="inline-flex items-center gap-1">
                        <User class="h-3.5 w-3.5" aria-hidden="true" />
                        {{ ev.actor_name ?? 'System' }}
                      </span>
                      <span class="zaqa-badge">{{ ev.visibility }}</span>
                      <span v-if="ev.status_snapshot" class="zaqa-badge">{{ ev.status_snapshot }}</span>
                    </div>
                    <div v-if="ev.comment" class="mt-2 rounded-xl border border-border bg-surface px-3 py-2 text-xs text-text-primary">
                      {{ ev.comment }}
                    </div>
                  </div>
                </div>
              </li>
            </ol>
          </div>
        </div>

        <aside class="space-y-4">
          <div class="rounded-2xl border border-border bg-surface shadow-sm">
            <div class="border-b border-border bg-surface-muted px-5 py-4">
              <div class="text-sm font-semibold text-text-primary">Status history</div>
              <div class="mt-1 text-xs text-text-muted">Raw status transitions.</div>
            </div>
            <div class="px-5 py-4">
              <div v-if="statusHistories.length === 0" class="text-sm text-text-muted">No status history.</div>
              <div v-else class="space-y-2">
                <div v-for="h in statusHistories" :key="h.id" class="rounded-xl border border-border bg-surface-muted px-4 py-3">
                  <div class="text-xs font-semibold text-text-primary">{{ h.from_status ?? '—' }} → {{ h.to_status }}</div>
                  <div v-if="h.comment" class="mt-1 text-xs text-text-muted">{{ h.comment }}</div>
                  <div class="mt-1 text-[11px] text-text-muted">{{ h.changed_at }}</div>
                  <div v-if="h.actor_name" class="mt-1 text-[11px] text-text-muted">By {{ h.actor_name }}</div>
                </div>
              </div>
            </div>
          </div>
        </aside>
      </div>
    </div>
  </FinanceLayout>
</template>

