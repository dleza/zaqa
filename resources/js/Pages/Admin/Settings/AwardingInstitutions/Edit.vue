<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import AdminCenteredFormPage from '@/Components/AdminCenteredFormPage.vue'
import { Link, useForm } from '@inertiajs/vue3'
import { Building2 } from 'lucide-vue-next'
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import Swal from 'sweetalert2'

const props = defineProps<{
  institution: {
    id: number
    country_id: number
    name: string
    is_active: boolean
    sort_order: number
    has_consent_form?: boolean
    consent_form_url?: string | null
  }
  countries: Array<{ id: number; name: string; iso_code: string }>
}>()

const form = useForm({
  _method: 'put',
  country_id: props.institution.country_id,
  name: props.institution.name,
  is_active: props.institution.is_active,
  sort_order: props.institution.sort_order,
  consent_form: null as File | null,
  remove_consent_form: false,
})

const consentFileInput = ref<HTMLInputElement | null>(null)
const consentPreviewUrl = ref<string | null>(null)

const consentPreviewKind = computed<'pdf' | 'image' | 'other' | null>(() => {
  const f = form.consent_form
  if (!f) return null
  const name = (f.name ?? '').toString().toLowerCase()
  const type = (f.type ?? '').toString().toLowerCase()
  if (type === 'application/pdf' || name.endsWith('.pdf')) return 'pdf'
  if (type.startsWith('image/') || name.endsWith('.png') || name.endsWith('.jpg') || name.endsWith('.jpeg')) return 'image'
  return 'other'
})

function revokeConsentPreviewUrl() {
  if (consentPreviewUrl.value) URL.revokeObjectURL(consentPreviewUrl.value)
  consentPreviewUrl.value = null
}

watch(
  () => form.consent_form,
  (file) => {
    revokeConsentPreviewUrl()
    if (file) consentPreviewUrl.value = URL.createObjectURL(file)
  },
)

onBeforeUnmount(() => revokeConsentPreviewUrl())

function formatBytes(bytes: number): string {
  const n = Number(bytes || 0)
  if (!Number.isFinite(n) || n <= 0) return '0 B'
  const units = ['B', 'KB', 'MB', 'GB']
  const i = Math.min(Math.floor(Math.log(n) / Math.log(1024)), units.length - 1)
  const v = n / Math.pow(1024, i)
  const digits = i === 0 ? 0 : v >= 10 ? 1 : 2
  return `${v.toFixed(digits)} ${units[i]}`
}

function onConsentFileChange(e: Event) {
  const t = e.target as HTMLInputElement
  form.consent_form = t.files?.[0] ?? null
  if (form.consent_form) form.remove_consent_form = false
}

function clearSelectedConsentFile() {
  form.consent_form = null
  if (consentFileInput.value) consentFileInput.value.value = ''
}

function submit() {
  form.post(`/admin/settings/awarding-institutions/${props.institution.id}`, {
    preserveScroll: true,
    forceFormData: true,
    onSuccess: () => {
      void Swal.fire({
        toast: true,
        position: 'top-end',
        icon: 'success',
        title: 'Updated',
        showConfirmButton: false,
        timer: 2200,
        timerProgressBar: true,
      })
    },
  })
}
</script>

