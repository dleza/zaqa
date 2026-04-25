<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { Link } from '@inertiajs/vue3'
import { ClipboardList, MessageSquare, Users } from 'lucide-vue-next'

defineProps<{
  applicant: any
  recent_applications: Array<any>
  can_view_internal_application: boolean
  stats: {
    total: number
    submitted: number
    success: number
    pending: number
    failed: number
  }
  recent_feedback: any | null
}>()
</script>

<template>
  <AdminLayout>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <div class="inline-flex items-center gap-2 text-xs font-semibold text-text-muted">
          <Users class="h-4 w-4" aria-hidden="true" />
          Applicants
        </div>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">Applicant details</h1>
        <p class="mt-1 text-sm text-text-muted">Profile summary and recent applications.</p>
      </div>

      <div class="flex items-center gap-2">
        <Link href="/admin/applicants" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm">Back</Link>
      </div>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
      <div class="lg:col-span-1">
        <div class="rounded-2xl border border-border bg-surface p-6 shadow-sm">
          <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Account</div>

          <div class="mt-3">
            <div class="text-base font-semibold text-text-primary">{{ applicant.name }}</div>
            <div class="mt-0.5 text-xs text-text-muted">{{ applicant.email }}</div>
          </div>

          <div class="mt-5 grid gap-3 text-sm">
            <div class="flex items-center justify-between gap-3">
              <div class="text-text-muted">Created</div>
              <div class="font-semibold text-text-primary">{{ applicant.created_at ?? '—' }}</div>
            </div>
            <div class="flex items-center justify-between gap-3">
              <div class="text-text-muted">Phone</div>
              <div class="font-semibold text-text-primary">{{ applicant.phone_primary ?? '—' }}</div>
            </div>
            <div class="flex items-center justify-between gap-3">
              <div class="text-text-muted">Type</div>
              <div class="font-semibold text-text-primary">{{ applicant.applicant_type ?? '—' }}</div>
            </div>
            <div class="flex items-center justify-between gap-3">
              <div class="text-text-muted">Status</div>
              <span class="zaqa-badge" :class="applicant.disabled_at ? 'zaqa-badge-danger' : (applicant.is_active ? 'zaqa-badge-success' : 'zaqa-badge-warning')">
                {{ applicant.disabled_at ? 'Disabled' : (applicant.is_active ? 'Active' : 'Inactive') }}
              </span>
            </div>
          </div>

          <div class="mt-5">
            <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Role</div>
            <div class="mt-2 flex flex-wrap gap-2">
              <span v-for="r in (applicant.roles ?? [])" :key="r" class="zaqa-badge">{{ r }}</span>
              <span v-if="(applicant.roles ?? []).length === 0" class="text-xs text-text-muted">—</span>
            </div>
          </div>

          <div class="mt-6 rounded-xl border border-border bg-surface-muted p-4">
            <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Profile (quick)</div>

            <div v-if="applicant.applicant_type === 'individual'" class="mt-3 grid gap-2 text-sm">
              <div class="flex items-center justify-between gap-3">
                <div class="text-text-muted">NRC</div>
                <div class="font-semibold text-text-primary">{{ applicant.profile?.nrc_number ?? '—' }}</div>
              </div>
              <div class="flex items-center justify-between gap-3">
                <div class="text-text-muted">Passport</div>
                <div class="font-semibold text-text-primary">{{ applicant.profile?.passport_number ?? '—' }}</div>
              </div>
            </div>

            <div v-else class="mt-3 grid gap-2 text-sm">
              <div class="flex items-center justify-between gap-3">
                <div class="text-text-muted">Institution</div>
                <div class="font-semibold text-text-primary">{{ applicant.profile?.institution_name ?? '—' }}</div>
              </div>
              <div class="flex items-center justify-between gap-3">
                <div class="text-text-muted">TPIN</div>
                <div class="font-semibold text-text-primary">{{ applicant.profile?.tpin ?? '—' }}</div>
              </div>
              <div class="flex items-center justify-between gap-3">
                <div class="text-text-muted">Contact</div>
                <div class="font-semibold text-text-primary">{{ applicant.profile?.contact_person_name ?? '—' }}</div>
              </div>
            </div>
          </div>

          <div class="mt-6 rounded-2xl border border-border bg-surface p-5 shadow-sm">
            <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Application stats</div>
            <div class="mt-4 grid gap-3 sm:grid-cols-2">
              <div class="rounded-xl border border-border bg-surface-muted p-4">
                <div class="text-xs text-text-muted">Total</div>
                <div class="mt-1 text-xl font-semibold text-text-primary">{{ stats.total }}</div>
              </div>
              <div class="rounded-xl border border-border bg-surface-muted p-4">
                <div class="text-xs text-text-muted">Submitted</div>
                <div class="mt-1 text-xl font-semibold text-text-primary">{{ stats.submitted }}</div>
              </div>
              <div class="rounded-xl border border-border bg-surface-muted p-4">
                <div class="text-xs text-text-muted">Successful</div>
                <div class="mt-1 text-xl font-semibold text-text-primary">{{ stats.success }}</div>
              </div>
              <div class="rounded-xl border border-border bg-surface-muted p-4">
                <div class="text-xs text-text-muted">Pending</div>
                <div class="mt-1 text-xl font-semibold text-text-primary">{{ stats.pending }}</div>
              </div>
              <div class="rounded-xl border border-border bg-surface-muted p-4 sm:col-span-2">
                <div class="text-xs text-text-muted">Failed</div>
                <div class="mt-1 text-xl font-semibold text-text-primary">{{ stats.failed }}</div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="lg:col-span-2">
        <div class="overflow-hidden rounded-2xl border border-border bg-surface shadow-sm">
          <div class="border-b border-border bg-surface-muted px-5 py-4">
            <div class="flex items-center gap-2 text-sm font-semibold text-text-primary">
              <MessageSquare class="h-4 w-4" aria-hidden="true" />
              Recent feedback
            </div>
            <div class="mt-1 text-xs text-text-muted">Latest service feedback submitted by this applicant.</div>
          </div>

          <div v-if="!recent_feedback" class="px-5 py-6">
            <div class="rounded-2xl border border-border bg-surface-muted p-6 text-center">
              <div class="text-sm font-semibold text-text-primary">No feedback yet</div>
              <div class="mt-1 text-xs text-text-muted">Feedback appears after successful submissions.</div>
            </div>
          </div>

          <div v-else class="px-5 py-5">
            <div class="rounded-2xl border border-border bg-surface-muted p-5">
              <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                <div>
                  <div class="text-sm font-semibold text-text-primary">
                    Rating: {{ recent_feedback.rating_value ?? '—' }}/5
                    <span v-if="recent_feedback.rating_label" class="text-text-muted">• {{ recent_feedback.rating_label }}</span>
                  </div>
                  <div class="mt-1 text-xs text-text-muted">
                    Submitted {{ recent_feedback.submitted_at ?? '—' }} • Source {{ recent_feedback.source ?? '—' }}
                  </div>
                </div>
                <div class="text-xs text-text-muted">
                  <Link v-if="can_view_internal_application" :href="`/finance/applications/${recent_feedback.application_id}/track`" class="zaqa-link">
                    View related application
                  </Link>
                </div>
              </div>

              <div class="mt-4 text-sm text-text-primary">
                <div v-if="recent_feedback.feedback_text && recent_feedback.feedback_text.trim().length > 0" class="whitespace-pre-wrap">
                  {{ recent_feedback.feedback_text }}
                </div>
                <div v-else class="text-xs text-text-muted">No comment provided.</div>
              </div>
            </div>
          </div>
        </div>

        <div class="overflow-hidden rounded-2xl border border-border bg-surface shadow-sm">
          <div class="border-b border-border bg-surface-muted px-5 py-4">
            <div class="flex items-center gap-2 text-sm font-semibold text-text-primary">
              <ClipboardList class="h-4 w-4" aria-hidden="true" />
              Recent applications
            </div>
            <div class="mt-1 text-xs text-text-muted">Latest submissions by this applicant.</div>
          </div>

          <div v-if="recent_applications.length === 0" class="px-5 py-6">
            <div class="rounded-2xl border border-border bg-surface-muted p-6 text-center">
              <div class="text-sm font-semibold text-text-primary">No applications yet</div>
              <div class="mt-1 text-xs text-text-muted">Once the applicant creates applications, they’ll appear here.</div>
            </div>
          </div>

          <div v-else class="overflow-x-auto">
            <table class="min-w-full text-sm">
              <thead class="bg-surface-muted text-xs font-semibold text-text-muted">
                <tr>
                  <th class="px-5 py-3 text-left">Application</th>
                  <th class="px-5 py-3 text-left">Service</th>
                  <th class="px-5 py-3 text-left">Status</th>
                  <th class="px-5 py-3 text-right">Action</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-border/60">
                <tr v-for="a in recent_applications" :key="a.id" class="hover:bg-surface-muted/60">
                  <td class="px-5 py-3">
                    <div class="font-semibold text-text-primary">{{ a.application_number ?? `#${a.id}` }}</div>
                    <div class="mt-0.5 text-xs text-text-muted">
                      {{ a.is_foreign ? 'Foreign' : 'Local' }} • Created {{ a.created_at ?? '—' }}
                    </div>
                  </td>
                  <td class="px-5 py-3 text-text-primary">
                    {{ a.service_type ?? '—' }}
                    <div class="mt-0.5 text-xs text-text-muted">{{ a.qualification_category ?? '' }}</div>
                  </td>
                  <td class="px-5 py-3">
                    <span class="zaqa-badge">{{ a.status_label ?? a.current_status ?? '—' }}</span>
                  </td>
                  <td class="px-5 py-3 text-right">
                    <Link
                      v-if="can_view_internal_application"
                      :href="`/finance/applications/${a.id}/track`"
                      class="zaqa-btn zaqa-btn-secondary h-9 px-3 py-2 text-xs"
                    >
                      View full details
                    </Link>
                    <span v-else class="text-xs text-text-muted">—</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>

