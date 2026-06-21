<script setup lang="ts">
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import ApplicantLayout from '@/Layouts/ApplicantLayout.vue'
import {
  BadgeCheck,
  Building2,
  CheckCircle2,
  Clock,
  FileCheck,
  KeyRound,
  Mail,
  MapPin,
  Pencil,
  Shield,
  User,
  Users,
} from 'lucide-vue-next'

const props = defineProps<{
  profile: any
}>()

const isIndividual = computed(() => (props.profile?.applicant_type ?? '') === 'individual')
const isInstitution = computed(() => (props.profile?.applicant_type ?? '') === 'institution')

const accountKindLabel = computed(() => {
  if (isInstitution.value) return 'Organization'
  if (isIndividual.value) return 'Individual applicant'
  return 'Applicant'
})

const accountKindDescription = computed(() => {
  if (isInstitution.value) {
    return 'Your organization’s registered profile for submitting verification requests on behalf of students or staff.'
  }
  return 'Your personal profile for qualification verification.'
})

function labelOrDash(v: any) {
  const s = (v ?? '').toString().trim()
  return s.length ? s : '—'
}

function formatWhen(iso: string | null | undefined): string {
  if (!iso) return ''
  try {
    const d = new Date(iso)
    return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(d)
  } catch {
    return String(iso)
  }
}

const emailVerified = computed(() => !!props.profile?.email_verified_at)
const phoneVerified = computed(() => !!props.profile?.phone_verified_at)

const displayHeadlineName = computed(() => {
  if (isInstitution.value && props.profile?.institution_profile?.institution_name) {
    return String(props.profile.institution_profile.institution_name)
  }
  return labelOrDash(props.profile?.name)
})

const identityDocOnFile = computed(() => !!props.profile?.applicant_profile?.identity_document_uploaded_at)
</script>

