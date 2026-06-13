<script setup lang="ts">
import AdminActionModal from '@/Components/AdminActionModal.vue'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { Link, useForm, usePage } from '@inertiajs/vue3'
import {
  AlertTriangle,
  Bell,
  CheckCircle2,
  MessageSquare,
  Plus,
  Radio,
  Settings2,
  ShieldAlert,
  Wifi,
  XCircle,
} from 'lucide-vue-next'
import { computed, ref } from 'vue'

const props = defineProps<{
  account: {
    balance: number
    low_balance_threshold: number
    critical_balance_threshold: number
    alert_level: string | null
    last_low_alert_at: string | null
    last_critical_alert_at: string | null
    last_zero_alert_at: string | null
  }
  statistics: { sent_today: number; failed_today: number }
  adjustments: Array<{
    id: number
    adjustment_type: string
    amount: number
    reason: string
    balance_before: number
    balance_after: number
    actor: { id: number; name: string } | null
    created_at: string | null
  }>
  recent_logs: Array<{ id: number; message_type: string; status: string; created_at: string | null }>
  config: { enabled: boolean; provider: string }
  can: { manage: boolean; test_connection: boolean }
}>()

const page = usePage()
const flash = computed(() => page.props.flash as Record<string, unknown>)
const addModalOpen = ref(false)

const form = useForm({
  amount: '',
  reason: '',
})

const healthPercent = computed(() => {
  const max = Math.max(props.account.low_balance_threshold * 1.5, props.account.balance, 1)
  return Math.min(100, Math.round((props.account.balance / max) * 100))
})

const healthLabel = computed(() => {
  if (props.account.balance <= 0) return 'Exhausted'
  if (props.account.balance <= props.account.critical_balance_threshold) return 'Critical'
  if (props.account.balance <= props.account.low_balance_threshold) return 'Low'
  return 'Healthy'
})

const healthBadgeClass = computed(() => {
  if (props.account.balance <= 0) return 'bg-red-100 text-red-800 border-red-200'
  if (props.account.balance <= props.account.critical_balance_threshold) return 'bg-red-50 text-red-700 border-red-200'
  if (props.account.balance <= props.account.low_balance_threshold) return 'bg-amber-50 text-amber-800 border-amber-200'
  return 'bg-emerald-50 text-emerald-800 border-emerald-200'
})

const healthBarClass = computed(() => {
  if (props.account.balance <= 0) return 'bg-red-500'
  if (props.account.balance <= props.account.critical_balance_threshold) return 'bg-red-500'
  if (props.account.balance <= props.account.low_balance_threshold) return 'bg-amber-500'
  return 'bg-emerald-500'
})

function formatDateTime(value: string | null): string {
  if (!value) return 'Not sent yet'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value
  return new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(date)
}

function openAddModal() {
  form.clearErrors()
  addModalOpen.value = true
}

function submitCredit() {
  form.post('/admin/settings/sms/balance', {
    preserveScroll: true,
    onSuccess: () => {
      form.reset('amount', 'reason')
      addModalOpen.value = false
    },
  })
}

function testConnection() {
  useForm({}).post('/admin/settings/sms/test-connection', { preserveScroll: true })
}
</script>

