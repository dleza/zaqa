<script setup lang="ts">
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import ApplicantLayout from '@/Layouts/ApplicantLayout.vue'

const props = defineProps<{
  profile: any
}>()

const isIndividual = computed(() => (props.profile?.applicant_type ?? '') === 'individual')
const isInstitution = computed(() => (props.profile?.applicant_type ?? '') === 'institution')

function labelOrDash(v: any) {
  const s = (v ?? '').toString().trim()
  return s.length ? s : '—'
}
</script>

<template>
  <ApplicantLayout>
    <template #pageHeader>
      <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <h1 class="text-2xl font-semibold tracking-tight text-text-primary">My Profile</h1>
          <p class="mt-1 text-sm text-text-muted">Your account details for ZAQA verification services.</p>
        </div>
        <div class="flex flex-wrap gap-2">
          <Link href="/applicant/profile/edit" class="zaqa-btn zaqa-btn-primary">
            Update Profile
          </Link>
          <Link href="/applicant/change-password" class="zaqa-btn zaqa-btn-secondary">
            Change Password
          </Link>
        </div>
      </div>
    </template>

    <div>
      <div class="zaqa-card mt-6">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <div class="text-xs font-semibold text-text-muted uppercase tracking-wider">Name</div>
            <div class="mt-1 text-sm font-medium text-text-primary">{{ labelOrDash(profile?.name) }}</div>
          </div>
          <div>
            <div class="text-xs font-semibold text-text-muted uppercase tracking-wider">Applicant type</div>
            <div class="mt-1 text-sm font-medium text-text-primary">{{ labelOrDash(profile?.applicant_type) }}</div>
          </div>
          <div>
            <div class="text-xs font-semibold text-text-muted uppercase tracking-wider">Email</div>
            <div class="mt-1 text-sm font-medium text-text-primary">{{ labelOrDash(profile?.email) }}</div>
          </div>
          <div>
            <div class="text-xs font-semibold text-text-muted uppercase tracking-wider">Primary phone</div>
            <div class="mt-1 text-sm font-medium text-text-primary">{{ labelOrDash(profile?.phone_primary) }}</div>
          </div>
          <div>
            <div class="text-xs font-semibold text-text-muted uppercase tracking-wider">Secondary phone</div>
            <div class="mt-1 text-sm font-medium text-text-primary">{{ labelOrDash(profile?.phone_secondary) }}</div>
          </div>
          <div>
            <div class="text-xs font-semibold text-text-muted uppercase tracking-wider">Account status</div>
            <div class="mt-1">
              <span class="zaqa-badge" :class="profile?.is_active ? 'zaqa-badge-success' : 'zaqa-badge-warning'">
                {{ profile?.is_active ? 'Active' : 'Pending activation' }}
              </span>
            </div>
          </div>
        </div>

        <div v-if="isIndividual" class="mt-6">
          <div class="text-xs font-semibold text-text-muted uppercase tracking-wider">Identity details</div>
          <div class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
              <div class="text-xs font-semibold text-text-muted uppercase tracking-wider">First name</div>
              <div class="mt-1 text-sm font-medium text-text-primary">{{ labelOrDash(profile?.applicant_profile?.first_name) }}</div>
            </div>
            <div>
              <div class="text-xs font-semibold text-text-muted uppercase tracking-wider">Middle name</div>
              <div class="mt-1 text-sm font-medium text-text-primary">{{ labelOrDash(profile?.applicant_profile?.middle_name) }}</div>
            </div>
            <div>
              <div class="text-xs font-semibold text-text-muted uppercase tracking-wider">Surname</div>
              <div class="mt-1 text-sm font-medium text-text-primary">{{ labelOrDash(profile?.applicant_profile?.surname) }}</div>
            </div>
            <div>
              <div class="text-xs font-semibold text-text-muted uppercase tracking-wider">NRC number</div>
              <div class="mt-1 text-sm font-medium text-text-primary">{{ labelOrDash(profile?.applicant_profile?.nrc_number) }}</div>
            </div>
            <div>
              <div class="text-xs font-semibold text-text-muted uppercase tracking-wider">Passport number</div>
              <div class="mt-1 text-sm font-medium text-text-primary">{{ labelOrDash(profile?.applicant_profile?.passport_number) }}</div>
            </div>
          </div>
        </div>

        <div v-if="isInstitution" class="mt-6">
          <div class="text-xs font-semibold text-text-muted uppercase tracking-wider">Institution details</div>
          <div class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
              <div class="text-xs font-semibold text-text-muted uppercase tracking-wider">Institution name</div>
              <div class="mt-1 text-sm font-medium text-text-primary">{{ labelOrDash(profile?.institution_profile?.institution_name) }}</div>
            </div>
            <div>
              <div class="text-xs font-semibold text-text-muted uppercase tracking-wider">TPIN</div>
              <div class="mt-1 text-sm font-medium text-text-primary">{{ labelOrDash(profile?.institution_profile?.tpin) }}</div>
            </div>
            <div>
              <div class="text-xs font-semibold text-text-muted uppercase tracking-wider">Contact person</div>
              <div class="mt-1 text-sm font-medium text-text-primary">{{ labelOrDash(profile?.institution_profile?.contact_person_name) }}</div>
            </div>
          </div>
        </div>

        <div class="mt-6">
          <div class="text-xs font-semibold text-text-muted uppercase tracking-wider">Address</div>
          <div class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
              <div class="text-xs font-semibold text-text-muted uppercase tracking-wider">Address line 1</div>
              <div class="mt-1 text-sm font-medium text-text-primary">
                {{ labelOrDash(isInstitution ? profile?.institution_profile?.address_line_1 : profile?.applicant_profile?.address_line_1) }}
              </div>
            </div>
            <div class="sm:col-span-2">
              <div class="text-xs font-semibold text-text-muted uppercase tracking-wider">Address line 2</div>
              <div class="mt-1 text-sm font-medium text-text-primary">
                {{ labelOrDash(isInstitution ? profile?.institution_profile?.address_line_2 : profile?.applicant_profile?.address_line_2) }}
              </div>
            </div>
            <div>
              <div class="text-xs font-semibold text-text-muted uppercase tracking-wider">City</div>
              <div class="mt-1 text-sm font-medium text-text-primary">
                {{ labelOrDash(isInstitution ? profile?.institution_profile?.city : profile?.applicant_profile?.city) }}
              </div>
            </div>
            <div>
              <div class="text-xs font-semibold text-text-muted uppercase tracking-wider">Province</div>
              <div class="mt-1 text-sm font-medium text-text-primary">
                {{ labelOrDash(isInstitution ? profile?.institution_profile?.province : profile?.applicant_profile?.province) }}
              </div>
            </div>
            <div>
              <div class="text-xs font-semibold text-text-muted uppercase tracking-wider">Postal code</div>
              <div class="mt-1 text-sm font-medium text-text-primary">
                {{ labelOrDash(isInstitution ? profile?.institution_profile?.postal_code : profile?.applicant_profile?.postal_code) }}
              </div>
            </div>
            <div>
              <div class="text-xs font-semibold text-text-muted uppercase tracking-wider">Country</div>
              <div class="mt-1 text-sm font-medium text-text-primary">
                {{ labelOrDash(isInstitution ? profile?.institution_profile?.country : profile?.applicant_profile?.country) }}
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </ApplicantLayout>
</template>
