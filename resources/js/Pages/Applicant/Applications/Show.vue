<script setup lang="ts">
import { computed, ref } from 'vue'
import { Link } from '@inertiajs/vue3'
import ApplicantLayout from '@/Layouts/ApplicantLayout.vue'
import {
  ArrowLeft,
  Building2,
  Calendar,
  CheckCircle2,
  Copy,
  Check,
  CreditCard,
  FileText,
  GraduationCap,
  Hash,
  History,
  Layers,
  MapPin,
  Sparkles,
  UserRound,
  ScrollText,
  Shield,
  FileDown,
} from 'lucide-vue-next'

const props = defineProps<{
  application: any
  countries: Array<{ id: number; name: string }>
  awardingInstitutions: Array<{ id: number; name: string }>
  localConsent: { title: string; text: string; version: string }
  applicant: any
}>()

const copiedQualId = ref<number | null>(null)
let copyTimer: ReturnType<typeof setTimeout> | null = null

const countryNameById = computed(() => new Map(props.countries.map((c) => [c.id, c.name])))

function formatCveqIssuedAt(iso: string | null | undefined): string {
  if (!iso) return ''
  try {
    return new Date(iso).toLocaleString(undefined, { dateStyle: 'medium' })
  } catch {
    return iso
  }
}

const qualificationsList = computed<any[]>(() => {
  const multi = props.application?.qualifications
  if (Array.isArray(multi) && multi.length > 0) {
    return multi
  }
  const single = props.application?.qualification
  return single ? [single] : []
})

const qualificationsNeedingAmendment = computed(() =>
  qualificationsList.value.filter((q: any) => (q.verification_state ?? '') === 'returned_to_applicant'),
)

const isDraftLike = computed(() => {
  const s = (props.application?.current_status ?? '').toString().toLowerCase()
  return s === 'draft' || s === 'pending_payment'
})

const canContinueEditing = computed(() => {
  if (!props.application?.can_edit) return false
  const s = (props.application?.current_status ?? '').toString().toLowerCase()
  return s === 'draft' || s === 'pending_payment' || s === 'sent_back'
})

function formatDisplayDate(iso: string | null | undefined): string {
  if (!iso) return '—'
  try {
    const d = new Date(iso)
    return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(d)
  } catch {
    return String(iso)
  }
}

async function copyVerificationRef(qualId: number, refText: string) {
  if (!refText) return
  try {
    await navigator.clipboard.writeText(refText)
    copiedQualId.value = qualId
    if (copyTimer) clearTimeout(copyTimer)
    copyTimer = setTimeout(() => {
      copiedQualId.value = null
    }, 2000)
  } catch {
    // ignore
  }
}

const subjectIdNumber = computed(() => {
  const vs = props.application?.metadata?.verification_subject ?? null
  const nrc = (vs?.nrc_number ?? '').toString().trim()
  const passport = (vs?.passport_number ?? '').toString().trim()
  if (nrc.length) return nrc
  if (passport.length) return passport

  const qualId = (props.application?.qualification?.nrc_passport_number ?? '').toString().trim()
  if (qualId.length) return qualId

  const selfNrc = (props.applicant?.applicant_profile?.nrc_number ?? '').toString().trim()
  const selfPassport = (props.applicant?.applicant_profile?.passport_number ?? '').toString().trim()
  return selfNrc.length ? selfNrc : selfPassport.length ? selfPassport : ''
})

const subjectGender = computed(() => {
  const vs = props.application?.metadata?.verification_subject ?? null
  const v = (vs?.gender ?? '').toString().trim().toLowerCase()
  if (v === 'male') return 'Male'
  if (v === 'female') return 'Female'

  const self = (props.applicant?.applicant_profile?.gender ?? '').toString().trim().toLowerCase()
  if (self === 'male') return 'Male'
  if (self === 'female') return 'Female'

  return ''
})

function countryLabel(countryId: number | null, other: string | null) {
  if (countryId) return countryNameById.value.get(countryId) ?? `#${countryId}`
  return other && other.length > 0 ? other : '—'
}

function qualAwardScope(q: any) {
  if (typeof q.is_foreign_qualification === 'boolean') {
    return q.is_foreign_qualification ? 'Foreign' : 'Local (Zambia)'
  }
  const iso = (q.country?.iso_code ?? '').toString().toUpperCase()
  if (iso === 'ZMB' || iso === 'ZM') return 'Local (Zambia)'
  if (iso) return 'Foreign'
  return props.application.is_foreign ? 'Foreign' : 'Local (Zambia)'
}

function qualNeedsForeignConsent(q: any): boolean {
  if (typeof q.requires_foreign_consent === 'boolean') return q.requires_foreign_consent
  const iso = (q.country?.iso_code ?? '').toString().toUpperCase()
  if (iso === 'ZMB' || iso === 'ZM') return false
  if (iso) return true
  return !!props.application.is_foreign && qualificationsList.value.length <= 1
}

