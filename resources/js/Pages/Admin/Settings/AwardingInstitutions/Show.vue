<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { Link, router } from '@inertiajs/vue3'
import { Activity, BadgeCheck, Ban, BookOpen, Building2, Cable, ChevronRight, FileText, GraduationCap, PlugZap, RefreshCcw } from 'lucide-vue-next'
import Swal from 'sweetalert2'

const props = defineProps<{
  institution: any
  stats: any
  qualification_counts_by_state: Record<string, number>
  recent_qualifications: Array<any>
  links: Record<string, string>
  can: {
    edit: boolean
    deactivate: boolean
    view_learner_records: boolean
    manage_integrations: boolean
    view_integration_logs: boolean
    view_qualifications_pool: boolean
    view_auto_verified: boolean
  }
}>()

function fmtDate(iso: string | null | undefined): string {
  if (!iso) return '—'
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return '—'
  return d.toLocaleString()
}

function clampPct(n: any): number {
  const v = Number(n ?? 0)
  if (!Number.isFinite(v)) return 0
  return Math.max(0, Math.min(100, Math.round(v)))
}

async function deactivate() {
  const res = await Swal.fire({
    icon: 'warning',
    title: 'Deactivate institution?',
    html: `<div class="text-left text-sm text-text-muted">This institution will no longer be available for new applicant selections. Existing applications and learner records will remain unchanged.</div>`,
    showCancelButton: true,
    confirmButtonText: 'Deactivate',
    cancelButtonText: 'Cancel',
  })
  if (!res.isConfirmed) return
  router.post(props.links.deactivate, {}, { preserveScroll: true })
}

async function reactivate() {
  const res = await Swal.fire({
    icon: 'question',
    title: 'Reactivate institution?',
    html: `<div class="text-left text-sm text-text-muted">This institution will become available for new applicant selections.</div>`,
    showCancelButton: true,
    confirmButtonText: 'Reactivate',
    cancelButtonText: 'Cancel',
  })
  if (!res.isConfirmed) return
  router.post(props.links.reactivate, {}, { preserveScroll: true })
}

const stateLabels: Record<string, string> = {
  awaiting_auto_verification: 'Awaiting auto-verification',
  awaiting_assignment: 'Awaiting assignment',
  assigned_to_level1: 'Assigned to Level 1',
  under_level1_review: 'Under Level 1 review',
  under_level2_review: 'Under Level 2 review',
  returned_to_applicant: 'Returned to applicant',
  auto_verified_pending_level2: 'Auto-verified pending Level 2',
  approved_for_certificate: 'Approved for certificate',
  rejected: 'Rejected',
  certificate_issued: 'Certificate issued',
  closed: 'Closed',
}
</script>

