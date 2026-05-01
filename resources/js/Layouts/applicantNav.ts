import type { Component } from 'vue'

import {
  LayoutDashboard,
  FilePlus,
  Files,
  ReceiptText,
  CreditCard,
  UserCircle,
  KeyRound,
} from 'lucide-vue-next'

export type ApplicantNavItem = {
  key: string
  label: string
  href: string
  icon: Component
  badgeKey?: string
}

export type ApplicantNavSection = {
  key: string
  label: string
  items: ApplicantNavItem[]
}

export const applicantNavSections: ApplicantNavSection[] = [
  {
    key: 'dashboard',
    label: 'Dashboard',
    items: [{ key: 'dashboard', label: 'Dashboard', href: '/applicant/dashboard', icon: LayoutDashboard }],
  },
  {
    key: 'verification',
    label: 'Verification',
    items: [
      { key: 'submit', label: 'Submit Application', href: '/applicant/applications/new', icon: FilePlus },
      { key: 'applications', label: 'My Applications', href: '/applicant/applications', icon: Files, badgeKey: 'applications' },
    ],
  },
  {
    key: 'payments',
    label: 'Payments',
    items: [
      { key: 'invoices', label: 'Invoices', href: '/applicant/invoices', icon: ReceiptText, badgeKey: 'invoices' },
      { key: 'payments', label: 'Payments', href: '/applicant/payments', icon: CreditCard },
    ],
  },
  {
    key: 'profile',
    label: 'My Profile',
    items: [
      { key: 'view-profile', label: 'View Profile', href: '/applicant/profile', icon: UserCircle },
      { key: 'change-password', label: 'Change Password', href: '/applicant/change-password', icon: KeyRound },
    ],
  },
];