function qualConsentLabel(q: any): { text: string; ok: boolean } {
  if (!qualNeedsForeignConsent(q)) {
    return { text: 'Not required (Zambian awarding institution)', ok: true }
  }
  if (typeof q.has_foreign_consent === 'boolean') {
    return q.has_foreign_consent
      ? { text: 'Institution consent on file', ok: true }
      : { text: 'Institution consent pending', ok: false }
  }
  const legacy = !!props.application.consent_form?.uploaded_document_id
  return legacy ? { text: 'Institution consent on file', ok: true } : { text: 'Institution consent pending', ok: false }
}

const currentDocuments = computed(() =>
  (props.application?.documents ?? []).filter((d: any) => d.is_current_version),
)

const QUALIFICATION_SCOPED_DOC_TYPES = ['certificate_copy', 'transcript', 'consent_form_signed']

function documentsForQualification(q: any, index: number) {
  const qid = Number(q.id)
  return currentDocuments.value.filter((d: any) => {
    const dq = d.qualification_id
    if (dq != null && dq !== '' && Number(dq) === qid) {
      return true
    }
    const singleLegacy =
      qualificationsList.value.length === 1 &&
      index === 0 &&
      (dq == null || dq === '' || Number(dq) === 0) &&
      QUALIFICATION_SCOPED_DOC_TYPES.includes(String(d.document_type ?? ''))
    return singleLegacy
  })
}

const applicationLevelDocuments = computed(() => {
  return currentDocuments.value.filter((d: any) => {
    const dq = d.qualification_id
    const t = String(d.document_type ?? '')
    if (dq != null && dq !== '' && Number(dq) > 0) {
      return false
    }
    if (qualificationsList.value.length === 1 && QUALIFICATION_SCOPED_DOC_TYPES.includes(t)) {
      return false
    }
    return true
  })
})

function docTypeLabel(documentType: string) {
  return String(documentType || '')
    .replace(/_/g, ' ')
    .replace(/\b\w/g, (c) => c.toUpperCase())
}

function money(cents: number, currency: string) {
  return new Intl.NumberFormat(undefined, { style: 'currency', currency: currency || 'ZMW' }).format((cents ?? 0) / 100)
}
</script>