<template>
  <AdminLayout>
    <div class="flex flex-col gap-4">
      <div class="rounded-2xl border border-border bg-surface p-6 shadow-sm">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
          <div>
            <div class="inline-flex items-center gap-2 text-xs font-semibold text-text-muted">
              <Building2 class="h-4 w-4" aria-hidden="true" />
              System Settings
            </div>
            <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">{{ institution.name }}</h1>
            <div class="mt-2 flex flex-wrap items-center gap-2">
              <span class="zaqa-badge" :class="institution.is_foreign ? 'zaqa-badge-warning' : 'zaqa-badge-success'">
                {{ institution.is_foreign ? 'Foreign' : 'Local' }}
              </span>
              <span class="zaqa-badge" :class="institution.is_active ? 'zaqa-badge-success' : 'zaqa-badge-warning'">
                {{ institution.is_active ? 'Active' : 'Inactive' }}
              </span>
              <span v-if="institution.country" class="zaqa-badge zaqa-badge-secondary">{{ institution.country.name }}</span>
            </div>
            <div class="mt-3 text-xs text-text-muted">
              Created: {{ fmtDate(institution.created_at) }} • Updated: {{ fmtDate(institution.updated_at) }}
            </div>
          </div>

          <div class="flex flex-wrap items-center gap-2">
            <Link href="/admin/settings/awarding-institutions" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm">Back</Link>
            <Link v-if="can.edit" :href="links.edit" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm">
              Edit
            </Link>
            <button
              v-if="can.deactivate && institution.is_active"
              type="button"
              class="zaqa-btn zaqa-btn-secondary inline-flex items-center gap-2 px-4 py-2 text-sm"
              @click="deactivate"
            >
              <Ban class="h-4 w-4" aria-hidden="true" />
              Deactivate
            </button>
            <button
              v-if="can.deactivate && !institution.is_active"
              type="button"
              class="zaqa-btn zaqa-btn-secondary inline-flex items-center gap-2 px-4 py-2 text-sm"
              @click="reactivate"
            >
              <RefreshCcw class="h-4 w-4" aria-hidden="true" />
              Reactivate
            </button>
            <Link v-if="can.view_learner_records" :href="links.learner_records" class="zaqa-btn zaqa-btn-primary inline-flex items-center gap-2 px-4 py-2 text-sm">
              <BookOpen class="h-4 w-4" aria-hidden="true" />
              Learner records
            </Link>
            <Link v-if="can.view_qualifications_pool" :href="links.qualifications_pool" class="zaqa-btn zaqa-btn-primary inline-flex items-center gap-2 px-4 py-2 text-sm">
              <GraduationCap class="h-4 w-4" aria-hidden="true" />
              Qualifications
            </Link>
          </div>
        </div>
      </div>

      <div class="grid gap-4 lg:grid-cols-4">
        <div class="rounded-2xl border border-border bg-surface p-5 shadow-sm">
          <div class="flex items-center justify-between">
            <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Learner records</div>
            <BookOpen class="h-4 w-4 text-text-muted" aria-hidden="true" />
          </div>
          <div class="mt-2 text-2xl font-semibold text-text-primary">{{ stats.learner_records_total }}</div>
          <div class="mt-1 text-xs text-text-muted">Last import: {{ stats.last_import_at ? fmtDate(stats.last_import_at) : '—' }}</div>
        </div>

        <div class="rounded-2xl border border-border bg-surface p-5 shadow-sm">
          <div class="flex items-center justify-between">
            <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Qualifications</div>
            <FileText class="h-4 w-4 text-text-muted" aria-hidden="true" />
          </div>
          <div class="mt-2 text-2xl font-semibold text-text-primary">{{ stats.qualifications_total }}</div>
          <div class="mt-1 text-xs text-text-muted">Auto-verified: {{ stats.auto_verified_total }}</div>
        </div>

        <div class="rounded-2xl border border-border bg-surface p-5 shadow-sm">
          <div class="flex items-center justify-between">
            <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Pending reviews</div>
            <Activity class="h-4 w-4 text-text-muted" aria-hidden="true" />
          </div>
          <div class="mt-2 grid grid-cols-2 gap-3">
            <div>
              <div class="text-xs text-text-muted">Level 2</div>
              <div class="text-lg font-semibold text-text-primary">{{ stats.pending_level2_total }}</div>
            </div>
            <div>
              <div class="text-xs text-text-muted">Level 1</div>
              <div class="text-lg font-semibold text-text-primary">{{ stats.pending_level1_total }}</div>
            </div>
          </div>
        </div>

        <div class="rounded-2xl border border-border bg-surface p-5 shadow-sm">
          <div class="flex items-center justify-between">
            <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Certificates</div>
            <BadgeCheck class="h-4 w-4 text-text-muted" aria-hidden="true" />
          </div>
          <div class="mt-2 text-2xl font-semibold text-text-primary">{{ stats.certificates_issued_total }}</div>
          <div class="mt-1 text-xs text-text-muted">Rejected: {{ stats.rejected_total }}</div>
        </div>
      </div>

      <div class="grid gap-4 lg:grid-cols-3">
        <div class="rounded-2xl border border-border bg-surface p-6 shadow-sm lg:col-span-1">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-sm font-semibold text-text-primary">Institution details</div>
              <div class="mt-1 text-xs text-text-muted">Quick operational overview.</div>
            </div>
          </div>

          <div class="mt-5 space-y-4 text-sm">
            <div>
              <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Country</div>
              <div class="mt-1 text-text-primary">{{ institution.country?.name ?? '—' }}</div>
              <div class="mt-0.5 text-xs text-text-muted">{{ institution.country?.iso_code ?? '' }}</div>
            </div>
            <div>
              <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Consent form</div>
              <div class="mt-1">
                <span class="zaqa-badge" :class="institution.has_consent_form ? 'zaqa-badge-success' : 'zaqa-badge-warning'">
                  {{ institution.has_consent_form ? 'On file' : 'Missing' }}
                </span>
              </div>
              <div v-if="institution.consent_form_url" class="mt-2">
                <a :href="institution.consent_form_url" class="zaqa-link text-sm">Download consent form</a>
              </div>
            </div>

            <div class="rounded-xl border border-border bg-surface-muted p-4">
              <div class="flex items-center justify-between">
                <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Integration status</div>
                <Cable class="h-4 w-4 text-text-muted" aria-hidden="true" />
              </div>
              <div class="mt-3 grid gap-2 text-sm">
                <div class="flex items-center justify-between">
                  <div class="text-text-muted">Push enabled</div>
                  <span class="zaqa-badge" :class="stats.push_enabled ? 'zaqa-badge-success' : 'zaqa-badge-warning'">
                    {{ stats.push_enabled ? 'Yes' : 'No' }}
                  </span>
                </div>
                <div class="flex items-center justify-between">
                  <div class="text-text-muted">Pull enabled</div>
                  <span class="zaqa-badge" :class="stats.pull_lookup_enabled ? 'zaqa-badge-success' : 'zaqa-badge-warning'">
                    {{ stats.pull_lookup_enabled ? 'Yes' : 'No' }}
                  </span>
                </div>
                <div class="flex items-center justify-between">
                  <div class="text-text-muted">API clients</div>
                  <div class="font-semibold text-text-primary">{{ stats.api_clients_active }}/{{ stats.api_clients_total }}</div>
                </div>
                <div class="flex items-center justify-between">
                  <div class="text-text-muted">Last activity</div>
                  <div class="text-xs text-text-muted">{{ stats.last_integration_activity_at ? fmtDate(stats.last_integration_activity_at) : '—' }}</div>
                </div>
              </div>

              <div class="mt-4 flex flex-wrap gap-2">
                <Link v-if="can.manage_integrations" :href="links.institution_integrations" class="zaqa-btn zaqa-btn-secondary inline-flex items-center gap-2 px-3 py-2 text-xs">
                  <PlugZap class="h-4 w-4" aria-hidden="true" />
                  Manage integration
                </Link>
                <Link v-if="can.manage_integrations" :href="links.institution_api_clients" class="zaqa-btn zaqa-btn-secondary px-3 py-2 text-xs">API clients</Link>
                <Link v-if="can.view_integration_logs" :href="links.institution_api_logs" class="zaqa-btn zaqa-btn-secondary px-3 py-2 text-xs">Push logs</Link>
                <Link v-if="can.view_integration_logs" :href="links.institution_pull_logs" class="zaqa-btn zaqa-btn-secondary px-3 py-2 text-xs">Pull logs</Link>
              </div>
            </div>
          </div>
        </div>

        <div class="rounded-2xl border border-border bg-surface p-6 shadow-sm lg:col-span-2">
          <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
              <div class="text-sm font-semibold text-text-primary">Qualification activity</div>
              <div class="mt-1 text-xs text-text-muted">Latest 10 qualifications for this institution.</div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
              <Link v-if="can.view_auto_verified" :href="links.qualifications_auto_verified" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-xs">
                Auto-verified queue
              </Link>
              <Link v-if="can.view_qualifications_pool" :href="links.qualifications_pool" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-xs">
                View all
              </Link>
            </div>
          </div>

          <div class="mt-5 grid gap-3">
            <div class="rounded-xl border border-border bg-surface-muted p-4">
              <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Verification status breakdown</div>
              <div class="mt-3 space-y-2">
                <div v-for="(count, state) in qualification_counts_by_state" :key="state" class="flex items-center gap-3">
                  <div class="w-56 truncate text-xs text-text-muted">{{ stateLabels[state] ?? state }}</div>
                  <div class="flex-1">
                    <div class="h-2 w-full overflow-hidden rounded-full bg-border/60">
                      <div
                        class="h-2 rounded-full bg-primary/70"
                        :style="{ width: `${clampPct((count / Math.max(stats.qualifications_total || 1, 1)) * 100)}%` }"
                      />
                    </div>
                  </div>
                  <div class="w-10 text-right text-xs font-semibold text-text-primary">{{ count }}</div>
                </div>
                <div v-if="stats.qualifications_total === 0" class="text-xs text-text-muted">No qualifications recorded yet.</div>
              </div>
            </div>

            <div class="overflow-hidden rounded-xl border border-border">
              <table class="min-w-full text-sm">
                <thead class="bg-surface-muted text-xs font-semibold text-text-muted">
                  <tr>
                    <th class="px-4 py-3 text-left">Application</th>
                    <th class="px-4 py-3 text-left">Qualification</th>
                    <th class="px-4 py-3 text-left">State</th>
                    <th class="px-4 py-3 text-right">Action</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-border/60">
                  <tr v-for="q in recent_qualifications" :key="q.id" class="hover:bg-surface-muted/60">
                    <td class="px-4 py-3">
                      <div class="font-semibold text-text-primary">{{ q.application_number ?? '—' }}</div>
                      <div class="mt-1 text-xs text-text-muted">Submitted: {{ q.submitted_at ? fmtDate(q.submitted_at) : '—' }}</div>
                    </td>
                    <td class="px-4 py-3">
                      <div class="font-semibold text-text-primary">{{ q.qualification_title ?? '—' }}</div>
                      <div v-if="q.verified_title" class="mt-1 text-xs text-text-muted">Verified: {{ q.verified_title }}</div>
                      <div class="mt-1 text-xs text-text-muted">
                        Holder: {{ q.holder_name ?? '—' }}
                        <span v-if="q.confidence !== null && q.confidence !== undefined" class="ml-2">
                          • Confidence: {{ clampPct(q.confidence) }}%
                        </span>
                      </div>
                    </td>
                    <td class="px-4 py-3">
                      <span class="zaqa-badge zaqa-badge-secondary">{{ stateLabels[q.verification_state] ?? q.verification_state }}</span>
                      <div v-if="q.assigned_verifier" class="mt-1 text-xs text-text-muted">Officer: {{ q.assigned_verifier }}</div>
                    </td>
                    <td class="px-4 py-3 text-right">
                      <Link :href="`/admin/verification/qualifications/${q.id}`" class="zaqa-btn zaqa-btn-secondary inline-flex items-center gap-1 px-3 py-2 text-xs">
                        Review
                        <ChevronRight class="h-4 w-4" aria-hidden="true" />
                      </Link>
                    </td>
                  </tr>
                  <tr v-if="recent_qualifications.length === 0">
                    <td colspan="4" class="px-4 py-6 text-center text-sm text-text-muted">No recent qualification activity for this institution.</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>