<template>
  <AdminLayout>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <div class="inline-flex items-center gap-2 text-xs font-semibold text-text-muted">
          <MessageSquare class="h-4 w-4" aria-hidden="true" />
          System Settings
        </div>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">SMS Balance</h1>
        <p class="mt-1 text-sm text-text-muted">Manage internal SMS credits, thresholds, and delivery alerts.</p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <Link href="/admin/settings/sms/logs" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm">View SMS logs</Link>
        <button
          v-if="can.manage"
          type="button"
          class="zaqa-btn zaqa-btn-primary inline-flex items-center gap-2 px-4 py-2 text-sm"
          @click="openAddModal"
        >
          <Plus class="h-4 w-4" aria-hidden="true" />
          Add SMS balance
        </button>
      </div>
    </div>

    <div
      v-if="account.alert_level === 'critical'"
      class="mt-6 rounded-xl border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-900"
    >
      SMS balance is critically low. Only {{ account.balance }} units remaining — top up immediately.
    </div>
    <div
      v-else-if="account.alert_level === 'warning'"
      class="mt-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900"
    >
      SMS balance is running low. {{ account.balance }} units remaining — consider topping up soon.
    </div>

    <div v-if="flash.success" class="mt-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
      {{ flash.success }}
    </div>
    <div v-if="flash.error" class="mt-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
      {{ flash.error }}
    </div>

    <div class="mt-6 grid gap-4 md:grid-cols-3">
      <div class="rounded-2xl border border-border bg-surface p-5 shadow-sm">
        <div class="text-xs font-semibold uppercase tracking-wide text-text-muted">Current balance</div>
        <div class="mt-2 text-3xl font-bold text-text-primary">{{ account.balance.toLocaleString() }}</div>
        <div class="mt-2 text-xs text-text-muted">SMS units available</div>
      </div>
      <div class="rounded-2xl border border-border bg-surface p-5 shadow-sm">
        <div class="text-xs font-semibold uppercase tracking-wide text-text-muted">Sent today</div>
        <div class="mt-2 text-3xl font-bold text-emerald-700">{{ statistics.sent_today.toLocaleString() }}</div>
        <div class="mt-2 text-xs text-text-muted">Successful deliveries</div>
      </div>
      <div class="rounded-2xl border border-border bg-surface p-5 shadow-sm">
        <div class="text-xs font-semibold uppercase tracking-wide text-text-muted">Failed today</div>
        <div class="mt-2 text-3xl font-bold text-red-700">{{ statistics.failed_today.toLocaleString() }}</div>
        <div class="mt-2 text-xs text-text-muted">Provider or validation failures</div>
      </div>
    </div>

    <div class="mt-6 overflow-hidden rounded-2xl border border-border bg-surface shadow-sm">
      <div class="border-b border-border bg-surface-muted px-5 py-4">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <div class="inline-flex items-center gap-2 text-sm font-semibold text-text-primary">
              <Settings2 class="h-4 w-4 text-[#0076BD]" aria-hidden="true" />
              Thresholds &amp; configuration
            </div>
            <p class="mt-1 text-xs text-text-muted">
              Balance health, alert thresholds, platform settings, and last email notifications.
            </p>
          </div>
          <span class="inline-flex w-fit items-center rounded-full border px-3 py-1 text-xs font-semibold" :class="healthBadgeClass">
            {{ healthLabel }}
          </span>
        </div>
      </div>

      <div class="space-y-6 p-5">
        <div class="rounded-xl border border-border bg-surface-muted/40 p-4">
          <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
              <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Balance health</div>
              <div class="mt-1 text-sm text-text-primary">
                Current balance relative to the low-balance threshold ({{ account.low_balance_threshold.toLocaleString() }} units).
              </div>
            </div>
            <div class="text-right">
              <div class="text-2xl font-bold text-text-primary">{{ account.balance.toLocaleString() }}</div>
              <div class="text-xs text-text-muted">of {{ account.low_balance_threshold.toLocaleString() }} warning level</div>
            </div>
          </div>
          <div class="mt-4 h-2.5 overflow-hidden rounded-full bg-border">
            <div class="h-full rounded-full transition-all" :class="healthBarClass" :style="{ width: `${healthPercent}%` }" />
          </div>
          <div class="mt-3 flex flex-wrap gap-4 text-xs text-text-muted">
            <span class="inline-flex items-center gap-1.5">
              <span class="h-2 w-2 rounded-full bg-emerald-500" />
              Above {{ account.low_balance_threshold.toLocaleString() }}
            </span>
            <span class="inline-flex items-center gap-1.5">
              <span class="h-2 w-2 rounded-full bg-amber-500" />
              Low (≤ {{ account.low_balance_threshold.toLocaleString() }})
            </span>
            <span class="inline-flex items-center gap-1.5">
              <span class="h-2 w-2 rounded-full bg-red-500" />
              Critical (≤ {{ account.critical_balance_threshold.toLocaleString() }})
            </span>
          </div>
        </div>

        <div>
          <div class="mb-3 text-xs font-semibold uppercase tracking-wider text-text-muted">Alert thresholds</div>
          <div class="grid gap-4 md:grid-cols-2">
            <div class="rounded-xl border border-amber-200/70 bg-amber-50/50 p-4">
              <div class="flex items-start gap-3">
                <div class="rounded-lg bg-amber-100 p-2 text-amber-700">
                  <AlertTriangle class="h-5 w-5" aria-hidden="true" />
                </div>
                <div class="min-w-0 flex-1">
                  <div class="text-sm font-semibold text-text-primary">Low balance</div>
                  <div class="mt-1 text-xs text-text-muted">Dashboard warning and email alert when balance crosses this level.</div>
                  <div class="mt-3 text-2xl font-bold text-amber-800">{{ account.low_balance_threshold.toLocaleString() }}</div>
                  <div class="mt-1 text-xs font-medium text-amber-700">units or below</div>
                </div>
              </div>
            </div>

            <div class="rounded-xl border border-red-200/70 bg-red-50/50 p-4">
              <div class="flex items-start gap-3">
                <div class="rounded-lg bg-red-100 p-2 text-red-700">
                  <ShieldAlert class="h-5 w-5" aria-hidden="true" />
                </div>
                <div class="min-w-0 flex-1">
                  <div class="text-sm font-semibold text-text-primary">Critical balance</div>
                  <div class="mt-1 text-xs text-text-muted">Urgent dashboard banner and priority email alert.</div>
                  <div class="mt-3 text-2xl font-bold text-red-800">{{ account.critical_balance_threshold.toLocaleString() }}</div>
                  <div class="mt-1 text-xs font-medium text-red-700">units or below</div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div>
          <div class="mb-3 text-xs font-semibold uppercase tracking-wider text-text-muted">Platform</div>
          <div class="grid gap-4 sm:grid-cols-2">
            <div class="flex items-center justify-between rounded-xl border border-border px-4 py-3">
              <div class="flex items-center gap-3">
                <Radio class="h-4 w-4 text-text-muted" aria-hidden="true" />
                <div>
                  <div class="text-sm font-medium text-text-primary">SMS provider</div>
                  <div class="text-xs text-text-muted">Active outbound gateway</div>
                </div>
              </div>
              <span class="zaqa-badge zaqa-badge-info uppercase">{{ config.provider }}</span>
            </div>
            <div class="flex items-center justify-between rounded-xl border border-border px-4 py-3">
              <div class="flex items-center gap-3">
                <CheckCircle2 v-if="config.enabled" class="h-4 w-4 text-emerald-600" aria-hidden="true" />
                <XCircle v-else class="h-4 w-4 text-text-muted" aria-hidden="true" />
                <div>
                  <div class="text-sm font-medium text-text-primary">SMS sending</div>
                  <div class="text-xs text-text-muted">Template-based notifications</div>
                </div>
              </div>
              <span
                class="zaqa-badge"
                :class="config.enabled ? 'zaqa-badge-success' : 'zaqa-badge-secondary'"
              >
                {{ config.enabled ? 'Enabled' : 'Disabled' }}
              </span>
            </div>
          </div>
          <div v-if="can.test_connection" class="mt-4">
            <button type="button" class="zaqa-btn zaqa-btn-secondary inline-flex items-center gap-2 px-4 py-2 text-sm" @click="testConnection">
              <Wifi class="h-4 w-4" aria-hidden="true" />
              Test provider connection
            </button>
          </div>
        </div>

        <div>
          <div class="mb-3 text-xs font-semibold uppercase tracking-wider text-text-muted">Last email alerts</div>
          <div class="grid gap-3 md:grid-cols-3">
            <div class="rounded-xl border border-border px-4 py-3">
              <div class="flex items-center gap-2 text-xs font-semibold text-text-muted">
                <Bell class="h-3.5 w-3.5" aria-hidden="true" />
                Low balance
              </div>
              <div class="mt-2 text-sm font-medium text-text-primary">{{ formatDateTime(account.last_low_alert_at) }}</div>
            </div>
            <div class="rounded-xl border border-border px-4 py-3">
              <div class="flex items-center gap-2 text-xs font-semibold text-text-muted">
                <Bell class="h-3.5 w-3.5" aria-hidden="true" />
                Critical balance
              </div>
              <div class="mt-2 text-sm font-medium text-text-primary">{{ formatDateTime(account.last_critical_alert_at) }}</div>
            </div>
            <div class="rounded-xl border border-border px-4 py-3">
              <div class="flex items-center gap-2 text-xs font-semibold text-text-muted">
                <Bell class="h-3.5 w-3.5" aria-hidden="true" />
                Zero balance
              </div>
              <div class="mt-2 text-sm font-medium text-text-primary">{{ formatDateTime(account.last_zero_alert_at) }}</div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <AdminActionModal
      v-model="addModalOpen"
      title="Add SMS balance"
      description="Credit internal SMS units. Each successful outbound SMS consumes one unit."
      max-width-class="max-w-lg"
    >
      <form id="sms-balance-credit-form" class="space-y-4" @submit.prevent="submitCredit">
        <div class="rounded-xl border border-border bg-surface-muted/60 p-4 text-sm text-text-muted">
          Current balance:
          <span class="font-semibold text-text-primary">{{ account.balance.toLocaleString() }} units</span>
        </div>

        <div>
          <label for="sms-credit-amount" class="text-xs font-semibold uppercase tracking-wider text-text-muted">Amount to add</label>
          <input
            id="sms-credit-amount"
            v-model="form.amount"
            type="number"
            min="1"
            step="1"
            class="zaqa-input mt-2 w-full"
            placeholder="e.g. 2000"
            required
          />
          <p v-if="form.errors.amount" class="mt-2 text-xs text-danger">{{ form.errors.amount }}</p>
        </div>

        <div>
          <label for="sms-credit-reason" class="text-xs font-semibold uppercase tracking-wider text-text-muted">Reason</label>
          <input
            id="sms-credit-reason"
            v-model="form.reason"
            type="text"
            class="zaqa-input mt-2 w-full"
            placeholder="e.g. Zamtel bundle purchase — March 2026"
            required
          />
          <p v-if="form.errors.reason" class="mt-2 text-xs text-danger">{{ form.errors.reason }}</p>
          <p class="mt-2 text-xs text-text-muted">Recorded in the balance adjustment audit trail.</p>
        </div>
      </form>

      <template #footer>
        <button type="button" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm" @click="addModalOpen = false">Cancel</button>
        <button
          type="submit"
          form="sms-balance-credit-form"
          class="zaqa-btn zaqa-btn-primary inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold disabled:opacity-50"
          :disabled="form.processing"
        >
          <Plus class="h-4 w-4" aria-hidden="true" />
          {{ form.processing ? 'Adding…' : 'Add balance' }}
        </button>
      </template>
    </AdminActionModal>

    <div class="mt-6 overflow-hidden rounded-2xl border border-border bg-surface shadow-sm">
      <div class="border-b border-border px-5 py-4">
        <div class="text-sm font-semibold text-text-primary">Recent adjustments</div>
        <div class="mt-1 text-xs text-text-muted">Credits and debits from SMS usage and manual top-ups.</div>
      </div>
      <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-surface-muted text-left text-xs uppercase tracking-wide text-text-muted">
            <tr>
              <th class="px-5 py-3">When</th>
              <th class="px-5 py-3">Type</th>
              <th class="px-5 py-3">Amount</th>
              <th class="px-5 py-3">Reason</th>
              <th class="px-5 py-3">Before</th>
              <th class="px-5 py-3">After</th>
              <th class="px-5 py-3">Actor</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="adjustments.length === 0">
              <td colspan="7" class="px-5 py-8 text-center text-sm text-text-muted">No balance adjustments yet.</td>
            </tr>
            <tr v-for="row in adjustments" :key="row.id" class="border-t border-border">
              <td class="px-5 py-3 whitespace-nowrap">{{ formatDateTime(row.created_at) }}</td>
              <td class="px-5 py-3">
                <span
                  class="zaqa-badge text-xs capitalize"
                  :class="row.adjustment_type === 'credit' ? 'zaqa-badge-success' : 'zaqa-badge-secondary'"
                >
                  {{ row.adjustment_type }}
                </span>
              </td>
              <td class="px-5 py-3 font-medium">{{ row.amount.toLocaleString() }}</td>
              <td class="px-5 py-3 max-w-xs truncate" :title="row.reason">{{ row.reason }}</td>
              <td class="px-5 py-3">{{ row.balance_before.toLocaleString() }}</td>
              <td class="px-5 py-3 font-medium">{{ row.balance_after.toLocaleString() }}</td>
              <td class="px-5 py-3">{{ row.actor?.name ?? 'System' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </AdminLayout>
</template>
