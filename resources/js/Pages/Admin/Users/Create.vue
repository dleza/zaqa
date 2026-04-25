<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import AdminCenteredFormPage from '@/Components/AdminCenteredFormPage.vue'
import { Link, useForm } from '@inertiajs/vue3'
import { Users } from 'lucide-vue-next'

const props = defineProps<{
  roles: Array<{ name: string }>
  departments: Array<{ id: number; name: string }>
}>()

const form = useForm({
  email: '',
  phone_primary: '',
  role: props.roles?.[0]?.name ?? '',
  department_id: props.departments?.[0]?.id ?? null,
  first_name: '',
  last_name: '',
})

function submit() {
  form.post('/admin/users', {
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
              <Users class="h-4 w-4" aria-hidden="true" />
              User management
            </div>
            <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">Add new user</h1>
            <p class="mt-1 text-sm text-text-muted">Create a staff account and assign a role (Applicant is not allowed here).</p>
          </div>

          <div class="flex items-center gap-2">
            <Link href="/admin/users" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm">Back</Link>
          </div>
        </div>
      </template>

      <div class="rounded-2xl border border-border bg-surface p-6 shadow-sm">
        <form class="space-y-5" @submit.prevent="submit">
          <div>
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
          </div>

          <div>
            <label class="text-sm font-semibold text-text-primary">Email</label>
            <input v-model="form.email" class="zaqa-input" type="email" autocomplete="email" />
            <div v-if="form.errors.email" class="mt-1 text-xs text-danger">{{ form.errors.email }}</div>
          </div>

          <div>
            <label class="text-sm font-semibold text-text-primary">Phone (optional)</label>
            <input v-model="form.phone_primary" class="zaqa-input" type="tel" autocomplete="tel" />
            <div v-if="form.errors.phone_primary" class="mt-1 text-xs text-danger">{{ form.errors.phone_primary }}</div>
          </div>

          <div>
            <label class="text-sm font-semibold text-text-primary">Department (optional)</label>
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

          <div class="rounded-xl border border-border bg-surface-muted px-4 py-3 text-xs text-text-muted">
            A secure temporary password will be generated automatically after you create this user.
          </div>

          <div class="flex items-center justify-end gap-2 pt-2">
            <button type="submit" class="zaqa-btn zaqa-btn-primary px-4 py-2 text-sm" :disabled="form.processing">
              Create user
            </button>
          </div>
        </form>
      </div>
    </AdminCenteredFormPage>
  </AdminLayout>
</template>

