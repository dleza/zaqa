<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import AdminCenteredFormPage from '@/Components/AdminCenteredFormPage.vue'
import { Link, useForm } from '@inertiajs/vue3'
import { SquarePen, User as UserIcon } from 'lucide-vue-next'

const props = defineProps<{
  user: {
    id: number
    name: string
    first_name: string | null
    last_name: string | null
    email: string
    phone_primary: string | null
    phone_secondary: string | null
    is_active: boolean
    disabled_at: string | null
    profile_photo_url: string | null
    department: { id: number; name: string } | null
    current_role: string | null
  }
  roles: Array<{ name: string }>
  departments: Array<{ id: number; name: string }>
}>()

const form = useForm({
  email: props.user.email ?? '',
  phone_primary: props.user.phone_primary ?? '',
  phone_secondary: props.user.phone_secondary ?? '',
  role: props.user.current_role ?? props.roles?.[0]?.name ?? '',
  department_id: props.user.department?.id ?? null,
  first_name: props.user.first_name ?? '',
  last_name: props.user.last_name ?? '',
})

function submit() {
  form.put(`/admin/users/${props.user.id}`, {
    preserveScroll: true,
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
              <SquarePen class="h-4 w-4" aria-hidden="true" />
              User management
            </div>
            <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">Edit user</h1>
            <p class="mt-1 text-sm text-text-muted">Update account details, department, and assigned role.</p>
          </div>

          <div class="flex items-center gap-2">
            <Link :href="`/admin/users/${user.id}`" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm">Back to profile</Link>
          </div>
        </div>
      </template>

      <div class="rounded-2xl border border-border bg-surface p-6 shadow-sm">
        <div class="mb-6 flex items-center gap-3 rounded-2xl border border-border bg-surface-muted/50 p-4">
          <img
            v-if="user.profile_photo_url"
            :src="user.profile_photo_url"
            alt="User profile photo"
            class="h-12 w-12 rounded-2xl object-cover"
          />
          <div
            v-else
            class="flex h-12 w-12 items-center justify-center rounded-2xl border border-border bg-surface"
            aria-hidden="true"
          >
            <UserIcon class="h-5 w-5 text-text-muted" />
          </div>

          <div class="min-w-0">
            <div class="truncate text-sm font-semibold text-text-primary">{{ user.name }}</div>
            <div class="mt-0.5 truncate text-xs text-text-muted">{{ user.email }}</div>
            <div class="mt-1 flex flex-wrap items-center gap-2">
              <span class="zaqa-badge" :class="user.disabled_at ? 'zaqa-badge-danger' : (user.is_active ? 'zaqa-badge-success' : 'zaqa-badge-warning')">
                {{ user.disabled_at ? 'Disabled' : (user.is_active ? 'Active' : 'Inactive') }}
              </span>
              <span v-if="user.current_role" class="zaqa-badge">{{ user.current_role }}</span>
            </div>
          </div>
        </div>

        <form class="space-y-5" @submit.prevent="submit">
          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label class="text-sm font-semibold text-text-primary">First name</label>
              <input v-model="form.first_name" class="zaqa-input" type="text" autocomplete="given-name" />
              <div v-if="form.errors.first_name" class="mt-1 text-xs text-danger">{{ form.errors.first_name }}</div>
            </div>
            <div>
              <label class="text-sm font-semibold text-text-primary">Last name</label>
              <input v-model="form.last_name" class="zaqa-input" type="text" autocomplete="family-name" />
              <div v-if="form.errors.last_name" class="mt-1 text-xs text-danger">{{ form.errors.last_name }}</div>
            </div>
          </div>

          <div>
            <label class="text-sm font-semibold text-text-primary">Email</label>
            <input v-model="form.email" class="zaqa-input" type="email" autocomplete="email" />
            <div v-if="form.errors.email" class="mt-1 text-xs text-danger">{{ form.errors.email }}</div>
          </div>

          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label class="text-sm font-semibold text-text-primary">Primary phone</label>
              <input v-model="form.phone_primary" class="zaqa-input" type="tel" autocomplete="tel" />
              <div v-if="form.errors.phone_primary" class="mt-1 text-xs text-danger">{{ form.errors.phone_primary }}</div>
            </div>
            <div>
              <label class="text-sm font-semibold text-text-primary">Secondary phone</label>
              <input v-model="form.phone_secondary" class="zaqa-input" type="tel" autocomplete="tel-national" />
              <div v-if="form.errors.phone_secondary" class="mt-1 text-xs text-danger">{{ form.errors.phone_secondary }}</div>
            </div>
          </div>

          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label class="text-sm font-semibold text-text-primary">Department</label>
              <select v-model="form.department_id" class="zaqa-input">
                <option :value="null">—</option>
                <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option>
              </select>
              <div v-if="form.errors.department_id" class="mt-1 text-xs text-danger">{{ form.errors.department_id }}</div>
            </div>
            <div>
              <label class="text-sm font-semibold text-text-primary">Role</label>
              <select v-model="form.role" class="zaqa-input">
                <option v-for="r in roles" :key="r.name" :value="r.name">{{ r.name }}</option>
              </select>
              <div v-if="form.errors.role" class="mt-1 text-xs text-danger">{{ form.errors.role }}</div>
            </div>
          </div>

          <div class="flex items-center justify-end gap-2 pt-2">
            <Link :href="`/admin/users/${user.id}`" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm">Cancel</Link>
            <button type="submit" class="zaqa-btn zaqa-btn-primary px-4 py-2 text-sm" :disabled="form.processing">
              Save changes
            </button>
          </div>
        </form>
      </div>
    </AdminCenteredFormPage>
  </AdminLayout>
</template>
