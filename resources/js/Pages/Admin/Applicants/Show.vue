<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { Link } from '@inertiajs/vue3'
import {
  ArrowLeft,
  Building2,
  Calendar,
  ClipboardList,
  Hash,
  Mail,
  MapPin,
  MessageSquare,
  Phone,
  Shield,
  Star,
  User,
  Users,
} from 'lucide-vue-next'
import { computed } from 'vue'

const props = defineProps<{
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

const isIndividual = computed(() => (props.applicant.applicant_type ?? '') === 'individual')
const isInstitution = computed(() => (props.applicant.applicant_type ?? '') === 'institution')

const typeLabel = computed(() => {
  if (isIndividual.value) return 'Individual applicant'
  if (isInstitution.value) return 'Institutional applicant'
  return 'Applicant'
})

const pageTitle = computed(() =>
  isInstitution.value && props.applicant.profile?.institution_name
    ? props.applicant.profile.institution_name
    : props.applicant.name,
)

const heroSubtitle = computed(() => {
  if (isInstitution.value && props.applicant.profile?.institution_name) {
    return props.applicant.name
  }
  return props.applicant.email
})

function formatDate(iso: string | null | undefined) {
  if (!iso) return '—'
  try {
    return new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
  } catch {
    return iso
  }
}

function formatDateShort(iso: string | null | undefined) {
  if (!iso) return '—'
  try {
    return new Date(iso).toLocaleDateString(undefined, { dateStyle: 'medium' })
  } catch {
    return iso
  }
}

function applicationBadgeClass(status: string | null | undefined) {
  const s = (status ?? '').toString()
  if (['approved', 'certificate_ready', 'completed'].includes(s)) return 'zaqa-badge-success'
  if (['rejected'].includes(s)) return 'zaqa-badge-danger'
  if (['draft', 'pending_payment'].includes(s)) return 'zaqa-badge-secondary'
  if (['submitted', 'resubmitted', 'sent_back'].includes(s)) return 'zaqa-badge-warning'
  if (['in_progress'].includes(s)) return 'zaqa-badge-info'
  return 'zaqa-badge-secondary'
}

const ratingStars = computed(() => {
  const raw = props.recent_feedback?.rating_value
  const n = typeof raw === 'number' ? raw : Number.parseInt(String(raw ?? ''), 10)
  if (Number.isNaN(n) || n < 1) return 0
  return Math.min(5, Math.max(1, Math.round(n)))
})
</script>

<template>
  <AdminLayout>
    <div class="w-full min-w-0 max-w-none">
      <!-- Top actions -->
      <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <Link
          href="/admin/applicants"
          class="inline-flex items-center gap-2 text-sm font-semibold text-brand hover:text-brand-dark"
        >
          <ArrowLeft class="h-4 w-4 shrink-0" aria-hidden="true" />
          Applicants directory
        </Link>
      </div>

      <!-- Hero -->
      <section
        class="relative mt-6 overflow-hidden rounded-2xl border border-border bg-surface shadow-md ring-1 ring-brand/[0.12]"
      >
        <div
          class="pointer-events-none absolute inset-0 bg-[radial-gradient(900px_380px_at_0%_-20%,color-mix(in_oklab,var(--color-brand)_18%,transparent),transparent_55%),radial-gradient(720px_320px_at_100%_0%,color-mix(in_oklab,var(--color-accent)_14%,transparent),transparent_48%)]"
          aria-hidden="true"
        />
        <div class="relative flex flex-col gap-6 p-6 sm:flex-row sm:items-start sm:justify-between sm:p-8">
          <div class="flex min-w-0 gap-5">
            <div
              class="flex h-16 w-16 shrink-0 items-center justify-center rounded-2xl border border-border bg-surface shadow-sm sm:h-20 sm:w-20"
              :class="isInstitution ? 'text-brand' : 'text-brand'"
            >
              <Building2 v-if="isInstitution" class="h-9 w-9 sm:h-10 sm:w-10" aria-hidden="true" />
              <User v-else class="h-9 w-9 sm:h-10 sm:w-10" aria-hidden="true" />
            </div>
            <div class="min-w-0 flex-1">
              <div class="flex flex-wrap items-center gap-2">
                <span
                  class="inline-flex items-center rounded-full border border-brand/25 bg-brand/10 px-2.5 py-0.5 text-[11px] font-bold uppercase tracking-wider text-brand"
                >
                  {{ typeLabel }}
                </span>
                <span
                  class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-[11px] font-semibold uppercase tracking-wide"
                  :class="
                    applicant.disabled_at
                      ? 'border-danger/30 bg-danger/10 text-danger'
                      : applicant.is_active
                        ? 'border-success/30 bg-success/10 text-success'
                        : 'border-border bg-surface-muted text-text-muted'
                  "
                >
                  {{ applicant.disabled_at ? 'Account disabled' : applicant.is_active ? 'Active' : 'Inactive' }}
                </span>
              </div>
              <h1 class="mt-3 break-words text-2xl font-bold tracking-tight text-text-primary sm:text-3xl">
                {{ pageTitle }}
              </h1>
              <p class="mt-1 text-sm text-text-muted">{{ heroSubtitle }}</p>

              <div class="mt-5 flex flex-wrap gap-x-6 gap-y-2 text-sm">
                <span class="inline-flex items-center gap-2 text-text-primary">
                  <Mail class="h-4 w-4 shrink-0 text-text-muted" aria-hidden="true" />
                  <span class="truncate">{{ applicant.email ?? '—' }}</span>
                </span>
                <span class="inline-flex items-center gap-2 text-text-primary">
                  <Phone class="h-4 w-4 shrink-0 text-text-muted" aria-hidden="true" />
                  {{ applicant.phone_primary ?? '—' }}
                </span>
                <span class="inline-flex items-center gap-2 text-text-muted">
                  <Calendar class="h-4 w-4 shrink-0" aria-hidden="true" />
                  Joined {{ formatDateShort(applicant.created_at) }}
                </span>
              </div>
            </div>
          </div>

          <div class="flex shrink-0 flex-col gap-2 sm:items-end">
            <div class="rounded-xl border border-border bg-surface-muted/90 px-4 py-3 text-right text-xs text-text-muted shadow-inner">
              <div class="font-semibold uppercase tracking-wider text-text-muted">Applicant ID</div>
              <div class="mt-1 font-mono text-sm font-semibold text-text-primary">#{{ applicant.id }}</div>
            </div>
          </div>
        </div>
      </section>

      <!-- Metrics -->
      <section class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5 lg:gap-4">
        <div
          class="rounded-2xl border border-border bg-surface p-4 shadow-sm ring-1 ring-black/[0.02] transition hover:border-brand/25"
        >
          <div class="text-[11px] font-bold uppercase tracking-wider text-text-muted">Applications</div>
          <div class="mt-2 text-2xl font-bold tabular-nums text-text-primary">{{ stats.total }}</div>
          <div class="mt-1 text-xs text-text-muted">All time</div>
        </div>
        <div
          class="rounded-2xl border border-border bg-surface p-4 shadow-sm ring-1 ring-black/[0.02] transition hover:border-brand/25"
        >
          <div class="text-[11px] font-bold uppercase tracking-wider text-text-muted">Submitted</div>
          <div class="mt-2 text-2xl font-bold tabular-nums text-text-primary">{{ stats.submitted }}</div>
          <div class="mt-1 text-xs text-text-muted">In workflow</div>
        </div>
        <div
          class="rounded-2xl border border-border bg-surface p-4 shadow-sm ring-1 ring-black/[0.02] transition hover:border-brand/25"
        >
          <div class="text-[11px] font-bold uppercase tracking-wider text-text-muted">Successful</div>
          <div class="mt-2 text-2xl font-bold tabular-nums text-success">{{ stats.success }}</div>
          <div class="mt-1 text-xs text-text-muted">Approved / issued</div>
        </div>
        <div
          class="rounded-2xl border border-border bg-surface p-4 shadow-sm ring-1 ring-black/[0.02] transition hover:border-brand/25"
        >
          <div class="text-[11px] font-bold uppercase tracking-wider text-text-muted">In progress</div>
          <div class="mt-2 text-2xl font-bold tabular-nums text-text-primary">{{ stats.pending }}</div>
          <div class="mt-1 text-xs text-text-muted">Draft / pending / live</div>
        </div>
        <div
          class="col-span-2 rounded-2xl border border-border bg-surface p-4 shadow-sm ring-1 ring-black/[0.02] sm:col-span-1 lg:col-span-1"
        >
          <div class="text-[11px] font-bold uppercase tracking-wider text-text-muted">Rejected</div>
          <div class="mt-2 text-2xl font-bold tabular-nums text-danger">{{ stats.failed }}</div>
          <div class="mt-1 text-xs text-text-muted">Final rejection</div>
        </div>
      </section>

      <div class="mt-8 grid gap-8 lg:grid-cols-12 lg:items-start">
        <!-- Sidebar: profile -->
        <aside class="space-y-6 lg:col-span-4 lg:sticky lg:top-6 lg:self-start">
          <section class="overflow-hidden rounded-2xl border border-border bg-surface shadow-sm ring-1 ring-black/[0.03]">
            <div class="border-b border-border bg-surface-muted/80 px-5 py-4">
              <div class="flex items-center gap-2 text-sm font-semibold text-text-primary">
                <Shield class="h-4 w-4 text-brand" aria-hidden="true" />
                Account & access
              </div>
              <p class="mt-1 text-xs leading-relaxed text-text-muted">Portal login identity and role assignments.</p>
            </div>
            <dl class="divide-y divide-border/70 px-5 py-2">
              <div class="flex justify-between gap-4 py-3">
                <dt class="text-xs font-medium text-text-muted">Display name</dt>
                <dd class="max-w-[60%] text-right text-sm font-semibold text-text-primary">{{ applicant.name }}</dd>
              </div>
              <div class="flex justify-between gap-4 py-3">
                <dt class="text-xs font-medium text-text-muted">Email</dt>
                <dd class="max-w-[60%] break-all text-right text-sm font-medium text-text-primary">
                  {{ applicant.email ?? '—' }}
                </dd>
              </div>
              <div class="flex justify-between gap-4 py-3">
                <dt class="text-xs font-medium text-text-muted">Primary phone</dt>
                <dd class="text-right text-sm font-medium text-text-primary">{{ applicant.phone_primary ?? '—' }}</dd>
              </div>
              <div class="flex justify-between gap-4 py-3">
                <dt class="text-xs font-medium text-text-muted">Registered</dt>
                <dd class="text-right text-sm text-text-primary">{{ formatDate(applicant.created_at) }}</dd>
              </div>
            </dl>
            <div class="border-t border-border px-5 py-4">
              <div class="text-[11px] font-bold uppercase tracking-wider text-text-muted">Roles</div>
              <div class="mt-3 flex flex-wrap gap-2">
                <span
                  v-for="r in applicant.roles ?? []"
                  :key="r"
                  class="rounded-full border border-border bg-surface-muted px-3 py-1 text-xs font-semibold text-text-primary"
                >
                  {{ r }}
                </span>
                <span v-if="(applicant.roles ?? []).length === 0" class="text-xs text-text-muted">Applicant (default)</span>
              </div>
            </div>
          </section>

          <!-- Type-specific profile -->
          <section class="overflow-hidden rounded-2xl border border-border bg-surface shadow-sm ring-1 ring-black/[0.03]">
            <div class="border-b border-border bg-surface-muted/80 px-5 py-4">
              <div class="flex items-center gap-2 text-sm font-semibold text-text-primary">
                <User v-if="isIndividual" class="h-4 w-4 text-brand" aria-hidden="true" />
                <Building2 v-else class="h-4 w-4 text-brand" aria-hidden="true" />
                {{ isIndividual ? 'Individual profile' : 'Institution profile' }}
              </div>
              <p class="mt-1 text-xs leading-relaxed text-text-muted">
                {{
                  isIndividual
                    ? 'Identity documents and contact address on file.'
                    : 'Registered organisation details and authorised contact.'
                }}
              </p>
            </div>

            <dl v-if="isIndividual" class="divide-y divide-border/70 px-5 py-2">
              <div class="flex justify-between gap-4 py-3">
                <dt class="text-xs font-medium text-text-muted">NRC</dt>
                <dd class="text-right text-sm font-medium text-text-primary">{{ applicant.profile?.nrc_number ?? '—' }}</dd>
              </div>
              <div class="flex justify-between gap-4 py-3">
                <dt class="text-xs font-medium text-text-muted">Passport</dt>
                <dd class="text-right text-sm font-medium text-text-primary">
                  {{ applicant.profile?.passport_number ?? '—' }}
                </dd>
              </div>
              <div class="py-3">
                <dt class="text-xs font-medium text-text-muted">Address</dt>
                <dd class="mt-2 flex gap-2 text-sm leading-relaxed text-text-primary">
                  <MapPin class="mt-0.5 h-4 w-4 shrink-0 text-text-muted" aria-hidden="true" />
                  <span>
                    <template v-if="applicant.profile?.address_line1 || applicant.profile?.city">
                      {{ applicant.profile?.address_line1 }}
                      <span v-if="applicant.profile?.address_line2"><br />{{ applicant.profile.address_line2 }}</span>
                      <span v-if="applicant.profile?.city"><br />{{ applicant.profile.city }}</span>
                    </template>
                    <template v-else>—</template>
                  </span>
                </dd>
              </div>
            </dl>

            <dl v-else class="divide-y divide-border/70 px-5 py-2">
              <div class="flex justify-between gap-4 py-3">
                <dt class="text-xs font-medium text-text-muted">Institution</dt>
                <dd class="max-w-[65%] text-right text-sm font-semibold leading-snug text-text-primary">
                  {{ applicant.profile?.institution_name ?? '—' }}
                </dd>
              </div>
              <div class="flex justify-between gap-4 py-3">
                <dt class="inline-flex items-center gap-1 text-xs font-medium text-text-muted">
                  <Hash class="h-3.5 w-3.5" aria-hidden="true" />
                  TPIN
                </dt>
                <dd class="font-mono text-sm font-medium text-text-primary">{{ applicant.profile?.tpin ?? '—' }}</dd>
              </div>
              <div class="flex justify-between gap-4 py-3">
                <dt class="text-xs font-medium text-text-muted">Contact person</dt>
                <dd class="max-w-[60%] text-right text-sm font-medium text-text-primary">
                  {{ applicant.profile?.contact_person_name ?? '—' }}
                </dd>
              </div>
            </dl>
          </section>
        </aside>

        <!-- Main -->
        <div class="min-w-0 space-y-8 lg:col-span-8">
          <!-- Feedback -->
          <section class="overflow-hidden rounded-2xl border border-border bg-surface shadow-sm ring-1 ring-black/[0.03]">
            <div class="border-b border-border bg-surface-muted/80 px-5 py-4 sm:px-6">
              <div class="flex items-center gap-2 text-sm font-semibold text-text-primary">
                <MessageSquare class="h-4 w-4 text-brand" aria-hidden="true" />
                Service feedback
              </div>
              <p class="mt-1 text-xs text-text-muted">Most recent rating and comment from this applicant.</p>
            </div>

            <div v-if="!recent_feedback" class="px-5 py-12 text-center sm:px-6">
              <MessageSquare class="mx-auto h-10 w-10 text-text-muted/40" aria-hidden="true" />
              <p class="mt-4 text-sm font-semibold text-text-primary">No feedback submitted yet</p>
              <p class="mt-1 text-xs text-text-muted">Feedback appears after eligible service interactions.</p>
            </div>

            <div v-else class="p-5 sm:p-6">
              <div class="flex flex-col gap-4 rounded-xl border border-border bg-gradient-to-br from-surface to-surface-muted/40 p-5 sm:flex-row sm:items-start sm:justify-between">
                <div>
                  <div class="flex flex-wrap items-center gap-3">
                    <div class="flex items-center gap-0.5" aria-hidden="true">
                      <Star
                        v-for="n in 5"
                        :key="n"
                        class="h-5 w-5"
                        :class="n <= ratingStars ? 'fill-accent text-accent' : 'fill-none text-border'"
                      />
                    </div>
                    <span class="text-sm font-semibold text-text-primary">
                      {{ recent_feedback.rating_value ?? '—' }}/5
                      <span v-if="recent_feedback.rating_label" class="font-normal text-text-muted">
                        · {{ recent_feedback.rating_label }}
                      </span>
                    </span>
                  </div>
                  <div class="mt-2 text-xs text-text-muted">
                    {{ formatDate(recent_feedback.submitted_at) }}
                    <span v-if="recent_feedback.source"> · {{ recent_feedback.source }}</span>
                  </div>
                </div>
                <Link
                  v-if="can_view_internal_application"
                  :href="`/finance/applications/${recent_feedback.application_id}/track`"
                  class="zaqa-btn zaqa-btn-secondary h-10 shrink-0 px-4 py-2 text-xs sm:self-start"
                >
                  Related application
                </Link>
              </div>
              <div class="mt-5 rounded-xl border border-border/80 bg-surface px-4 py-4">
                <div class="text-[11px] font-bold uppercase tracking-wider text-text-muted">Comment</div>
                <p
                  v-if="recent_feedback.feedback_text && recent_feedback.feedback_text.trim().length > 0"
                  class="mt-2 whitespace-pre-wrap text-sm leading-relaxed text-text-primary"
                >
                  {{ recent_feedback.feedback_text }}
                </p>
                <p v-else class="mt-2 text-sm text-text-muted">No written comment.</p>
              </div>
            </div>
          </section>

          <!-- Applications -->
          <section class="overflow-hidden rounded-2xl border border-border bg-surface shadow-sm ring-1 ring-black/[0.03]">
            <div class="flex flex-col gap-3 border-b border-border bg-surface-muted/80 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
              <div>
                <div class="flex items-center gap-2 text-sm font-semibold text-text-primary">
                  <ClipboardList class="h-4 w-4 text-brand" aria-hidden="true" />
                  Recent applications
                </div>
                <p class="mt-1 text-xs text-text-muted">Latest verification requests (newest first).</p>
              </div>
              <span class="inline-flex items-center gap-1.5 rounded-full border border-border bg-surface px-3 py-1 text-xs font-semibold text-text-muted">
                <Users class="h-3.5 w-3.5" aria-hidden="true" />
                {{ recent_applications.length }} shown
              </span>
            </div>

            <div v-if="recent_applications.length === 0" class="px-5 py-12 text-center sm:px-6">
              <ClipboardList class="mx-auto h-10 w-10 text-text-muted/40" aria-hidden="true" />
              <p class="mt-4 text-sm font-semibold text-text-primary">No applications yet</p>
              <p class="mt-1 text-xs text-text-muted">New submissions will appear in this list automatically.</p>
            </div>

            <div v-else class="overflow-x-auto">
              <table class="min-w-full text-sm">
                <thead>
                  <tr class="border-b border-border bg-surface-muted/50 text-left text-[11px] font-bold uppercase tracking-wider text-text-muted">
                    <th class="px-5 py-3 sm:px-6">Application</th>
                    <th class="px-5 py-3 sm:px-6">Service</th>
                    <th class="px-5 py-3 sm:px-6">Status</th>
                    <th class="px-5 py-3 text-right sm:px-6"></th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-border/60">
                  <tr
                    v-for="a in recent_applications"
                    :key="a.id"
                    class="transition-colors hover:bg-brand/[0.03]"
                  >
                    <td class="px-5 py-4 align-top sm:px-6">
                      <div class="font-semibold text-text-primary">{{ a.application_number ?? `#${a.id}` }}</div>
                      <div class="mt-1 flex flex-wrap gap-x-2 gap-y-0.5 text-xs text-text-muted">
                        <span>{{ a.is_foreign ? 'Foreign' : 'Local' }}</span>
                        <span aria-hidden="true">·</span>
                        <span>Created {{ formatDateShort(a.created_at) }}</span>
                      </div>
                    </td>
                    <td class="px-5 py-4 align-top text-text-primary sm:px-6">
                      <div>{{ a.service_type ?? '—' }}</div>
                      <div v-if="a.qualification_category" class="mt-0.5 text-xs text-text-muted">
                        {{ a.qualification_category }}
                      </div>
                    </td>
                    <td class="px-5 py-4 align-top sm:px-6">
                      <span class="zaqa-badge" :class="applicationBadgeClass(a.current_status)">
                        {{ a.status_label ?? a.current_status ?? '—' }}
                      </span>
                    </td>
                    <td class="px-5 py-4 text-right align-middle sm:px-6">
                      <Link
                        v-if="can_view_internal_application"
                        :href="`/finance/applications/${a.id}/track`"
                        class="zaqa-btn zaqa-btn-secondary inline-flex h-9 items-center px-3 py-2 text-xs font-semibold"
                      >
                        Track
                      </Link>
                      <span v-else class="text-xs text-text-muted">—</span>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>
