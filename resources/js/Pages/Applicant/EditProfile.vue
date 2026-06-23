<script setup lang="ts">
import { computed } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import ApplicantLayout from '@/Layouts/ApplicantLayout.vue'
import InputError from '@/Components/InputError.vue'
import ZambianPhoneInput from '@/Components/ZambianPhoneInput.vue'
import { zambianPhoneLocalPart } from '@/lib/zambianPhone'

const props = defineProps<{
  profile: any
  change_trail: Array<{
    id: number
    event_type: string
    message: string
    created_at: string | null
    changed_fields: string[]
  }>
}>()

const isInstitution = computed(() => (props.profile?.applicant_type ?? '') === 'institution')
const emailLocked = computed(() => !!props.profile?.email_verified_at)
const phoneLocked = computed(() => !!props.profile?.phone_verified_at)

const fieldLabels: Record<string, string> = {
  'user.name': 'Display name',
  'user.email': 'Email',
  'user.phone_primary': 'Primary phone',
  'user.phone_secondary': 'Secondary phone',

  'applicant_profile.first_name': 'First name',
  'applicant_profile.middle_name': 'Middle name',
  'applicant_profile.surname': 'Surname',
  'applicant_profile.nrc_number': 'NRC number',
  'applicant_profile.passport_number': 'Passport number',
  'applicant_profile.email': 'Email',
  'applicant_profile.phone_primary': 'Primary phone',
  'applicant_profile.phone_secondary': 'Secondary phone',
  'applicant_profile.address_line_1': 'Address line 1',
  'applicant_profile.address_line_2': 'Address line 2',
  'applicant_profile.city': 'City / Town',
  'applicant_profile.province': 'Province',
  'applicant_profile.postal_code': 'Postal code',
  'applicant_profile.country': 'Country',

  'institution_profile.institution_name': 'Institution name',
  'institution_profile.tpin': 'TPIN',
  'institution_profile.contact_person_name': 'Contact person name',
  'institution_profile.email': 'Email',
  'institution_profile.phone_primary': 'Primary phone',
  'institution_profile.phone_secondary': 'Secondary phone',
  'institution_profile.address_line_1': 'Address line 1',
  'institution_profile.address_line_2': 'Address line 2',
  'institution_profile.city': 'City / Town',
  'institution_profile.province': 'Province',
  'institution_profile.postal_code': 'Postal code',
  'institution_profile.country': 'Country',
}