<template>
  <ApplicantLayout>
    <div class="relative min-h-[50vh]">
      <!-- Ambient background -->
      <div class="pointer-events-none absolute inset-0 -z-10 overflow-hidden" aria-hidden="true">
        <div class="absolute -left-16 top-0 h-64 w-64 rounded-full bg-brand/10 blur-3xl" />
        <div class="absolute right-0 top-24 h-72 w-72 rounded-full bg-accent/10 blur-3xl" />
        <div
          class="absolute bottom-0 left-1/2 h-px w-[120%] -translate-x-1/2 bg-gradient-to-r from-transparent via-border/70 to-transparent"
        />
      </div>

      <div class="zaqa-wizard-shell">
        <!-- Intro row -->
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
          <div>
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-text-muted">Account</p>
            <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary sm:text-3xl">Your profile</h1>
            <p class="mt-2 max-w-2xl text-sm leading-relaxed text-text-muted">
              {{ accountKindDescription }}
            </p>
          </div>
          <div class="flex flex-wrap gap-2 lg:shrink-0 lg:justify-end">
            <Link
              href="/applicant/profile/edit"
              class="zaqa-btn zaqa-btn-primary inline-flex items-center gap-2 px-5 py-2.5 text-sm font-semibold shadow-md shadow-brand/20"
            >
              <Pencil class="h-4 w-4 opacity-90" aria-hidden="true" />
              Update profile
            </Link>
            <Link
              href="/applicant/change-password"
              class="zaqa-btn zaqa-btn-secondary inline-flex items-center gap-2 px-4 py-2.5 text-sm font-semibold"
            >
              <KeyRound class="h-4 w-4 opacity-80" aria-hidden="true" />
              Change password
            </Link>
          </div>
        </div>

        <!-- Hero identity card -->
        <div
          class="mt-8 overflow-hidden rounded-3xl border border-border/80 bg-surface shadow-[0_24px_60px_-16px_rgba(0,115,186,0.14)] ring-1 ring-black/[0.04]"
        >
          <div
            class="zaqa-brand-hero relative border-b border-white/10 px-6 py-8 text-text-on-dark sm:px-10 sm:py-10"
          >
            <div
              class="pointer-events-none absolute inset-0 opacity-[0.1]"
              style="
                background-image: radial-gradient(circle at 20% 20%, white 0.5px, transparent 0.6px);
                background-size: 24px 24px;
              "
            />
            <div class="relative flex flex-col gap-8 lg:flex-row lg:items-center lg:justify-between">
              <div class="flex min-w-0 flex-1 items-start gap-5">
                <div
                  class="flex h-16 w-16 shrink-0 items-center justify-center rounded-2xl border border-white/20 bg-white/10 shadow-inner backdrop-blur-sm sm:h-[72px] sm:w-[72px]"
                  aria-hidden="true"
                >
                  <Building2 v-if="isInstitution" class="h-9 w-9 text-white" />
                  <User v-else class="h-9 w-9 text-white" />
                </div>
                <div class="min-w-0">
                  <div class="flex flex-wrap items-center gap-2">
                    <span
                      class="inline-flex items-center gap-1.5 rounded-full border border-white/25 bg-white/10 px-3 py-1 text-[11px] font-bold uppercase tracking-wider text-white/95"
                    >
                      <Users v-if="isInstitution" class="h-3.5 w-3.5 opacity-90" />
                      <User v-else class="h-3.5 w-3.5 opacity-90" />
                      {{ accountKindLabel }}
                    </span>
                    <span
                      class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-[11px] font-semibold"
                      :class="
                        profile?.is_active
                          ? 'border-emerald-400/40 bg-emerald-500/20 text-emerald-50'
                          : 'border-amber-400/35 bg-amber-500/15 text-amber-50'
                      "
                    >
                      {{ profile?.is_active ? 'Active' : 'Pending activation' }}
                    </span>
                  </div>
                  <h2 class="mt-3 break-words font-mono text-xl font-bold leading-tight tracking-tight text-white sm:text-2xl">
                    {{ displayHeadlineName }}
                  </h2>
                  <p v-if="isInstitution && profile?.name" class="mt-1 text-sm text-white/75">
                    Portal login name: <span class="font-medium text-white">{{ profile.name }}</span>
                  </p>
                  <p v-if="isIndividual && profile?.name" class="mt-1 text-sm text-white/75">
                    Account name: <span class="font-medium text-white">{{ profile.name }}</span>
                  </p>
                </div>
              </div>
              <dl class="grid shrink-0 grid-cols-1 gap-3 sm:grid-cols-2 lg:text-right">
                <div class="rounded-xl border border-white/15 bg-white/10 px-4 py-3 backdrop-blur-sm">
                  <dt class="flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-wider text-white/65 lg:justify-end">
                    <Shield class="h-3.5 w-3.5 opacity-80" aria-hidden="true" />
                    Applicant type
                  </dt>
                  <dd class="mt-1 text-sm font-semibold capitalize text-white">
                    {{ profile?.applicant_type?.replace('_', ' ') || '—' }}
                  </dd>
                </div>
                <div class="rounded-xl border border-white/15 bg-white/10 px-4 py-3 backdrop-blur-sm">
                  <dt class="flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-wider text-white/65 lg:justify-end">
                    <BadgeCheck class="h-3.5 w-3.5 opacity-80" aria-hidden="true" />
                    Record status
                  </dt>
                  <dd class="mt-1 text-sm font-semibold text-white">
                    {{ profile?.is_active ? 'Eligible to use services' : 'Complete activation to proceed' }}
                  </dd>
                </div>
              </dl>
            </div>
          </div>

          <div class="divide-y divide-border/70 bg-surface px-6 py-8 sm:px-10">
            <!-- Contact -->
            <section class="pb-8">
              <div class="flex items-center gap-3 border-b border-border/60 pb-4">
                <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand/10 text-brand">
                  <Mail class="h-5 w-5" aria-hidden="true" />
                </span>
                <div>
                  <h3 class="text-base font-semibold text-text-primary">Contact</h3>
                  <p class="text-xs text-text-muted">How ZAQA reaches you about applications and verification.</p>
                </div>
              </div>
              <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div class="rounded-2xl border border-border/80 bg-surface-muted/50 p-4">
                  <div class="flex items-start justify-between gap-2">
                    <span class="text-[10px] font-semibold uppercase tracking-wider text-text-muted">Email</span>
                    <span
                      v-if="emailVerified"
                      class="inline-flex items-center gap-0.5 rounded-full bg-emerald-500/10 px-2 py-0.5 text-[10px] font-semibold text-emerald-800"
                    >
                      <CheckCircle2 class="h-3 w-3" aria-hidden="true" />
                      Verified
                    </span>
                    <span
                      v-else
                      class="inline-flex items-center gap-0.5 rounded-full bg-amber-500/10 px-2 py-0.5 text-[10px] font-semibold text-amber-900"
                    >
                      <Clock class="h-3 w-3" aria-hidden="true" />
                      Unverified
                    </span>
                  </div>
                  <p class="mt-2 break-all text-sm font-semibold text-text-primary">{{ labelOrDash(profile?.email) }}</p>
                </div>
                <div class="rounded-2xl border border-border/80 bg-surface-muted/50 p-4">
                  <div class="flex items-start justify-between gap-2">
                    <span class="text-[10px] font-semibold uppercase tracking-wider text-text-muted">Primary phone</span>
                    <span
                      v-if="phoneVerified"
                      class="inline-flex items-center gap-0.5 rounded-full bg-emerald-500/10 px-2 py-0.5 text-[10px] font-semibold text-emerald-800"
                    >
                      <CheckCircle2 class="h-3 w-3" aria-hidden="true" />
                      Verified
                    </span>
                    <span
                      v-else-if="labelOrDash(profile?.phone_primary) !== '—'"
                      class="inline-flex items-center gap-0.5 rounded-full bg-surface-muted px-2 py-0.5 text-[10px] font-semibold text-text-muted"
                    >
                      Not verified
                    </span>
                  </div>
                  <p class="mt-2 text-sm font-semibold text-text-primary">{{ labelOrDash(profile?.phone_primary) }}</p>
                </div>
                <div class="rounded-2xl border border-border/80 bg-surface-muted/50 p-4">
                  <div class="text-[10px] font-semibold uppercase tracking-wider text-text-muted">Secondary phone</div>
                  <p class="mt-2 text-sm font-semibold text-text-primary">{{ labelOrDash(profile?.phone_secondary) }}</p>
                </div>
              </div>
            </section>

            <!-- Individual: identity -->
            <section v-if="isIndividual" class="pb-8 pt-2">
              <div class="flex items-center gap-3 border-b border-border/60 pb-4">
                <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-accent/15 text-accent-deep">
                  <User class="h-5 w-5" aria-hidden="true" />
                </span>
                <div>
                  <h3 class="text-base font-semibold text-text-primary">Personal identity</h3>
                  <p class="text-xs text-text-muted">Legal name and official ID as used on verification applications.</p>
                </div>
              </div>
              <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div class="rounded-2xl border border-border/80 bg-surface-muted/40 p-4">
                  <div class="text-[10px] font-semibold uppercase tracking-wider text-text-muted">First name</div>
                  <div class="mt-1.5 text-sm font-semibold text-text-primary">
                    {{ labelOrDash(profile?.applicant_profile?.first_name) }}
                  </div>
                </div>
                <div class="rounded-2xl border border-border/80 bg-surface-muted/40 p-4">
                  <div class="text-[10px] font-semibold uppercase tracking-wider text-text-muted">Middle name</div>
                  <div class="mt-1.5 text-sm font-semibold text-text-primary">
                    {{ labelOrDash(profile?.applicant_profile?.middle_name) }}
                  </div>
                </div>
                <div class="rounded-2xl border border-border/80 bg-surface-muted/40 p-4 sm:col-span-2 lg:col-span-1">
                  <div class="text-[10px] font-semibold uppercase tracking-wider text-text-muted">Surname</div>
                  <div class="mt-1.5 text-sm font-semibold text-text-primary">
                    {{ labelOrDash(profile?.applicant_profile?.surname) }}
                  </div>
                </div>
                <div class="rounded-2xl border border-border/80 bg-surface-muted/40 p-4">
                  <div class="text-[10px] font-semibold uppercase tracking-wider text-text-muted">NRC number</div>
                  <div class="mt-1.5 font-mono text-sm font-semibold text-text-primary">
                    {{ labelOrDash(profile?.applicant_profile?.nrc_number) }}
                  </div>
                </div>
                <div class="rounded-2xl border border-border/80 bg-surface-muted/40 p-4">
                  <div class="text-[10px] font-semibold uppercase tracking-wider text-text-muted">Passport number</div>
                  <div class="mt-1.5 font-mono text-sm font-semibold text-text-primary">
                    {{ labelOrDash(profile?.applicant_profile?.passport_number) }}
                  </div>
                </div>
              </div>

              <div
                class="mt-6 flex flex-col gap-4 rounded-2xl border border-brand/20 bg-gradient-to-br from-brand/[0.04] to-transparent p-5 sm:flex-row sm:items-center sm:justify-between"
              >
                <div class="flex items-start gap-3">
                  <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-brand/10 text-brand">
                    <FileCheck class="h-5 w-5" aria-hidden="true" />
                  </span>
                  <div>
                    <div class="text-sm font-semibold text-text-primary">Identity document on file</div>
                    <p class="mt-1 text-xs leading-relaxed text-text-muted">
                      A clear copy of NRC or passport helps speed up verification when you apply for yourself.
                    </p>
                  </div>
                </div>
                <div class="shrink-0 text-left sm:text-right">
                  <template v-if="identityDocOnFile">
                    <span class="inline-flex items-center gap-1 rounded-full bg-emerald-500/10 px-2.5 py-1 text-[11px] font-semibold text-emerald-800">
                      <CheckCircle2 class="h-3.5 w-3.5" aria-hidden="true" />
                      Uploaded
                    </span>
                    <div v-if="profile?.applicant_profile?.identity_document_original_name" class="mt-2 max-w-xs truncate text-xs text-text-muted sm:ml-auto">
                      {{ profile.applicant_profile.identity_document_original_name }}
                    </div>
                    <div v-if="profile?.applicant_profile?.identity_document_uploaded_at" class="mt-1 text-[11px] text-text-muted">
                      {{ formatWhen(profile.applicant_profile.identity_document_uploaded_at) }}
                    </div>
                  </template>
                  <template v-else>
                    <span class="inline-flex items-center gap-1 rounded-full bg-surface-muted px-2.5 py-1 text-[11px] font-semibold text-text-muted">
                      No document uploaded
                    </span>
                    <Link href="/applicant/profile/edit" class="mt-2 block text-xs font-semibold text-brand hover:underline">
                      Upload in profile editor →
                    </Link>
                  </template>
                </div>
              </div>
            </section>

            <!-- Institution -->
            <section v-if="isInstitution" class="pb-8 pt-2">
              <div class="flex items-center gap-3 border-b border-border/60 pb-4">
                <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-violet-500/15 text-violet-900">
                  <Building2 class="h-5 w-5" aria-hidden="true" />
                </span>
                <div>
                  <h3 class="text-base font-semibold text-text-primary">Organization details</h3>
                  <p class="text-xs text-text-muted">
                    Registered institution information used when your organization submits verification requests.
                  </p>
                </div>
              </div>
              <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
                <div class="rounded-2xl border border-border/80 bg-surface-muted/40 p-5 lg:col-span-2">
                  <div class="text-[10px] font-semibold uppercase tracking-wider text-text-muted">Institution name</div>
                  <div class="mt-2 text-lg font-semibold leading-snug text-text-primary">
                    {{ labelOrDash(profile?.institution_profile?.institution_name) }}
                  </div>
                </div>
                <div class="rounded-2xl border border-border/80 bg-surface-muted/40 p-4">
                  <div class="text-[10px] font-semibold uppercase tracking-wider text-text-muted">TPIN</div>
                  <div class="mt-1.5 font-mono text-sm font-semibold text-text-primary">
                    {{ labelOrDash(profile?.institution_profile?.tpin) }}
                  </div>
                </div>
                <div class="rounded-2xl border border-border/80 bg-surface-muted/40 p-4">
                  <div class="text-[10px] font-semibold uppercase tracking-wider text-text-muted">Contact person</div>
                  <div class="mt-1.5 text-sm font-semibold text-text-primary">
                    {{ labelOrDash(profile?.institution_profile?.contact_person_name) }}
                  </div>
                </div>
              </div>
            </section>

            <!-- Address (both types) -->
            <section class="pt-2">
              <div class="flex items-center gap-3 border-b border-border/60 pb-4">
                <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-slate-500/15 text-slate-800">
                  <MapPin class="h-5 w-5" aria-hidden="true" />
                </span>
                <div>
                  <h3 class="text-base font-semibold text-text-primary">Address</h3>
                  <p class="text-xs text-text-muted">
                    {{ isInstitution ? 'Organization postal address.' : 'Your postal address for correspondence.' }}
                  </p>
                </div>
              </div>
              <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2 rounded-2xl border border-border/80 bg-surface-muted/40 p-4">
                  <div class="text-[10px] font-semibold uppercase tracking-wider text-text-muted">Address line 1</div>
                  <div class="mt-1.5 text-sm font-semibold text-text-primary">
                    {{
                      labelOrDash(
                        isInstitution ? profile?.institution_profile?.address_line_1 : profile?.applicant_profile?.address_line_1,
                      )
                    }}
                  </div>
                </div>
                <div class="sm:col-span-2 rounded-2xl border border-border/80 bg-surface-muted/40 p-4">
                  <div class="text-[10px] font-semibold uppercase tracking-wider text-text-muted">Address line 2</div>
                  <div class="mt-1.5 text-sm font-semibold text-text-primary">
                    {{
                      labelOrDash(
                        isInstitution ? profile?.institution_profile?.address_line_2 : profile?.applicant_profile?.address_line_2,
                      )
                    }}
                  </div>
                </div>
                <div class="rounded-2xl border border-border/80 bg-surface-muted/40 p-4">
                  <div class="text-[10px] font-semibold uppercase tracking-wider text-text-muted">City</div>
                  <div class="mt-1.5 text-sm font-semibold text-text-primary">
                    {{ labelOrDash(isInstitution ? profile?.institution_profile?.city : profile?.applicant_profile?.city) }}
                  </div>
                </div>
                <div class="rounded-2xl border border-border/80 bg-surface-muted/40 p-4">
                  <div class="text-[10px] font-semibold uppercase tracking-wider text-text-muted">Province</div>
                  <div class="mt-1.5 text-sm font-semibold text-text-primary">
                    {{ labelOrDash(isInstitution ? profile?.institution_profile?.province : profile?.applicant_profile?.province) }}
                  </div>
                </div>
                <div class="rounded-2xl border border-border/80 bg-surface-muted/40 p-4">
                  <div class="text-[10px] font-semibold uppercase tracking-wider text-text-muted">Postal code</div>
                  <div class="mt-1.5 font-mono text-sm font-semibold text-text-primary">
                    {{ labelOrDash(isInstitution ? profile?.institution_profile?.postal_code : profile?.applicant_profile?.postal_code) }}
                  </div>
                </div>
                <div class="rounded-2xl border border-border/80 bg-surface-muted/40 p-4">
                  <div class="text-[10px] font-semibold uppercase tracking-wider text-text-muted">Country</div>
                  <div class="mt-1.5 text-sm font-semibold text-text-primary">
                    {{ labelOrDash(isInstitution ? profile?.institution_profile?.country : profile?.applicant_profile?.country) }}
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
