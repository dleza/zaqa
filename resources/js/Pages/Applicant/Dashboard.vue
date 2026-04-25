<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3'
import ApplicantLayout from '@/Layouts/ApplicantLayout.vue'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import {
  ArrowRight,
  BadgeCheck,
  CircleAlert,
  ClipboardList,
  CreditCard,
  FileText,
  LayoutDashboard,
  RefreshCcw,
  Send,
} from 'lucide-vue-next'

const page = usePage()
const authUserName = computed(() => ((page.props as any).auth?.user?.name ?? '').toString().trim())
const greeting = computed(() => {
  const h = new Date().getHours()
  if (h < 12) return 'Good morning'
  if (h < 17) return 'Good afternoon'
  return 'Good evening'
})

const props = defineProps<{
  counts: Record<string, number>
  continueDraft: { label: string; href: string; kind: string } | null
  applications: Array<any>
  activity: Array<any>
  alerts: Array<any>
}>()

function money(cents: number, currency: string) {
  return new Intl.NumberFormat(undefined, { style: 'currency', currency: currency || 'ZMW' }).format((cents ?? 0) / 100)
}

const hasApplications = computed(() => (props.applications?.length ?? 0) > 0)

const trackModalOpen = ref(false)
const trackSelectedId = ref<number | ''>('')
const trackLoading = ref(false)
const trackError = ref<string | null>(null)
const trackSummary = ref<{ application: any; events: any[] } | null>(null)

function openTrackModal() {
  trackModalOpen.value = true
  trackSelectedId.value = ''
  trackSummary.value = null
  trackError.value = null
}

function closeTrackModal() {
  trackModalOpen.value = false
  trackLoading.value = false
  trackSelectedId.value = ''
  trackSummary.value = null
  trackError.value = null
}

async function loadTrackSummary() {
  trackError.value = null
  trackSummary.value = null

  const id = Number(trackSelectedId.value || 0)
  if (!id) return

  trackLoading.value = true
  try {
    const res = await fetch(`/applicant/applications/${id}/track-summary`, {
      headers: { Accept: 'application/json' },
    })
    if (!res.ok) throw new Error(`Request failed (${res.status})`)
    trackSummary.value = await res.json()
  } catch (e: any) {
    trackError.value = e?.message ? String(e.message) : 'Could not load activity.'
  } finally {
    trackLoading.value = false
  }
}

function onKeydown(e: KeyboardEvent) {
  if (e.key === 'Escape' && trackModalOpen.value) closeTrackModal()
}

onMounted(() => window.addEventListener('keydown', onKeydown))
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown))

function badgeClass(status: string) {
  const s = (status ?? '').toString()
  if (s === 'draft') return 'zaqa-badge zaqa-badge-warning'
  if (s === 'sent_back') return 'zaqa-badge zaqa-badge-warning'
  if (s === 'submitted') return 'zaqa-badge zaqa-badge-info'
  if (s === 'approved') return 'zaqa-badge zaqa-badge-success'
  if (s === 'rejected') return 'zaqa-badge zaqa-badge-danger'
  return 'zaqa-badge'
}
</script>