function labelForField(field: string): string {
  if (fieldLabels[field]) return fieldLabels[field]
  const cleaned = field.split('.').pop() ?? field
  return cleaned
    .replace(/_/g, ' ')
    .replace(/\b\w/g, (c) => c.toUpperCase())
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

const form = useForm<any>({
  email: props.profile?.email ?? '',
  phone_primary: zambianPhoneLocalPart(props.profile?.phone_primary),
  phone_secondary: props.profile?.phone_secondary ?? '',

  address_line_1: props.profile?.applicant_profile?.address_line_1 ?? props.profile?.institution_profile?.address_line_1 ?? '',
  address_line_2: props.profile?.applicant_profile?.address_line_2 ?? props.profile?.institution_profile?.address_line_2 ?? '',
  city: props.profile?.applicant_profile?.city ?? props.profile?.institution_profile?.city ?? '',
  province: props.profile?.applicant_profile?.province ?? props.profile?.institution_profile?.province ?? '',
  postal_code: props.profile?.applicant_profile?.postal_code ?? props.profile?.institution_profile?.postal_code ?? '',
  country: props.profile?.applicant_profile?.country ?? props.profile?.institution_profile?.country ?? '',

  ...(isInstitution.value
    ? {
        institution_name: props.profile?.institution_profile?.institution_name ?? props.profile?.name ?? '',
        tpin: props.profile?.institution_profile?.tpin ?? '',
        contact_person_name: props.profile?.institution_profile?.contact_person_name ?? '',
      }
    : {
        first_name: props.profile?.applicant_profile?.first_name ?? '',
        middle_name: props.profile?.applicant_profile?.middle_name ?? '',
        surname: props.profile?.applicant_profile?.surname ?? '',
        nrc_number: props.profile?.applicant_profile?.nrc_number ?? '',
        passport_number: props.profile?.applicant_profile?.passport_number ?? '',
      }),
})

function save() {
  form.put('/applicant/profile', { preserveScroll: true })
}

const identityForm = useForm<{ file: File | null }>({ file: null })

function onIdentityFile(e: Event) {
  const t = e.target as HTMLInputElement
  identityForm.file = t.files?.[0] ?? null
}

function uploadIdentityDocument() {
  identityForm.post('/applicant/profile/identity-document', {
    preserveScroll: true,
    forceFormData: true,
    onSuccess: () => identityForm.reset('file'),
  })
}

function removeIdentityDocument() {
  router.delete('/applicant/profile/identity-document', { preserveScroll: true })
}
</script>

<template>
  <ApplicantLayout>
    <div class="zaqa-wizard-shell mx-auto w-full min-w-0 max-w-7xl 2xl:max-w-[1440px]">
      <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <h1 class="text-2xl font-semibold tracking-tight text-text-primary">Update profile</h1>
          <p class="mt-1 text-sm text-text-muted">Keep your details current for verification workflows.</p>
        </div>
        <div class="flex flex-wrap gap-2">
          <Link href="/applicant/profile" class="zaqa-btn zaqa-btn-secondary px-3 py-2 text-sm">Back</Link>
        </div>
      </div>

      <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-12">
        <form class="rounded-xl border border-border bg-surface p-5 sm:p-6 xl:col-span-8" @submit.prevent="save">
        <div class="flex items-start justify-between gap-4">
          <div>
            <h2 class="text-base font-semibold text-text-primary">Account details</h2>
            <p class="mt-1 text-sm text-text-muted">These are used for contact and record matching.</p>
          </div>
        </div>

        <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div class="sm:col-span-2">
            <div class="flex items-center justify-between gap-2">
              <label class="text-sm font-medium">Email</label>
              <span v-if="emailLocked" class="text-xs font-semibold text-emerald-700">Verified • Locked</span>
              <span v-else class="text-xs text-text-muted">Required if no primary phone</span>
            </div>
            <input
              v-model="form.email"
              type="email"
              class="zaqa-input"
              :readonly="emailLocked"
              :class="emailLocked ? 'cursor-not-allowed bg-surface-muted/60 text-text-muted' : ''"
            />
            <InputError :message="form.errors.email" />
          </div>

          <div>
            <div class="flex items-center justify-between gap-2">
              <label class="text-sm font-medium">Primary phone</label>
              <span v-if="phoneLocked" class="text-xs font-semibold text-emerald-700">Verified • Locked</span>
              <span v-else class="text-xs text-text-muted">Required if no email</span>
            </div>
            <ZambianPhoneInput
              v-model="form.phone_primary"
              :readonly="phoneLocked"
              :disabled="phoneLocked"
              :helper-text="phoneLocked ? 'Verified contact details are locked for security.' : 'Enter your mobile number without the country code.'"
            />
            <InputError :message="form.errors.phone_primary" />
          </div>
          <div>
            <label class="text-sm font-medium">Secondary phone (optional)</label>
            <input v-model="form.phone_secondary" class="zaqa-input" />
            <InputError :message="form.errors.phone_secondary" />
          </div>

          <div class="sm:col-span-2 text-xs text-text-muted">
            Provide at least one contact method: <span class="font-semibold text-text-primary">email</span> or <span class="font-semibold text-text-primary">primary phone</span>.
          </div>

          <div v-if="emailLocked || phoneLocked" class="sm:col-span-2 rounded-lg border border-border bg-surface-muted/50 px-4 py-3 text-xs text-text-muted">
            Verified contact details are locked for security and cannot be changed from this page.
          </div>
        </div>

        <div class="mt-8 border-t border-border pt-6">
          <h2 class="text-base font-semibold text-text-primary">Identity & names</h2>
          <p class="mt-1 text-sm text-text-muted">For individuals, NRC or Passport is required.</p>

          <div v-if="isInstitution" class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
              <label class="text-sm font-medium">Institution name</label>
              <input v-model="form.institution_name" class="zaqa-input" />
              <InputError :message="form.errors.institution_name" />
            </div>
            <div>
              <label class="text-sm font-medium">TPIN (optional)</label>
              <input v-model="form.tpin" class="zaqa-input" />
              <InputError :message="form.errors.tpin" />
            </div>
            <div>
              <label class="text-sm font-medium">Contact person</label>
              <input v-model="form.contact_person_name" class="zaqa-input" />
              <InputError :message="form.errors.contact_person_name" />
            </div>
          </div>

          <div v-else class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
              <label class="text-sm font-medium">First name</label>
              <input v-model="form.first_name" class="zaqa-input" />
              <InputError :message="form.errors.first_name" />
            </div>
            <div>
              <label class="text-sm font-medium">Middle name (optional)</label>
              <input v-model="form.middle_name" class="zaqa-input" />
              <InputError :message="form.errors.middle_name" />
            </div>
            <div class="sm:col-span-2">
              <label class="text-sm font-medium">Surname</label>
              <input v-model="form.surname" class="zaqa-input" />
              <InputError :message="form.errors.surname" />
            </div>
            <div>
              <label class="text-sm font-medium">NRC number</label>
              <input v-model="form.nrc_number" class="zaqa-input" />
              <InputError :message="form.errors.nrc_number" />
            </div>
            <div>
              <label class="text-sm font-medium">Passport number</label>
              <input v-model="form.passport_number" class="zaqa-input" />
              <InputError :message="form.errors.passport_number" />
            </div>
            <div class="sm:col-span-2 text-xs text-text-muted">
              You must provide <span class="font-semibold text-text-primary">either NRC or Passport</span> (or both).
            </div>
          </div>
        </div>

        <div v-if="!isInstitution" class="mt-8 border-t border-border pt-6">
          <h2 class="text-base font-semibold text-text-primary">Identity document copy</h2>
          <p class="mt-1 text-sm text-text-muted">
            Upload once—future verification applications can reuse this file when you apply for yourself (unless you upload a different copy on a specific application).
          </p>

          <div v-if="profile?.applicant_profile?.identity_document_uploaded_at" class="mt-4 rounded-lg border border-success/25 bg-success/10 px-4 py-3 text-sm">
            <div class="font-semibold text-text-primary">On file</div>
            <div class="mt-1 text-xs text-text-muted">
              {{ profile?.applicant_profile?.identity_document_original_name ?? 'Uploaded document' }}
              <span v-if="profile?.applicant_profile?.identity_document_size_bytes" class="ml-1 font-mono">
                ({{ Math.round(Number(profile.applicant_profile.identity_document_size_bytes) / 1024) }} KB)
              </span>
            </div>
            <button type="button" class="zaqa-btn zaqa-btn-secondary mt-3 px-3 py-2 text-xs" @click="removeIdentityDocument">
              Remove file
            </button>
          </div>

          <div v-else class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:max-w-2xl">
            <div>
              <label class="text-sm font-medium text-text-primary">NRC or passport scan</label>
              <input type="file" class="zaqa-input" accept=".pdf,.jpg,.jpeg,.png,.webp" @change="onIdentityFile" />
              <InputError :message="identityForm.errors.file" />
            </div>
            <button
              type="button"
              class="zaqa-btn zaqa-btn-primary w-fit"
              :disabled="identityForm.processing || !identityForm.file"
              @click="uploadIdentityDocument"
            >
              Upload identity document
            </button>
          </div>
        </div>

        <div class="mt-8 border-t border-border pt-6">
          <h2 class="text-base font-semibold text-text-primary">Address</h2>
          <p class="mt-1 text-sm text-text-muted">Optional, but recommended for official correspondence.</p>

          <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
              <label class="text-sm font-medium">Address line 1</label>
              <input v-model="form.address_line_1" class="zaqa-input" />
              <InputError :message="form.errors.address_line_1" />
            </div>
            <div class="sm:col-span-2">
              <label class="text-sm font-medium">Address line 2 (optional)</label>
              <input v-model="form.address_line_2" class="zaqa-input" />
              <InputError :message="form.errors.address_line_2" />
            </div>
            <div>
              <label class="text-sm font-medium">City / Town</label>
              <input v-model="form.city" class="zaqa-input" />
              <InputError :message="form.errors.city" />
            </div>
            <div>
              <label class="text-sm font-medium">Province</label>
              <input v-model="form.province" class="zaqa-input" />
              <InputError :message="form.errors.province" />
            </div>
            <div>
              <label class="text-sm font-medium">Postal code</label>
              <input v-model="form.postal_code" class="zaqa-input" />
              <InputError :message="form.errors.postal_code" />
            </div>
            <div>
              <label class="text-sm font-medium">Country</label>
              <input v-model="form.country" class="zaqa-input" />
              <InputError :message="form.errors.country" />
            </div>
          </div>
        </div>

        <div class="mt-8 flex flex-wrap gap-2">
          <button type="submit" class="zaqa-btn zaqa-btn-primary w-full sm:w-auto" :disabled="form.processing">
            Save changes
          </button>
          <Link href="/applicant/profile" class="zaqa-btn zaqa-btn-secondary w-full sm:w-auto">
            Cancel
          </Link>
        </div>
      </form>

        <aside class="rounded-xl border border-border bg-surface p-5 sm:p-6 xl:col-span-4 xl:sticky xl:top-6 xl:self-start">
          <div class="flex items-start justify-between gap-3">
            <div>
              <h2 class="text-base font-semibold text-text-primary">Change trail</h2>
              <p class="mt-1 text-sm text-text-muted">Recent updates to your profile details.</p>
            </div>
          </div>

          <div v-if="!change_trail?.length" class="mt-5 rounded-lg border border-dashed border-border bg-surface-muted/40 px-4 py-3 text-sm text-text-muted">
            No recent profile changes recorded.
          </div>

          <ol v-else class="mt-5 space-y-4">
            <li v-for="item in change_trail" :key="item.id" class="rounded-lg border border-border/70 bg-surface-muted/40 px-4 py-3">
              <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                  <div class="text-sm font-semibold text-text-primary">{{ item.message }}</div>
                  <div class="mt-0.5 text-xs text-text-muted">{{ formatWhen(item.created_at) }}</div>
                </div>
              </div>

              <div v-if="item.changed_fields?.length" class="mt-2 flex flex-wrap gap-1.5">
                <span
                  v-for="f in item.changed_fields.slice(0, 6)"
                  :key="f"
                  class="inline-flex items-center rounded-full bg-brand/10 px-2 py-0.5 text-[11px] font-semibold text-brand"
                >
                  {{ labelForField(f) }}
                </span>
                <span v-if="item.changed_fields.length > 6" class="text-[11px] font-semibold text-text-muted">
                  +{{ item.changed_fields.length - 6 }} more
                </span>
              </div>
            </li>
          </ol>

          <div class="mt-6 rounded-lg border border-border/70 bg-surface-muted/40 px-4 py-3 text-xs text-text-muted">
            Changes to identity and contact details are recorded for security and accountability.
          </div>
        </aside>
      </div>
    </div>
  </ApplicantLayout>
</template>