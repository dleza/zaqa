<script setup lang="ts">
import GuestLayout from '@/Layouts/GuestLayout.vue'
import { zaqaLogoUrl } from '@/constants/zaqaLogo'
import { Head } from '@inertiajs/vue3'
import {
  AlertTriangle,
  Ban,
  BadgeCheck,
  CheckCircle2,
  FileCheck2,
  LockKeyhole,
  ShieldCheck,
} from 'lucide-vue-next'
import { computed } from 'vue'

const coatOfArmsUrl = new URL('../../../images/certificates/coat_of_arms_watermark.png', import.meta.url).href

type SubjectResult = {
  index: number
  subject_name: string
  grade: string
}

type CertificateVerificationPayload = {
  found: boolean
  status: string
  certificate_type?: string
  status_label: string
  message: string
  verified_at?: string | null
  verification_reference?: string | null
  revoked_at?: string | null
  revocation_public_note?: string | null
  has_newer_active_certificate?: boolean
  certificate: null | {
    certificate_number: string | null
    zaqa_reference_number: string | null
    issued_at: string | null
    award_date?: string | null
    holder_name: string | null
    holder_identifier?: string | null
    qualification_title: string | null
    awarding_institution: string | null
    qualification_type_label?: string | null
    qualification_level_label?: string | null
    qualification_type_code?: string | null
    template_key?: string | null
    subject_count?: number
    subject_results?: SubjectResult[]
    replacement_certificate_number: string | null
  }
}

const props = defineProps<{
  verification: CertificateVerificationPayload
}>()

const statusTheme = computed(() => {
  const status = props.verification.status
  const isRejection = props.verification.certificate_type === 'rejection'

  if (status === 'issued' && isRejection) {
    return {
      heroTitle: 'Rejection Notice Verified',
      heroText: 'This QR code confirms that ZAQA issued a rejection notice for the qualification shown below.',
      panelClass: 'border-rose-200/70 bg-gradient-to-br from-rose-50 via-white to-orange-50/60',
      iconWrapClass: 'bg-rose-700 text-white shadow-[0_18px_40px_-20px_rgba(190,18,60,0.75)]',
      badgeClass: 'bg-rose-700 text-white ring-1 ring-rose-200',
      securityClass: 'border-rose-200/70 bg-rose-50/90',
      icon: FileCheck2,
      registryOutcome: 'QR code recognized. This record confirms an issued rejection notice.',
    }
  }

  if (status === 'issued') {
    return {
      heroTitle: 'Certificate Verified',
      heroText: 'This certificate was issued and verified by ZAQA.',
      panelClass: 'border-emerald-300/50 bg-gradient-to-br from-emerald-50 via-white to-emerald-100/70',
      iconWrapClass: 'bg-emerald-600 text-white shadow-[0_18px_40px_-20px_rgba(5,150,105,0.7)]',
      badgeClass: 'bg-emerald-600 text-white ring-1 ring-emerald-200',
      securityClass: 'border-emerald-200/70 bg-emerald-50/80',
      icon: ShieldCheck,
      registryOutcome: 'QR validation successful',
    }
  }

  if (status === 'reissued') {
    return {
      heroTitle: 'Certificate Superseded',
      heroText: 'This certificate record exists, but a newer replacement certificate has been issued.',
      panelClass: 'border-amber-300/60 bg-gradient-to-br from-amber-50 via-white to-orange-50',
      iconWrapClass: 'bg-amber-500 text-white shadow-[0_18px_40px_-20px_rgba(245,158,11,0.8)]',
      badgeClass: 'bg-amber-500 text-white ring-1 ring-amber-200',
      securityClass: 'border-amber-200/70 bg-amber-50/90',
      icon: AlertTriangle,
      registryOutcome: 'QR code recognized, but this certificate has been superseded.',
    }
  }

  if (status === 'revoked') {
    return {
      heroTitle: isRejection ? 'Rejection Certificate Recalled' : 'Certificate Recalled',
      heroText: isRejection
        ? 'This rejection certificate has been recalled by the Zambia Qualifications Authority and is no longer valid.'
        : 'This certificate has been recalled by the Zambia Qualifications Authority and is no longer valid.',
      panelClass: 'border-rose-300/60 bg-gradient-to-br from-rose-50 via-white to-red-50',
      iconWrapClass: 'bg-rose-600 text-white shadow-[0_18px_40px_-20px_rgba(225,29,72,0.8)]',
      badgeClass: 'bg-rose-600 text-white ring-1 ring-rose-200',
      securityClass: 'border-rose-200/70 bg-rose-50/90',
      icon: Ban,
      registryOutcome: 'QR code recognized, but this certificate is no longer valid.',
    }
  }

  return {
    heroTitle: 'Verification Not Found',
    heroText: 'The scanned code does not match an active certificate record in the ZAQA registry.',
    panelClass: 'border-slate-300/60 bg-gradient-to-br from-slate-50 via-white to-slate-100/80',
    iconWrapClass: 'bg-slate-700 text-white shadow-[0_18px_40px_-20px_rgba(51,65,85,0.75)]',
    badgeClass: 'bg-slate-700 text-white ring-1 ring-slate-200',
    securityClass: 'border-slate-200/80 bg-slate-50/80',
    icon: FileCheck2,
    registryOutcome: 'No matching active certificate record.',
  }
})