<template>
  <AdminLayout>
    <AdminCenteredFormPage max-width="2xl">
      <template #header>
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
          <div>
            <div class="inline-flex items-center gap-2 text-xs font-semibold text-text-muted">
              <Building2 class="h-4 w-4" aria-hidden="true" />
              System Settings
            </div>
            <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">Edit awarding institution</h1>
            <p class="mt-1 text-sm text-text-muted">Update institution details.</p>
          </div>
          <div class="flex items-center gap-2">
            <Link href="/admin/settings/awarding-institutions" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm">Back</Link>
          </div>
        </div>
      </template>

      <div class="rounded-2xl border border-border bg-surface p-6 shadow-sm">
        <form class="space-y-5" @submit.prevent="submit">
          <div>
            <label class="text-sm font-semibold text-text-primary">Country</label>
            <select v-model="form.country_id" class="zaqa-input">
              <option v-for="c in countries" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
            <div v-if="form.errors.country_id" class="mt-1 text-xs text-danger">{{ form.errors.country_id }}</div>
          </div>

          <div>
            <label class="text-sm font-semibold text-text-primary">Institution name</label>
            <input v-model="form.name" class="zaqa-input" autocomplete="off" />
            <div v-if="form.errors.name" class="mt-1 text-xs text-danger">{{ form.errors.name }}</div>
          </div>

          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label class="text-sm font-semibold text-text-primary">Sort order</label>
              <input v-model.number="form.sort_order" class="zaqa-input" type="number" min="0" />
              <div v-if="form.errors.sort_order" class="mt-1 text-xs text-danger">{{ form.errors.sort_order }}</div>
            </div>
            <div class="flex items-end">
              <label class="flex items-center gap-2 text-sm font-semibold text-text-primary">
                <input v-model="form.is_active" type="checkbox" class="h-4 w-4 rounded border-border" />
                Active
              </label>
            </div>
          </div>
          <div v-if="form.errors.is_active" class="text-xs text-danger">{{ form.errors.is_active }}</div>

          <div class="rounded-xl border border-border bg-surface-muted/50 p-4">
            <div class="text-sm font-semibold text-text-primary">Institution Consent Form</div>
            <p class="mt-1 text-xs text-text-muted">
              Upload the consent form applicants must download, sign, and re-upload when verifying a foreign qualification from this institution.
            </p>

            <div v-if="institution.has_consent_form && institution.consent_form_url" class="mt-3 flex flex-wrap items-center gap-2">
              <a :href="institution.consent_form_url" class="zaqa-btn zaqa-btn-secondary px-3 py-2 text-xs" target="_blank" rel="noopener">
                View / download current form
              </a>
              <label class="ml-auto flex items-center gap-2 text-xs font-semibold text-text-primary">
                <input v-model="form.remove_consent_form" type="checkbox" class="h-4 w-4 rounded border-border" />
                Remove
              </label>
            </div>

            <input
              ref="consentFileInput"
              type="file"
              class="mt-3 block w-full text-sm text-text-primary file:mr-4 file:rounded-lg file:border-0 file:bg-surface file:px-4 file:py-2 file:text-sm file:font-semibold file:text-text-primary hover:file:bg-surface/80"
              accept=".pdf,.doc,.docx,.png,.jpg,.jpeg,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,image/png,image/jpeg"
              @change="onConsentFileChange"
            />
            <div
              v-if="form.consent_form"
              class="mt-3 flex flex-wrap items-center justify-between gap-2 rounded-lg border border-border bg-surface px-3 py-2 text-xs"
            >
              <div class="min-w-0 text-text-muted">
                Selected: <span class="font-semibold text-text-primary">{{ form.consent_form.name }}</span>
                <span class="ml-1">({{ formatBytes(form.consent_form.size) }})</span>
              </div>
              <button type="button" class="zaqa-btn zaqa-btn-secondary px-3 py-1.5 text-xs" @click="clearSelectedConsentFile">
                Clear
              </button>
            </div>
            <div v-if="form.consent_form && consentPreviewUrl" class="mt-3 rounded-lg border border-border bg-surface">
              <div v-if="consentPreviewKind === 'pdf'" class="h-[28rem] w-full">
                <object :data="consentPreviewUrl" type="application/pdf" class="h-full w-full rounded-lg">
                  <iframe :src="consentPreviewUrl" class="h-full w-full rounded-lg" title="Consent form preview" />
                </object>
              </div>
              <div v-else-if="consentPreviewKind === 'image'" class="p-3">
                <img :src="consentPreviewUrl" class="max-h-[28rem] w-full rounded-lg object-contain" alt="Consent form preview" />
              </div>
              <div v-else class="p-3 text-xs text-text-muted">
                Preview is not available for this file type. Save changes to upload, then use “View / download current form”.
              </div>
            </div>
            <div v-if="form.errors.consent_form" class="mt-1 text-xs text-danger">{{ form.errors.consent_form }}</div>
            <div v-if="form.errors.remove_consent_form" class="mt-1 text-xs text-danger">{{ form.errors.remove_consent_form }}</div>
          </div>

          <div class="flex items-center justify-end gap-2 pt-2">
            <button type="submit" class="zaqa-btn zaqa-btn-primary px-4 py-2 text-sm" :disabled="form.processing">
              Save changes
            </button>
          </div>
        </form>
      </div>
    </AdminCenteredFormPage>
  </AdminLayout>
</template>
