<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import InputError from '@/Components/InputError.vue'
import { Link, useForm } from '@inertiajs/vue3'

const form = useForm({
  current_password: '',
  password: '',
  password_confirmation: '',
})

function submit() {
  form.post('/admin/change-password')
}
</script>

<template>
  <AdminLayout>
    <div class="flex items-center justify-between">
      <div>
        <h2 class="text-xl font-semibold">Change Password</h2>
        <p class="mt-1 text-sm text-text-muted">Update your account password.</p>
      </div>
      <Link href="/admin/profile" class="zaqa-link text-sm">Back</Link>
    </div>

    <form class="zaqa-card mt-6 max-w-xl space-y-4" @submit.prevent="submit">
      <div>
        <label class="text-sm font-medium">Current password</label>
        <input v-model="form.current_password" type="password" class="zaqa-input" autocomplete="current-password" />
        <InputError :message="form.errors.current_password" />
      </div>

      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div>
          <label class="text-sm font-medium">New password</label>
          <input v-model="form.password" type="password" class="zaqa-input" autocomplete="new-password" />
          <InputError :message="form.errors.password" />
        </div>
        <div>
          <label class="text-sm font-medium">Confirm new password</label>
          <input v-model="form.password_confirmation" type="password" class="zaqa-input" autocomplete="new-password" />
        </div>
      </div>

      <button type="submit" class="zaqa-btn zaqa-btn-primary" :disabled="form.processing">
        Update password
      </button>
    </form>
  </AdminLayout>
</template>