<template>
  <ApplicantLayout>
    <div class="relative min-h-[60vh]">
      <!-- Ambient background -->
      <div
        class="pointer-events-none absolute inset-0 -z-10 overflow-hidden"
        aria-hidden="true"
      >
        <div
          class="absolute -left-20 top-0 h-72 w-72 rounded-full bg-brand/12 blur-3xl"
        />
        <div
          class="absolute -right-16 top-32 h-80 w-80 rounded-full bg-accent/10 blur-3xl"
        />
        <div
          class="absolute bottom-0 left-1/2 h-px w-[120%] -translate-x-1/2 bg-gradient-to-r from-transparent via-border/80 to-transparent"
        />
      </div>

      <div class="zaqa-wizard-shell">
        <!-- Top bar -->
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
          <div>
            <Link
              href="/applicant/applications"
              class="inline-flex items-center gap-1.5 text-sm font-medium text-text-muted transition hover:text-brand"
            >
              <ArrowLeft class="h-4 w-4 shrink-0" aria-hidden="true" />
              All applications
            </Link>
            <div class="mt-4 flex items-center gap-2">
              <span
                class="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-brand to-brand-dark text-text-on-dark shadow-md shadow-brand/25"
              >
                <Sparkles class="h-4 w-4" aria-hidden="true" />
              </span>
              <div>
                <h1 class="text-xl font-semibold tracking-tight text-text-primary sm:text-2xl">
                  Application overview
                </h1>
                <p class="mt-0.5 max-w-xl text-sm text-text-muted">
                  <template v-if="isDraftLike">
                    Your verification application — complete the wizard and proceed to payment. Once payment is confirmed,
                    your application is automatically submitted for verification.
                  </template>
                  <template v-else>
                    Your submitted verification request with ZAQA — keep your references handy when you contact us about a
                    specific qualification.
                  </template>
                </p>
              </div>
            </div>
          </div>

          <div class="flex flex-wrap gap-2 sm:justify-end">
            <Link
              :href="`/applicant/applications/${application.id}/track`"
              class="zaqa-btn zaqa-btn-secondary inline-flex items-center gap-2 px-4 py-2.5 text-sm font-semibold shadow-sm"
            >
              <ScrollText class="h-4 w-4 opacity-80" aria-hidden="true" />
              Track progress
            </Link>
            <Link
              v-if="canContinueEditing"
              :href="`/applicant/applications/${application.id}/edit`"
              class="zaqa-btn zaqa-btn-primary inline-flex items-center gap-2 px-4 py-2.5 text-sm font-semibold shadow-md shadow-brand/20"
            >
              Continue editing
            </Link>
          </div>
        </div>

        <!-- Hero summary card -->
        <div
          class="mt-8 overflow-hidden rounded-3xl border border-border/80 bg-surface shadow-[0_20px_50px_-12px_rgba(11,58,102,0.15)] ring-1 ring-black/[0.04]"
        >
          <div
            class="relative border-b border-border/70 bg-gradient-to-br from-brand-dark via-brand-dark to-brand px-5 py-6 text-text-on-dark sm:px-8 sm:py-8"
          >
            <div
              class="pointer-events-none absolute inset-0 opacity-[0.12]"
              style="
                background-image: url('data:image/svg+xml,%3Csvg width=\'60\' height=\'60\' viewBox=\'0 0 60 60\' xmlns=\'http://www.w3.org/2000/svg\'%3E%3Cg fill=\'none\' fill-rule=\'evenodd\'%3E%3Cg fill=\'%23ffffff\' fill-opacity=\'1\'%3E%3Cpath d=\'M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z\'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E');
              "
            />
            <div class="relative flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
              <div class="min-w-0">
                <div class="text-[11px] font-semibold uppercase tracking-[0.2em] text-white/70">Application reference</div>
                <div class="mt-2 font-mono text-2xl font-bold tracking-tight text-white sm:text-3xl">
                  {{ application.application_number }}
                </div>
                <div class="mt-3 flex flex-wrap items-center gap-2">
                  <span
                    class="inline-flex items-center rounded-full border border-white/20 bg-white/10 px-3 py-1 text-xs font-semibold backdrop-blur-sm"
                  >
                    {{ application.status_label }}
                  </span>
                  <span v-if="application.service_type" class="text-xs text-white/80">
                    {{ application.service_type }} · {{ application.is_foreign ? 'Foreign scope' : 'Local (Zambia)' }}
                  </span>
                </div>
              </div>
              <dl
                class="grid shrink-0 grid-cols-1 gap-3 text-sm sm:grid-cols-2 lg:text-right"
              >
                <div class="rounded-xl border border-white/15 bg-white/10 px-4 py-3 backdrop-blur-sm">
                  <dt class="text-[10px] font-semibold uppercase tracking-wider text-white/65">Submitted</dt>
                  <dd class="mt-1 font-semibold text-white">
                    {{ formatDisplayDate(application.submitted_at) }}
                  </dd>
                </div>
                <div class="rounded-xl border border-white/15 bg-white/10 px-4 py-3 backdrop-blur-sm">
                  <dt class="text-[10px] font-semibold uppercase tracking-wider text-white/65">Service deadline</dt>
                  <dd class="mt-1 font-semibold text-white">
                    {{ formatDisplayDate(application.service_deadline_at) }}
                  </dd>
                </div>
              </dl>
            </div>
          </div>

          <div
            v-for="q in qualificationsNeedingAmendment"
            :key="'amend-' + q.id"
            class="border-b border-amber-300/40 bg-amber-50 px-5 py-4 sm:px-8"
          >
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
              <div class="min-w-0">
                <div class="text-sm font-semibold text-amber-950">Qualification update required</div>
                <div class="mt-0.5 text-sm font-medium text-text-primary">{{ q.title_of_qualification || 'Qualification' }}</div>
                <p v-if="q.amendment_comment" class="mt-2 whitespace-pre-wrap text-sm leading-relaxed text-amber-950/90">
                  {{ q.amendment_comment }}
                </p>
                <p v-else class="mt-2 text-sm text-amber-950/90">
                  ZAQA has returned this item for amendment. Use the button to update only this qualification.
                </p>
              </div>
              <Link
                :href="`/applicant/applications/${application.id}/qualifications/${q.id}/amend`"
                class="zaqa-btn zaqa-btn-warning h-10 shrink-0 px-4 py-2 text-sm"
              >
                Update qualification
              </Link>
            </div>
          </div>

          <div
            v-if="qualificationsList.length > 0"
            class="border-b border-border/70 bg-surface-muted/80 px-5 py-4 sm:px-8"
          >
            <div class="flex flex-wrap items-start gap-3">
              <Hash class="mt-0.5 h-5 w-5 shrink-0 text-brand" aria-hidden="true" />
              <div class="min-w-0 flex-1">
                <div class="text-sm font-semibold text-text-primary">Per-qualification verification references</div>
                <p class="mt-1 text-xs leading-relaxed text-text-muted">
                  Each programme or certificate you asked us to verify has its own code in the verification pool. Quote
                  <strong class="text-text-primary">this qualification reference</strong> (not only the application
                  number) when you follow up about that specific item.
                </p>
              </div>
            </div>
          </div>

          <div class="px-5 py-6 sm:px-8 sm:py-8">
            <!-- Section: Applicant -->
            <section class="scroll-mt-8">
              <div class="flex items-center gap-3 border-b border-border/60 pb-3">
                <span
                  class="flex h-9 w-9 items-center justify-center rounded-xl bg-brand/10 text-brand"
                  aria-hidden="true"
                >
                  <UserRound class="h-5 w-5" />
                </span>
                <div>
                  <h2 class="text-base font-semibold text-text-primary">Applicant & verification subject</h2>
                  <p class="text-xs text-text-muted">Who this application is for and how we reach you.</p>
                </div>
              </div>
              <div class="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div
                  class="group rounded-2xl border border-border/80 bg-surface-muted/50 p-4 transition hover:border-brand/20 hover:shadow-sm"
                >
                  <div class="text-[10px] font-semibold uppercase tracking-wider text-text-muted">Submitting for</div>
                  <div class="mt-1.5 text-sm font-semibold text-text-primary">
                    {{ (application.metadata?.submitting_for ?? 'self') === 'other' ? 'On behalf of someone else' : 'Myself' }}
                  </div>
                </div>
                <div
                  class="group rounded-2xl border border-border/80 bg-surface-muted/50 p-4 transition hover:border-brand/20 hover:shadow-sm"
                >
                  <div class="text-[10px] font-semibold uppercase tracking-wider text-text-muted">Verification subject</div>
                  <div class="mt-1.5 text-sm font-semibold text-text-primary">
                    {{ application.metadata?.verification_subject?.full_name || applicant?.name || '—' }}
                  </div>
                </div>
                <div
                  class="group rounded-2xl border border-border/80 bg-surface-muted/50 p-4 transition hover:border-brand/20 hover:shadow-sm"
                >
                  <div class="text-[10px] font-semibold uppercase tracking-wider text-text-muted">Subject NRC / Passport</div>
                  <div class="mt-1.5 font-mono text-sm font-semibold text-text-primary">{{ subjectIdNumber || '—' }}</div>
                </div>
                <div
                  class="group rounded-2xl border border-border/80 bg-surface-muted/50 p-4 transition hover:border-brand/20 hover:shadow-sm"
                >
                  <div class="text-[10px] font-semibold uppercase tracking-wider text-text-muted">Subject gender</div>
                  <div class="mt-1.5 text-sm font-semibold text-text-primary">{{ subjectGender || '—' }}</div>
                </div>
                <div
                  class="group rounded-2xl border border-border/80 bg-surface-muted/50 p-4 transition hover:border-brand/20 hover:shadow-sm"
                >
                  <div class="text-[10px] font-semibold uppercase tracking-wider text-text-muted">Email</div>
                  <div class="mt-1.5 text-sm font-semibold text-text-primary break-all">{{ applicant?.email ?? '—' }}</div>
                </div>
                <div
                  class="group rounded-2xl border border-border/80 bg-surface-muted/50 p-4 transition hover:border-brand/20 hover:shadow-sm sm:col-span-2 lg:col-span-1"
                >
                  <div class="text-[10px] font-semibold uppercase tracking-wider text-text-muted">Primary phone</div>
                  <div class="mt-1.5 text-sm font-semibold text-text-primary">{{ applicant?.phone_primary ?? '—' }}</div>
                </div>
              </div>
            </section>

            <div class="my-10 h-px bg-gradient-to-r from-transparent via-border to-transparent" />

            <!-- Section: Qualifications -->
            <section>
              <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                <div class="flex items-center gap-3">
                  <span
                    class="flex h-9 w-9 items-center justify-center rounded-xl bg-accent/15 text-accent-deep"
                    aria-hidden="true"
                  >
                    <GraduationCap class="h-5 w-5" />
                  </span>
                  <div>
                    <h2 class="text-base font-semibold text-text-primary">Qualifications for verification</h2>
                    <p class="text-xs text-text-muted">
                      {{ qualificationsList.length }} programme{{ qualificationsList.length === 1 ? '' : 's' }} on this
                      application
                    </p>
                  </div>
                </div>
              </div>

              <div v-if="qualificationsList.length === 0" class="mt-5 rounded-2xl border border-dashed border-border bg-surface-muted/40 px-5 py-8 text-center text-sm text-text-muted">
                No qualifications linked to this application.
              </div>

              <div v-else class="mt-6 space-y-8">
                <article
                  v-for="(q, idx) in qualificationsList"
                  :key="q.id ?? idx"
                  class="relative overflow-hidden rounded-3xl border border-border/90 bg-surface shadow-[0_8px_30px_-8px_rgba(0,0,0,0.08)] ring-1 ring-black/[0.03]"
                >
                  <div
                    class="absolute left-0 top-0 h-full w-1 bg-gradient-to-b from-brand via-brand to-brand-dark"
                    aria-hidden="true"
                  />

                  <!-- Reference ribbon — always visible -->
                  <div
                    class="border-b border-border/70 bg-gradient-to-r from-brand/[0.06] via-surface-muted/90 to-surface-muted/40 px-5 py-5 pl-6 sm:px-8 sm:py-6"
                  >
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                      <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                          <span
                            class="rounded-full bg-brand/10 px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wider text-brand"
                          >
                            Qualification {{ idx + 1 }}
                          </span>
                          <span v-if="qualAwardScope(q).startsWith('Foreign')" class="text-[10px] font-semibold uppercase tracking-wide text-accent-deep">
                            Foreign award
                          </span>
                        </div>
                        <h3 class="mt-2 text-lg font-semibold leading-snug tracking-tight text-text-primary sm:text-xl">
                          {{ q.title_of_qualification || 'Untitled qualification' }}
                        </h3>
                      </div>

                      <div
                        class="flex w-full shrink-0 flex-col gap-2 rounded-2xl border-2 border-brand/25 bg-surface px-4 py-4 shadow-inner shadow-brand/5 sm:max-w-md lg:w-auto lg:min-w-[280px]"
                      >
                        <div class="flex items-center justify-between gap-2">
                          <div class="flex items-center gap-2 text-brand">
                            <Hash class="h-4 w-4 shrink-0 opacity-80" aria-hidden="true" />
                            <span class="text-[10px] font-bold uppercase tracking-[0.18em] text-text-muted">
                              Verification reference
                            </span>
                          </div>
                          <button
                            v-if="q.verification_reference_number"
                            type="button"
                            class="inline-flex items-center gap-1 rounded-lg border border-border bg-surface-muted px-2 py-1 text-[11px] font-semibold text-text-primary transition hover:border-brand/40 hover:bg-brand/5"
                            @click="copyVerificationRef(Number(q.id), q.verification_reference_number)"
                          >
                            <Check v-if="copiedQualId === Number(q.id)" class="h-3.5 w-3.5 text-success" />
                            <Copy v-else class="h-3.5 w-3.5 opacity-70" />
                            {{ copiedQualId === Number(q.id) ? 'Copied' : 'Copy' }}
                          </button>
                        </div>
                        <p
                          v-if="q.verification_reference_number"
                          class="break-all font-mono text-lg font-bold tracking-tight text-brand-dark sm:text-xl"
                        >
                          {{ q.verification_reference_number }}
                        </p>
                      <p v-else class="text-sm leading-relaxed text-text-muted">
                        <template v-if="isDraftLike">
                            This unique reference is generated automatically once your payment is confirmed. Complete the
                            wizard and proceed to payment to receive a verification reference for this qualification.
                        </template>
                        <template v-else>
                            A verification reference was not recorded for this row. If you submitted recently, refresh
                            the page or contact ZAQA with your application reference above.
                          </template>
                        </p>
                      </div>
                    </div>
                  </div>

                  <div class="grid grid-cols-1 gap-6 px-5 py-6 pl-6 sm:px-8 lg:grid-cols-2 lg:items-start">
                    <div class="space-y-4">
                      <div
                        v-if="q.cveq_certificate"
                        class="rounded-2xl border border-emerald-300/60 bg-emerald-50/95 p-4 sm:p-5"
                      >
                        <div class="flex flex-wrap items-start justify-between gap-3">
                          <div>
                            <div class="text-[10px] font-bold uppercase tracking-wider text-emerald-900">
                              ZAQA verification certificate
                            </div>
                            <p class="mt-1 font-mono text-sm font-semibold text-emerald-950">
                              {{ q.cveq_certificate.certificate_number }}
                            </p>
                            <p v-if="q.cveq_certificate.issued_at" class="mt-1 text-xs text-emerald-800">
                              Issued {{ formatCveqIssuedAt(q.cveq_certificate.issued_at) }}
                            </p>
                          </div>
                          <a
                            v-if="q.cveq_certificate.download_url"
                            :href="q.cveq_certificate.download_url"
                            class="zaqa-btn zaqa-btn-secondary inline-flex shrink-0 items-center gap-2 px-4 py-2 text-sm font-semibold"
                          >
                            <FileDown class="h-4 w-4" aria-hidden="true" />
                            Download PDF
                          </a>
                        </div>
                      </div>
                      <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div class="rounded-2xl border border-border/80 bg-surface-muted/40 p-4">
                          <div class="flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-wider text-text-muted">
                            <Layers class="h-3 w-3 opacity-60" aria-hidden="true" />
                            Qualification type
                          </div>
                          <div class="mt-2 text-sm font-semibold leading-snug text-text-primary">
                            {{
                              q.qualification_type_master
                                ? `${q.qualification_type_master.level_label} — ${q.qualification_type_master.name}`
                                : '—'
                            }}
                          </div>
                        </div>
                        <div class="rounded-2xl border border-border/80 bg-surface-muted/40 p-4">
                          <div class="flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-wider text-text-muted">
                            <MapPin class="h-3 w-3 opacity-60" aria-hidden="true" />
                            Award scope
                          </div>
                          <div class="mt-2 text-sm font-semibold text-text-primary">{{ qualAwardScope(q) }}</div>
                        </div>
                        <div class="rounded-2xl border border-border/80 bg-surface-muted/40 p-4">
                          <div class="text-[10px] font-semibold uppercase tracking-wider text-text-muted">Country of award</div>
                          <div class="mt-2 text-sm font-semibold text-text-primary">
                            {{ q.country?.name || countryLabel(q.country_id, q.country_name_other) }}
                          </div>
                        </div>
                        <div class="rounded-2xl border border-border/80 bg-surface-muted/40 p-4">
                          <div class="flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-wider text-text-muted">
                            <Building2 class="h-3 w-3 opacity-60" aria-hidden="true" />
                            Awarding institution
                          </div>
                          <div class="mt-2 text-sm font-semibold leading-snug text-text-primary">
                            {{ q.awarding_institution_name_other || q.awarding_institution?.name || q.awarding_institution_name || '—' }}
                          </div>
                        </div>
                        <div class="rounded-2xl border border-border/80 bg-surface-muted/40 p-4">
                          <div class="flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-wider text-text-muted">
                            <Calendar class="h-3 w-3 opacity-60" aria-hidden="true" />
                            Award date
                          </div>
                          <div class="mt-2 text-sm font-semibold text-text-primary">{{ q.award_date || '—' }}</div>
                        </div>
                        <div class="rounded-2xl border border-border/80 bg-surface-muted/40 p-4">
                          <div class="text-[10px] font-semibold uppercase tracking-wider text-text-muted">Certificate / student / exam ID</div>
                          <div class="mt-2 font-mono text-sm font-semibold text-text-primary">
                            {{ q.certificate_number || q.student_number || q.examination_number || '—' }}
                          </div>
                        </div>
                      </div>
                      <div v-if="q.notes" class="rounded-2xl border border-border/70 bg-amber-500/[0.06] p-4">
                        <div class="text-[10px] font-semibold uppercase tracking-wider text-text-muted">Notes</div>
                        <div class="mt-2 whitespace-pre-wrap text-sm text-text-primary">{{ q.notes }}</div>
                      </div>
                    </div>

                    <div
                      class="rounded-2xl border border-border/80 bg-gradient-to-b from-surface-muted/60 to-surface-muted/30 p-5 lg:sticky lg:top-6"
                    >
                      <div class="flex items-center gap-2 text-text-primary">
                        <FileText class="h-4 w-4 text-brand" aria-hidden="true" />
                        <span class="text-sm font-semibold">Documents for this qualification</span>
                      </div>
                      <p class="mt-1 text-xs leading-relaxed text-text-muted">
                        Certificate, transcript, and institution consent files supplied for this programme.
                      </p>
                      <div
                        v-if="documentsForQualification(q, idx).length === 0"
                        class="mt-4 rounded-xl border border-dashed border-border bg-surface/80 px-4 py-6 text-center text-sm text-text-muted"
                      >
                        No documents uploaded for this qualification.
                      </div>
                      <ul v-else class="mt-4 space-y-3">
                        <li
                          v-for="doc in documentsForQualification(q, idx)"
                          :key="doc.id"
                          class="rounded-xl border border-border/70 bg-surface px-4 py-3 shadow-sm"
                        >
                          <div class="text-[10px] font-semibold uppercase tracking-wide text-text-muted">
                            {{ docTypeLabel(doc.document_type) }}
                          </div>
                          <div class="mt-1 truncate text-sm font-medium text-text-primary" :title="doc.original_name">
                            {{ doc.original_name || '—' }}
                          </div>
                          <div class="mt-3 flex flex-wrap gap-3">
                            <a
                              v-if="doc.preview_url"
                              :href="doc.preview_url"
                              target="_blank"
                              rel="noopener"
                              class="zaqa-link text-xs font-semibold"
                            >
                              Preview
                            </a>
                            <a
                              v-if="doc.download_url"
                              :href="doc.download_url"
                              target="_blank"
                              rel="noopener"
                              class="zaqa-link text-xs font-semibold"
                            >
                              Download
                            </a>
                          </div>
                        </li>
                      </ul>
                    </div>
                  </div>

                  <div
                    v-if="q.subject_results && q.subject_results.length > 0"
                    class="border-t border-border/70 bg-surface-muted/30 px-5 py-5 pl-6 sm:px-8"
                  >
                    <div class="text-[10px] font-semibold uppercase tracking-wider text-text-muted">Subject results</div>
                    <div class="mt-3 overflow-hidden rounded-xl border border-border bg-surface shadow-sm">
                      <table class="w-full text-sm">
                        <thead class="bg-surface-muted text-left text-[10px] font-semibold uppercase tracking-wider text-text-muted">
                          <tr>
                            <th class="px-4 py-3">Subject</th>
                            <th class="px-4 py-3">Grade</th>
                          </tr>
                        </thead>
                        <tbody class="divide-y divide-border/60">
                          <tr v-for="(row, ridx) in q.subject_results" :key="ridx" class="hover:bg-surface-muted/40">
                            <td class="px-4 py-3 font-medium text-text-primary">{{ row.subject_name || '—' }}</td>
                            <td class="px-4 py-3 text-text-primary">{{ row.grade || '—' }}</td>
                          </tr>
                        </tbody>
                      </table>
                    </div>
                  </div>
                </article>
              </div>

              <p class="mt-8 rounded-xl border border-border/60 bg-surface-muted/50 px-4 py-3 text-xs leading-relaxed text-text-muted">
                Holder identity (NRC / passport) for verification is taken from the applicant / verification subject section
                above and applies to all listed qualifications.
              </p>
            </section>

            <div class="my-10 h-px bg-gradient-to-r from-transparent via-border to-transparent" />

            <!-- Application-level docs -->
            <section>
              <div class="flex items-center gap-3 border-b border-border/60 pb-3">
                <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-brand/10 text-brand" aria-hidden="true">
                  <FileText class="h-5 w-5" />
                </span>
                <div>
                  <h2 class="text-base font-semibold text-text-primary">Application & identity documents</h2>
                  <p class="text-xs text-text-muted">
                    Identity and supporting files for the whole application (not tied to a single qualification card).
                  </p>
                </div>
              </div>
              <div
                v-if="applicationLevelDocuments.length === 0"
                class="mt-5 rounded-2xl border border-dashed border-border bg-surface-muted/40 px-5 py-8 text-center text-sm text-text-muted"
              >
                No application-level documents uploaded.
              </div>
              <div v-else class="mt-5 divide-y divide-border/60 overflow-hidden rounded-2xl border border-border bg-surface shadow-sm">
                <div
                  v-for="doc in applicationLevelDocuments"
                  :key="doc.id"
                  class="flex flex-col gap-3 px-5 py-4 transition hover:bg-surface-muted/40 sm:flex-row sm:items-center sm:justify-between"
                >
                  <div class="min-w-0">
                    <div class="text-[10px] font-semibold uppercase tracking-wider text-text-muted">
                      {{ docTypeLabel(doc.document_type) }}
                    </div>
                    <div class="mt-1 text-sm font-semibold text-text-primary">{{ doc.original_name || '—' }}</div>
                  </div>
                  <div class="flex flex-wrap items-center gap-3">
                    <span class="zaqa-badge zaqa-badge-success text-[10px]">Uploaded</span>
                    <a v-if="doc.preview_url" :href="doc.preview_url" target="_blank" rel="noopener" class="zaqa-link text-xs font-semibold">
                      Preview
                    </a>
                    <a v-if="doc.download_url" :href="doc.download_url" target="_blank" rel="noopener" class="zaqa-link text-xs font-semibold">
                      Download
                    </a>
                  </div>
                </div>
              </div>
            </section>

            <div class="my-10 h-px bg-gradient-to-r from-transparent via-border to-transparent" />

            <!-- Consent -->
            <section>
              <div class="flex items-center gap-3 border-b border-border/60 pb-3">
                <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-emerald-500/15 text-emerald-800" aria-hidden="true">
                  <Shield class="h-5 w-5" />
                </span>
                <div>
                  <h2 class="text-base font-semibold text-text-primary">Institution consent</h2>
                  <p class="text-xs text-text-muted">
                    Foreign awarding institutions require signed institution consent; Zambian institutions do not require this per qualification.
                  </p>
                </div>
              </div>
              <div v-if="qualificationsList.length === 0" class="mt-5 text-sm text-text-muted">—</div>
              <div v-else class="mt-5 space-y-3">
                <div
                  v-for="(q, idx) in qualificationsList"
                  :key="`consent-${q.id ?? idx}`"
                  class="flex flex-col gap-2 rounded-2xl border border-border/80 bg-surface-muted/50 px-5 py-4 sm:flex-row sm:items-center sm:justify-between"
                >
                  <div class="min-w-0 text-sm font-semibold text-text-primary">
                    {{ idx + 1 }}. {{ q.title_of_qualification || 'Qualification' }}
                  </div>
                  <span
                    class="inline-flex w-fit shrink-0 rounded-full border px-3 py-1 text-[11px] font-semibold"
                    :class="
                      qualConsentLabel(q).ok
                        ? 'border-success/30 bg-success/10 text-emerald-800'
                        : qualNeedsForeignConsent(q)
                          ? 'border-warning/30 bg-warning/10 text-warning'
                          : 'border-border bg-surface text-text-muted'
                    "
                  >
                    {{ qualConsentLabel(q).text }}
                  </span>
                </div>
              </div>
            </section>

            <div class="my-10 h-px bg-gradient-to-r from-transparent via-border to-transparent" />

            <!-- Payment -->
            <section>
              <div class="flex items-center gap-3 border-b border-border/60 pb-3">
                <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-violet-500/15 text-violet-900" aria-hidden="true">
                  <CreditCard class="h-5 w-5" />
                </span>
                <div>
                  <h2 class="text-base font-semibold text-text-primary">Payment</h2>
                  <p class="text-xs text-text-muted">Invoice and payment confirmation for this application.</p>
                </div>
              </div>
              <div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="rounded-2xl border border-border/80 bg-surface-muted/50 p-5">
                  <div class="text-[10px] font-semibold uppercase tracking-wider text-text-muted">Invoice number</div>
                  <div class="mt-2 font-mono text-sm font-semibold text-text-primary">
                    {{ application.invoice?.invoice_number ?? '—' }}
                  </div>
                </div>
                <div class="rounded-2xl border border-border/80 bg-surface-muted/50 p-5">
                  <div class="text-[10px] font-semibold uppercase tracking-wider text-text-muted">Amount</div>
                  <div class="mt-2 text-lg font-bold text-text-primary">
                    {{ money(application.invoice?.amount_cents ?? 0, application.invoice?.currency ?? 'ZMW') }}
                  </div>
                </div>
                <div class="rounded-2xl border border-border/80 bg-surface-muted/50 p-5">
                  <div class="text-[10px] font-semibold uppercase tracking-wider text-text-muted">Method</div>
                  <div class="mt-2 text-sm font-semibold capitalize text-text-primary">
                    {{ application.payment?.method?.replace(/_/g, ' ') ?? '—' }}
                  </div>
                </div>
                <div class="rounded-2xl border border-border/80 bg-surface-muted/50 p-5">
                  <div class="text-[10px] font-semibold uppercase tracking-wider text-text-muted">Payment status</div>
                  <div class="mt-2 text-sm font-semibold capitalize text-text-primary">
                    {{ application.payment?.status?.replace(/_/g, ' ') ?? '—' }}
                  </div>
                </div>
              </div>
              <div v-if="application.invoice?.download_url" class="mt-4">
                <a
                  :href="application.invoice.download_url"
                  class="zaqa-btn zaqa-btn-secondary inline-flex items-center gap-2 px-4 py-2 text-sm"
                >
                  Download invoice
                </a>
              </div>
            </section>

            <div class="my-10 h-px bg-gradient-to-r from-transparent via-border to-transparent" />

            <!-- Timeline -->
            <section>
              <div class="flex items-center gap-3 border-b border-border/60 pb-3">
                <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-slate-500/15 text-slate-800" aria-hidden="true">
                  <History class="h-5 w-5" />
                </span>
                <div>
                  <h2 class="text-base font-semibold text-text-primary">Status timeline</h2>
                  <p class="text-xs text-text-muted">Recorded status changes on your application.</p>
                </div>
              </div>
              <div
                v-if="application.status_histories.length === 0"
                class="mt-5 rounded-2xl border border-dashed border-border bg-surface-muted/40 px-5 py-8 text-center text-sm text-text-muted"
              >
                No status changes recorded yet.
              </div>
              <div v-else class="relative mt-6 space-y-0 pl-2">
                <div
                  class="absolute bottom-2 left-[11px] top-2 w-px bg-gradient-to-b from-brand/40 via-border to-transparent"
                  aria-hidden="true"
                />
                <div
                  v-for="h in application.status_histories"
                  :key="h.id"
                  class="relative pb-8 pl-10 last:pb-0"
                >
                  <span
                    class="absolute left-0 top-1.5 flex h-[22px] w-[22px] items-center justify-center rounded-full border-2 border-surface bg-brand shadow-sm ring-2 ring-brand/20"
                  >
                    <CheckCircle2 class="h-3 w-3 text-text-on-dark" aria-hidden="true" />
                  </span>
                  <div class="rounded-2xl border border-border/80 bg-surface-muted/60 px-4 py-3">
                    <div class="text-sm font-semibold text-text-primary">
                      {{ h.from_status ?? '—' }}
                      <span class="mx-1 text-text-muted">→</span>
                      {{ h.to_status }}
                    </div>
                    <div v-if="h.comment" class="mt-2 text-sm text-text-muted">{{ h.comment }}</div>
                    <div class="mt-2 text-[11px] font-medium text-text-muted">
                      {{ formatDisplayDate(h.changed_at) }}
                    </div>
                  </div>
                </div>
              </div>
            </section>
          </div>
        </div>
      </div>
    </div>
  </ApplicantLayout>
</template>