const registryOutcomeLabel = computed(() => statusTheme.value.registryOutcome ?? 'QR validation successful')

const hasSubjectResults = computed(() => (props.verification.certificate?.subject_results?.length ?? 0) > 0)

const subjectSectionTitle = computed(() => {
  if (props.verification.certificate?.template_key === 'school_subjects') {
    return 'Validated subject results'
  }

  return 'Subject results'
})

function formatDate(iso: string | null | undefined, includeTime = false) {
  if (!iso) return '—'

  try {
    return new Date(iso).toLocaleString(undefined, {
      dateStyle: 'medium',
      ...(includeTime ? { timeStyle: 'short' as const } : {}),
    })
  } catch {
    return iso
  }
}

</script>

<template>
  <Head title="Official certificate verification" />

  <GuestLayout
    :card="false"
    max-width-class="max-w-6xl"
    content-padding-class="px-4 py-6 sm:px-6 sm:py-8 lg:px-8 lg:py-10"
    header-compact
    :hide-header="true"
    :center-content="false"
  >
    <div class="relative overflow-hidden rounded-[2rem] border border-brand/15 bg-white shadow-[0_35px_120px_-55px_rgba(11,58,102,0.45)]">
      <div class="pointer-events-none absolute inset-0 overflow-hidden">
        <div class="absolute inset-x-0 top-0 h-48 bg-gradient-to-r from-brand via-brand/90 to-accent/70" />
        <div
          class="absolute left-1/2 top-[8%] h-[28rem] w-[28rem] -translate-x-1/2 opacity-[0.07] bg-contain bg-center bg-no-repeat"
          :style="{ backgroundImage: `url(${coatOfArmsUrl})` }"
        />
        <div class="absolute -left-16 top-24 h-40 w-40 rounded-full bg-white/10 blur-3xl" />
        <div class="absolute -right-20 top-8 h-48 w-48 rounded-full bg-amber-200/20 blur-3xl" />
        <div class="absolute inset-0 bg-[radial-gradient(circle_at_top,_rgba(255,255,255,0.18),_transparent_45%)]" />
      </div>

      <div class="relative">
        <section class="border-b border-white/15 px-5 py-6 text-white sm:px-8 lg:px-10">
          <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
            <div class="flex items-start gap-4">
              <div class="rounded-2xl bg-white/95 p-3 shadow-lg shadow-slate-950/10">
                <img :src="zaqaLogoUrl" alt="ZAQA logo" class="h-12 w-auto object-contain sm:h-14" />
              </div>
              <div class="min-w-0">
                <div class="text-[11px] font-semibold uppercase tracking-[0.3em] text-white/75">Republic of Zambia</div>
                <h1 class="mt-2 text-xl font-semibold tracking-tight sm:text-2xl">Zambia Qualifications Authority</h1>
                <p class="mt-1 text-sm text-white/85 sm:text-base">Official Certificate Verification</p>
              </div>
            </div>

            <div class="flex flex-wrap items-center gap-2 print:hidden">
              <span class="inline-flex items-center gap-2 rounded-full bg-white/15 px-3 py-1.5 text-xs font-semibold text-white ring-1 ring-white/20 backdrop-blur">
                <BadgeCheck class="h-4 w-4" aria-hidden="true" />
                Official ZAQA registry
              </span>
            </div>
          </div>
        </section>

        <div class="space-y-6 px-5 py-6 sm:px-8 sm:py-8 lg:px-10 lg:py-10">
          <section
            class="relative overflow-hidden rounded-[1.75rem] border p-6 shadow-[0_25px_70px_-45px_rgba(15,23,42,0.45)] sm:p-7"
            :class="statusTheme.panelClass"
          >
            <div class="absolute inset-y-0 right-0 hidden w-44 bg-gradient-to-l from-white/60 to-transparent sm:block" />
            <div class="relative flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
              <div class="flex items-start gap-4 sm:gap-5">
                <span
                  class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl text-white sm:h-16 sm:w-16"
                  :class="statusTheme.iconWrapClass"
                >
                  <component :is="statusTheme.icon" class="h-7 w-7 sm:h-8 sm:w-8" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                  <div class="text-xs font-semibold uppercase tracking-[0.2em] text-text-muted">Official verification result</div>
                  <h2 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary sm:text-3xl">
                    {{ statusTheme.heroTitle }}
                  </h2>
                  <p class="mt-2 max-w-2xl text-sm leading-6 text-text-secondary sm:text-base">
                    {{ statusTheme.heroText }}
                  </p>
                  <p class="mt-2 text-sm text-text-muted">{{ verification.message }}</p>
                </div>
              </div>

              <div class="grid gap-3 sm:min-w-[250px]">
                <div class="inline-flex w-fit items-center gap-2 rounded-full px-3 py-1.5 text-xs font-semibold uppercase tracking-[0.18em]" :class="statusTheme.badgeClass">
                  <CheckCircle2 class="h-4 w-4" aria-hidden="true" />
                  {{ verification.status_label }}
                </div>
                <div class="space-y-2 rounded-2xl border border-white/60 bg-white/80 p-4 backdrop-blur">
                  <div>
                    <div class="text-[11px] font-semibold uppercase tracking-[0.16em] text-text-muted">Verified on</div>
                    <div class="mt-1 text-sm font-semibold text-text-primary">
                      {{ formatDate(verification.verified_at, true) }}
                    </div>
                  </div>
                  <div>
                    <div class="text-[11px] font-semibold uppercase tracking-[0.16em] text-text-muted">Verification reference</div>
                    <div class="mt-1 break-all font-mono text-xs text-text-primary">
                      {{ verification.verification_reference || '—' }}
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </section>

          <section
            v-if="verification.certificate"
            class="overflow-hidden rounded-[1.75rem] border border-border/80 bg-white shadow-[0_30px_90px_-55px_rgba(15,23,42,0.45)]"
          >
            <div class="border-b border-border/70 bg-gradient-to-r from-slate-50 to-white px-5 py-5 sm:px-8 sm:py-6">
              <div class="grid gap-5 lg:grid-cols-[minmax(0,1.5fr)_minmax(260px,0.8fr)] lg:items-end">
                <div class="space-y-3">
                  <div class="text-xs font-semibold uppercase tracking-[0.2em] text-text-muted">Certificate identity</div>
                  <div>
                    <div class="text-sm text-text-muted">Qualification holder</div>
                    <div class="mt-1 text-2xl font-semibold tracking-tight text-text-primary sm:text-3xl">
                      {{ verification.certificate.holder_name || '—' }}
                    </div>
                  </div>
                  <div>
                    <div class="text-sm text-text-muted">Qualification</div>
                    <div class="mt-1 text-lg font-semibold leading-snug text-brand sm:text-xl">
                      {{ verification.certificate.qualification_title || '—' }}
                    </div>
                  </div>
                </div>

                <div class="grid gap-3 rounded-[1.5rem] border border-brand/10 bg-brand/[0.03] p-4 sm:grid-cols-2 lg:grid-cols-1">
                  <div>
                    <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-text-muted">Certificate number</div>
                    <div class="mt-1 font-mono text-sm font-semibold text-text-primary">
                      {{ verification.certificate.certificate_number || '—' }}
                    </div>
                  </div>
                  <div>
                    <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-text-muted">ZAQA reference</div>
                    <div class="mt-1 font-mono text-sm font-semibold text-text-primary">
                      {{ verification.certificate.zaqa_reference_number || '—' }}
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <div class="grid gap-8 px-5 py-6 sm:px-8 sm:py-8 lg:grid-cols-[minmax(0,1.5fr)_minmax(280px,0.9fr)]">
              <div class="space-y-8">
                <section class="space-y-4">
                  <div class="flex items-center gap-3">
                    <div class="h-9 w-1.5 rounded-full bg-brand" />
                    <div>
                      <h3 class="text-lg font-semibold text-text-primary">Qualification details</h3>
                      <p class="text-sm text-text-muted">Official certificate information from the ZAQA registry.</p>
                    </div>
                  </div>

                  <dl class="grid gap-4 sm:grid-cols-2">
                    <div class="rounded-2xl bg-surface-muted/45 p-4">
                      <dt class="text-[11px] font-semibold uppercase tracking-[0.16em] text-text-muted">Awarding institution</dt>
                      <dd class="mt-1.5 text-sm font-semibold leading-6 text-text-primary">
                        {{ verification.certificate.awarding_institution || '—' }}
                      </dd>
                    </div>
                    <div class="rounded-2xl bg-surface-muted/45 p-4">
                      <dt class="text-[11px] font-semibold uppercase tracking-[0.16em] text-text-muted">Validated by ZAQA</dt>
                      <dd class="mt-1.5 text-sm font-semibold text-text-primary">
                        {{ formatDate(verification.certificate.issued_at) }}
                      </dd>
                    </div>
                    <div
                      v-if="verification.status === 'revoked' && verification.revoked_at"
                      class="rounded-2xl bg-rose-50/80 p-4 ring-1 ring-rose-200/70"
                    >
                      <dt class="text-[11px] font-semibold uppercase tracking-[0.16em] text-rose-800">Recalled on</dt>
                      <dd class="mt-1.5 text-sm font-semibold text-rose-950">
                        {{ formatDate(verification.revoked_at, true) }}
                      </dd>
                    </div>
                    <div class="rounded-2xl bg-surface-muted/45 p-4">
                      <dt class="text-[11px] font-semibold uppercase tracking-[0.16em] text-text-muted">Award date</dt>
                      <dd class="mt-1.5 text-sm font-semibold text-text-primary">
                        {{ formatDate(verification.certificate.award_date) }}
                      </dd>
                    </div>
                    <div class="rounded-2xl bg-surface-muted/45 p-4">
                      <dt class="text-[11px] font-semibold uppercase tracking-[0.16em] text-text-muted">Qualification level</dt>
                      <dd class="mt-1.5 text-sm font-semibold text-text-primary">
                        {{
                          verification.certificate.qualification_level_label ||
                          verification.certificate.qualification_type_label ||
                          verification.certificate.qualification_type_code ||
                          '—'
                        }}
                      </dd>
                    </div>
                    <div class="rounded-2xl bg-surface-muted/45 p-4 sm:col-span-2">
                      <dt class="text-[11px] font-semibold uppercase tracking-[0.16em] text-text-muted">Certificate type</dt>
                      <dd class="mt-1.5 text-sm font-semibold text-text-primary">
                        {{
                          verification.certificate_type === 'rejection'
                            ? 'Rejection notice'
                            : verification.certificate?.template_key === 'school_subjects'
                              ? 'School certificate with subject results'
                              : 'Standard ZAQA verification certificate'
                        }}
                      </dd>
                    </div>
                  </dl>
                </section>

                <section v-if="hasSubjectResults" class="space-y-4">
                  <div class="flex items-center gap-3">
                    <div class="h-9 w-1.5 rounded-full bg-emerald-600" />
                    <div>
                      <h3 class="text-lg font-semibold text-text-primary">{{ subjectSectionTitle }}</h3>
                      <p class="text-sm text-text-muted">
                        {{ verification.certificate?.subject_count || 0 }} validated subject result{{
                          (verification.certificate?.subject_count || 0) === 1 ? '' : 's'
                        }} recorded for this certificate.
                      </p>
                    </div>
                  </div>

                  <div class="overflow-hidden rounded-[1.5rem] border border-border/80 bg-white">
                    <div class="overflow-x-auto">
                      <table class="min-w-full divide-y divide-border/70 text-sm">
                        <thead class="bg-surface-muted/70">
                          <tr class="text-left text-[11px] font-semibold uppercase tracking-[0.16em] text-text-muted">
                            <th class="px-4 py-3">#</th>
                            <th class="px-4 py-3">Subject</th>
                            <th class="px-4 py-3">Grade</th>
                          </tr>
                        </thead>
                        <tbody class="divide-y divide-border/60">
                          <tr v-for="row in verification.certificate?.subject_results || []" :key="`${row.index}-${row.subject_name}`">
                            <td class="px-4 py-3 font-semibold text-text-primary">{{ row.index }}</td>
                            <td class="px-4 py-3 text-text-primary">{{ row.subject_name }}</td>
                            <td class="px-4 py-3">
                              <span class="inline-flex min-w-[3rem] justify-center rounded-full bg-brand/10 px-3 py-1 text-xs font-semibold text-brand">
                                {{ row.grade }}
                              </span>
                            </td>
                          </tr>
                        </tbody>
                      </table>
                    </div>
                  </div>
                </section>
              </div>

              <aside class="space-y-5">
                <section class="rounded-[1.5rem] border border-border/80 bg-surface-muted/40 p-5">
                  <div class="flex items-center gap-3">
                    <span class="flex h-11 w-11 items-center justify-center rounded-2xl bg-brand/10 text-brand">
                      <ShieldCheck class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div>
                      <h3 class="text-base font-semibold text-text-primary">Digital security</h3>
                      <p class="text-sm text-text-muted">Matched against the official ZAQA certificate registry.</p>
                    </div>
                  </div>

                  <div class="mt-4 space-y-3">
                    <div class="rounded-2xl border border-border/70 bg-white p-4">
                      <div class="text-[11px] font-semibold uppercase tracking-[0.16em] text-text-muted">Registry outcome</div>
                      <div class="mt-1.5 text-sm font-semibold text-text-primary">{{ registryOutcomeLabel }}</div>
                    </div>
                    <div class="rounded-2xl border border-border/70 bg-white p-4">
                      <div class="text-[11px] font-semibold uppercase tracking-[0.16em] text-text-muted">Official source</div>
                      <div class="mt-1.5 text-sm font-semibold text-text-primary">Zambia Qualifications Authority</div>
                    </div>
                  </div>
                </section>

                <section class="rounded-[1.5rem] border p-5" :class="statusTheme.securityClass">
                  <div class="flex items-start gap-3">
                    <span class="mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-white text-brand shadow-sm">
                      <LockKeyhole class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div>
                      <h3 class="text-base font-semibold text-text-primary">Verification notice</h3>
                      <p class="mt-1 text-sm leading-6 text-text-secondary">
                        This page confirms whether the scanned certificate matches the official ZAQA registry record.
                      </p>
                    </div>
                  </div>
                </section>

                <section
                  v-if="verification.status === 'revoked'"
                  class="rounded-[1.5rem] border border-rose-300/50 bg-rose-50/90 p-5"
                >
                  <div class="text-[11px] font-semibold uppercase tracking-[0.16em] text-rose-900">Revocation notice</div>
                  <p class="mt-2 text-sm leading-6 text-rose-950">
                    {{
                      verification.revocation_public_note ||
                      'This certificate has been recalled by the Zambia Qualifications Authority and is no longer valid.'
                    }}
                  </p>
                  <p v-if="verification.has_newer_active_certificate" class="mt-3 text-sm text-rose-900/90">
                    A newer certificate may have been issued. Please contact ZAQA for confirmation.
                  </p>
                </section>

                <section
                  v-if="verification.certificate?.replacement_certificate_number"
                  class="rounded-[1.5rem] border border-amber-300/50 bg-amber-50/90 p-5"
                >
                  <div class="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-900">Replacement certificate</div>
                  <div class="mt-2 font-mono text-sm font-semibold text-amber-950">
                    {{ verification.certificate.replacement_certificate_number }}
                  </div>
                  <p class="mt-2 text-sm text-amber-900/90">
                    This certificate was replaced by the newer certificate shown above.
                  </p>
                </section>
              </aside>
            </div>
          </section>

          <section
            v-else
            class="rounded-[1.75rem] border border-rose-300/50 bg-gradient-to-br from-rose-50 via-white to-red-50 p-6 shadow-[0_25px_70px_-45px_rgba(225,29,72,0.45)] sm:p-8"
          >
            <div class="flex items-start gap-4">
              <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-rose-600 text-white">
                <Ban class="h-7 w-7" aria-hidden="true" />
              </span>
              <div>
                <h3 class="text-xl font-semibold text-rose-950">No matching certificate record</h3>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-rose-900/90">
                  The scanned code does not match a certificate currently available in the ZAQA verification registry.
                  Check the QR code and try again if necessary.
                </p>
              </div>
            </div>
          </section>

          <footer class="border-t border-border/70 pt-4 text-center text-xs text-text-muted sm:pt-6">
            <div class="font-semibold text-text-secondary">Powered by Zambia Qualifications Authority</div>
            <div class="mt-1">Official digital certificate verification portal</div>
            <div class="mt-1">© {{ new Date().getFullYear() }} ZAQA. All rights reserved.</div>
          </footer>
        </div>
      </div>
    </div>
  </GuestLayout>
</template>