<template>
  <ApplicantLayout>
    <div class="zaqa-wizard-shell">
      <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <div class="inline-flex items-center gap-2 text-xs font-semibold text-text-muted">
            <LayoutDashboard class="h-4 w-4" aria-hidden="true" />
            Applicant portal
          </div>
          <h2 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">Dashboard</h2>
          <p class="mt-1 text-sm text-text-muted">
            {{ greeting }}{{ authUserName ? ` ${authUserName}` : '' }}. Track your verification applications, payments, and next actions.
          </p>
        </div>
      </div>

      <!-- Summary cards -->
      <div class="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <Link href="/applicant/applications" class="rounded-2xl border border-border bg-surface p-4 shadow-sm transition hover:bg-surface-muted">
          <div class="flex items-center justify-between">
            <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Total</div>
            <FileText class="h-4 w-4 text-brand" aria-hidden="true" />
          </div>
          <div class="mt-2 text-2xl font-semibold text-text-primary">{{ counts.total ?? 0 }}</div>
        </Link>
        <Link href="/applicant/applications" class="rounded-2xl border border-border bg-surface p-4 shadow-sm transition hover:bg-surface-muted">
          <div class="flex items-center justify-between">
            <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Draft</div>
            <RefreshCcw class="h-4 w-4 text-accent" aria-hidden="true" />
          </div>
          <div class="mt-2 text-2xl font-semibold text-text-primary">{{ counts.draft ?? 0 }}</div>
        </Link>
        <Link href="/applicant/applications" class="rounded-2xl border border-border bg-surface p-4 shadow-sm transition hover:bg-surface-muted">
          <div class="flex items-center justify-between">
            <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Submitted</div>
            <Send class="h-4 w-4 text-brand" aria-hidden="true" />
          </div>
          <div class="mt-2 text-2xl font-semibold text-text-primary">{{ counts.submitted ?? 0 }}</div>
        </Link>
        <Link href="/applicant/applications" class="rounded-2xl border border-border bg-surface p-4 shadow-sm transition hover:bg-surface-muted">
          <div class="flex items-center justify-between">
            <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Sent back</div>
            <CircleAlert class="h-4 w-4 text-warning" aria-hidden="true" />
          </div>
          <div class="mt-2 text-2xl font-semibold text-text-primary">{{ counts.sent_back ?? 0 }}</div>
        </Link>
        <Link href="/applicant/applications" class="rounded-2xl border border-border bg-surface p-4 shadow-sm transition hover:bg-surface-muted">
          <div class="flex items-center justify-between">
            <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Approved</div>
            <BadgeCheck class="h-4 w-4 text-success" aria-hidden="true" />
          </div>
          <div class="mt-2 text-2xl font-semibold text-text-primary">{{ counts.approved ?? 0 }}</div>
        </Link>
      </div>

      <!-- Alerts + Quick actions -->
      <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-3">
        <div class="lg:col-span-2 space-y-4">
          <div v-if="alerts.length > 0" class="rounded-2xl border border-border bg-surface p-5 shadow-sm">
            <div class="flex items-center justify-between">
              <div>
                <div class="text-sm font-semibold text-text-primary">Important</div>
                <div class="mt-1 text-xs text-text-muted">Items that may require your attention.</div>
              </div>
            </div>
            <div class="mt-4 space-y-2">
              <Link
                v-for="a in alerts"
                :key="a.title + a.message"
                :href="a.href || '/applicant/applications'"
                class="block rounded-xl border border-border bg-surface-muted px-4 py-3 transition hover:bg-surface"
              >
                <div class="flex items-start justify-between gap-3">
                  <div class="min-w-0">
                    <div class="text-sm font-semibold text-text-primary">{{ a.title }}</div>
                    <div class="mt-1 text-xs text-text-muted">{{ a.message }}</div>
                  </div>
                  <ArrowRight class="h-4 w-4 text-text-muted" aria-hidden="true" />
                </div>
              </Link>
            </div>
          </div>

          <!-- Applications overview -->
          <div class="rounded-2xl border border-border bg-surface shadow-sm">
            <div class="border-b border-border bg-surface-muted px-5 py-4">
              <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div>
                  <div class="text-sm font-semibold text-text-primary">Applications overview</div>
                  <div class="mt-1 text-xs text-text-muted">Your applications with status and payment context.</div>
                </div>
                <Link href="/applicant/applications" class="zaqa-link text-sm">View all</Link>
              </div>
            </div>

            <div v-if="!hasApplications" class="px-5 py-6">
              <div class="rounded-2xl border border-border bg-surface-muted p-6 text-center">
                <div class="mx-auto inline-flex h-12 w-12 items-center justify-center rounded-2xl border border-brand/20 bg-brand/10">
                  <ClipboardList class="h-5 w-5 text-brand" aria-hidden="true" />
                </div>
                <div class="mt-3 text-sm font-semibold text-text-primary">Start your first application</div>
                <div class="mt-1 text-xs text-text-muted">Create a verification request and track progress end-to-end.</div>
                <div class="mt-4">
                  <Link href="/applicant/applications/new" class="zaqa-btn zaqa-btn-primary">New application</Link>
                </div>
              </div>
            </div>

            <div v-else class="overflow-x-auto">
              <table class="min-w-full text-sm">
                <thead class="bg-surface-muted text-xs font-semibold text-text-muted">
                  <tr>
                    <th class="px-5 py-3 text-left">Reference</th>
                    <th class="px-5 py-3 text-left">Qualification</th>
                    <th class="px-5 py-3 text-left">Status</th>
                    <th class="px-5 py-3 text-left">Payment</th>
                    <th class="px-5 py-3 text-right">Action</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-border/60">
                  <tr v-for="app in applications.slice(0, 8)" :key="app.id" class="hover:bg-surface-muted/60">
                    <td class="px-5 py-3">
                      <div class="font-semibold text-text-primary">{{ app.application_number }}</div>
                      <div class="mt-0.5 text-xs text-text-muted">Updated {{ app.updated_at ?? app.created_at ?? '—' }}</div>
                    </td>
                    <td class="px-5 py-3">
                      <div class="text-text-primary">
                        {{ app.qualification_type ? `${app.qualification_type.level_label} — ${app.qualification_type.name}` : '—' }}
                      </div>
                    </td>
                    <td class="px-5 py-3">
                      <span :class="badgeClass(app.current_status)">{{ app.status_label }}</span>
                    </td>
                    <td class="px-5 py-3">
                      <div v-if="app.invoice" class="text-xs">
                        <div class="font-semibold text-text-primary">{{ money(app.invoice.amount_cents, app.invoice.currency) }}</div>
                        <div class="mt-0.5 text-text-muted">
                          {{ app.payment?.status ?? '—' }}
                        </div>
                      </div>
                      <div v-else class="text-xs text-text-muted">—</div>
                    </td>
                    <td class="px-5 py-3 text-right">
                      <div class="flex flex-col items-end gap-1">
                        <Link :href="app.primary_action.href" class="zaqa-link text-sm">
                          {{ app.primary_action.label }}
                        </Link>
                        <Link :href="`/applicant/applications/${app.id}/track`" class="zaqa-link text-xs">
                          Track
                        </Link>
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <!-- Quick actions -->
        <aside class="space-y-4">
          <div class="rounded-2xl border border-border bg-surface p-5 shadow-sm">
            <div class="text-sm font-semibold text-text-primary">Quick actions</div>
            <div class="mt-1 text-xs text-text-muted">Shortcuts to key areas.</div>

            <div class="mt-4 grid grid-cols-1 gap-2">
              <Link href="/applicant/applications/new" class="zaqa-btn zaqa-btn-primary w-full">
                <ClipboardList class="mr-2 h-4 w-4" aria-hidden="true" />
                New application
              </Link>

              <button type="button" class="zaqa-btn zaqa-btn-secondary w-full" :disabled="applications.length === 0" @click="openTrackModal">
                Track application
              </button>

              <Link v-if="continueDraft" :href="continueDraft.href" class="zaqa-btn zaqa-btn-secondary w-full">
                <RefreshCcw class="mr-2 h-4 w-4" aria-hidden="true" />
                {{ continueDraft.label }}
              </Link>

              <Link href="/applicant/applications" class="zaqa-btn zaqa-btn-secondary w-full">
                <FileText class="mr-2 h-4 w-4" aria-hidden="true" />
                My applications
              </Link>

              <Link href="/applicant/invoices" class="zaqa-btn zaqa-btn-secondary w-full">
                <CreditCard class="mr-2 h-4 w-4" aria-hidden="true" />
                View invoices
              </Link>
            </div>
          </div>
        </aside>
      </div>
    </div>

    <!-- Track modal -->
    <div v-if="trackModalOpen" class="fixed inset-0 z-50">
      <div class="absolute inset-0 bg-black/50" @click="closeTrackModal" />
      <div class="absolute inset-0 flex items-end justify-center p-4 sm:items-center">
        <div class="w-full max-w-2xl overflow-hidden rounded-2xl border border-border bg-surface shadow-xl">
          <div class="border-b border-border bg-surface-muted px-5 py-4">
            <div class="flex items-start justify-between gap-3">
              <div>
                <div class="text-sm font-semibold text-text-primary">Track an application</div>
                <div class="mt-1 text-xs text-text-muted">Select an application to view its latest activity.</div>
              </div>
              <button type="button" class="zaqa-btn zaqa-btn-ghost px-3 py-2 text-sm" @click="closeTrackModal">Close</button>
            </div>
          </div>

          <div class="px-5 py-5 space-y-4">
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
              <div class="sm:col-span-2">
                <label class="text-sm font-medium">Application</label>
                <select v-model="trackSelectedId" class="zaqa-input mt-1" @change="loadTrackSummary">
                  <option value="">Select application…</option>
                  <option v-for="a in applications" :key="a.id" :value="a.id">
                    {{ a.application_number }} — {{ a.status_label }}
                  </option>
                </select>
              </div>
              <div class="flex items-end">
                <button type="button" class="zaqa-btn zaqa-btn-secondary w-full" :disabled="!trackSelectedId || trackLoading" @click="loadTrackSummary">
                  {{ trackLoading ? 'Loading…' : 'Load activity' }}
                </button>
              </div>
            </div>

            <div v-if="trackError" class="rounded-xl border border-danger/20 bg-danger/10 px-4 py-3 text-sm text-danger">
              {{ trackError }}
            </div>

            <div v-if="trackSummary" class="overflow-hidden rounded-2xl border border-border">
              <div class="border-b border-border bg-surface-muted px-4 py-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                  <div class="text-sm font-semibold text-text-primary">
                    {{ trackSummary.application.application_number }}
                  </div>
                  <Link :href="`/applicant/applications/${trackSummary.application.id}/track`" class="zaqa-link text-sm">
                    Open full tracking
                  </Link>
                </div>
              </div>
              <div class="divide-y divide-border/60">
                <div v-for="ev in trackSummary.events" :key="ev.id" class="px-4 py-3 bg-surface">
                  <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                    <div class="text-sm font-semibold text-text-primary">{{ ev.title }}</div>
                    <div class="text-[11px] text-text-muted">{{ ev.occurred_at ?? '—' }}</div>
                  </div>
                  <div v-if="ev.description" class="mt-1 text-xs text-text-muted">{{ ev.description }}</div>
                  <div v-if="ev.comment" class="mt-2 rounded-xl border border-border bg-surface-muted px-3 py-2 text-xs text-text-primary">
                    {{ ev.comment }}
                  </div>
                </div>
              </div>
            </div>

            <div v-else class="rounded-xl border border-border bg-surface-muted px-4 py-3 text-sm text-text-muted">
              Select an application to see activity here.
            </div>
          </div>

          <div class="border-t border-border bg-surface-muted px-5 py-4">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
              <div class="text-xs text-text-muted">Tip: Press Esc to close.</div>
              <div class="flex gap-2">
                <Link href="/applicant/applications" class="zaqa-btn zaqa-btn-secondary">My applications</Link>
                <Link href="/applicant/applications/new" class="zaqa-btn zaqa-btn-primary">New application</Link>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </ApplicantLayout>
</template>

