import {
  BadgeCheck,
  Banknote,
  ClipboardList,
  FileText,
  Globe,
  LayoutDashboard,
  Settings,
  ShieldCheck,
  Users,
  Building2,
  GraduationCap,
  Coins,
  BarChart3,
} from 'lucide-vue-next'

export type AdminNavItem = {
  label: string
  href?: string
  icon?: any
  activeStartsWith?: string
  requiredAnyPermissions?: string[]
  children?: AdminNavItem[]
}

export type AdminNavSection = {
  label: string
  items: AdminNavItem[]
  requiredAnyPermissions?: string[]
}

export const adminNavSections: AdminNavSection[] = [
  {
    label: 'Overview',
    items: [
      {
        label: 'Dashboard',
        href: '/admin/dashboard',
        icon: LayoutDashboard,
        activeStartsWith: '/admin/dashboard',
        requiredAnyPermissions: ['dashboard.view'],
      },
    ],
  },
  {
    label: 'User management',
    requiredAnyPermissions: ['admin.users.view', 'admin.applicants.view', 'admin.roles.view', 'admin.roles.manage'],
    items: [
      {
        label: 'Users',
        href: '/admin/users',
        icon: Users,
        activeStartsWith: '/admin/users',
        requiredAnyPermissions: ['admin.users.view'],
      },
      {
        label: 'Applicants',
        href: '/admin/applicants',
        icon: Users,
        activeStartsWith: '/admin/applicants',
        requiredAnyPermissions: ['admin.applicants.view'],
      },
      {
        label: 'Roles & permissions',
        href: '/admin/roles',
        icon: ShieldCheck,
        activeStartsWith: '/admin/roles',
        requiredAnyPermissions: ['admin.roles.view', 'admin.roles.manage'],
      },
    ],
  },
  {
    label: 'Applications',
    requiredAnyPermissions: ['admin.applications.view'],
    items: [
      {
        label: 'Applications pool',
        href: '/admin/applications',
        icon: ClipboardList,
        activeStartsWith: '/admin/applications',
        requiredAnyPermissions: ['admin.applications.view'],
      },
      {
        label: 'Track applications',
        href: '/admin/applications/track',
        icon: FileText,
        activeStartsWith: '/admin/applications/track',
        requiredAnyPermissions: ['admin.applications.view'],
      },
    ],
  },
  {
    label: 'Verification',
    requiredAnyPermissions: ['verification.pool.view', 'verification.level1.process'],
    items: [
      {
        label: 'Applications Pool',
        href: '/admin/verification/pool',
        icon: ShieldCheck,
        activeStartsWith: '/admin/verification/pool',
        requiredAnyPermissions: ['verification.pool.view'],
      },
      {
        label: 'Category by Country',
        href: '/admin/verification/pool/country',
        icon: Globe,
        activeStartsWith: '/admin/verification/pool/country',
        requiredAnyPermissions: ['verification.pool.view'],
      },
      {
        label: 'Category by Awarding Institution',
        href: '/admin/verification/pool/awarding-institution',
        icon: Building2,
        activeStartsWith: '/admin/verification/pool/awarding-institution',
        requiredAnyPermissions: ['verification.pool.view'],
      },
      {
        label: 'Assigned to Me',
        href: '/admin/verification/assigned-to-me',
        icon: Users,
        activeStartsWith: '/admin/verification/assigned-to-me',
        requiredAnyPermissions: ['verification.level1.process'],
      },
    ],
  },
  {
    label: 'Reports',
    requiredAnyPermissions: ['reports.sla.view'],
    items: [
      {
        label: 'SLA performance',
        href: '/admin/reports/sla',
        icon: BarChart3,
        activeStartsWith: '/admin/reports/sla',
        requiredAnyPermissions: ['reports.sla.view'],
      },
    ],
  },
  {
    label: 'Finance',
    requiredAnyPermissions: ['admin.finance.view'],
    items: [
      {
        label: 'Payment proof review',
        href: '/finance/payment-proofs',
        icon: Banknote,
        activeStartsWith: '/finance/payment-proofs',
        requiredAnyPermissions: ['admin.finance.view'],
      },
    ],
  },
  {
    label: 'Certificates',
    requiredAnyPermissions: ['admin.certificates.view'],
    items: [
      {
        label: 'Certificates',
        href: '/admin/certificates',
        icon: BadgeCheck,
        activeStartsWith: '/admin/certificates',
        requiredAnyPermissions: ['admin.certificates.view'],
      },
    ],
  },
  {
    label: '',
    requiredAnyPermissions: [
      'settings.countries.view',
      'settings.awarding_institutions.view',
      'settings.qualification_types.view',
      'settings.fees.view',
      'settings.departments.view',
    ],
    items: [
      {
        label: 'System Settings',
        href: '/admin/settings/countries',
        icon: Settings,
        activeStartsWith: '/admin/settings',
        requiredAnyPermissions: [
          'settings.countries.view',
          'settings.awarding_institutions.view',
          'settings.qualification_types.view',
          'settings.fees.view',
          'settings.departments.view',
        ],
        children: [
          {
            label: 'Countries',
            href: '/admin/settings/countries',
            icon: Globe,
            activeStartsWith: '/admin/settings/countries',
            requiredAnyPermissions: ['settings.countries.view'],
          },
          {
            label: 'Awarding Institutions',
            href: '/admin/settings/awarding-institutions',
            icon: Building2,
            activeStartsWith: '/admin/settings/awarding-institutions',
            requiredAnyPermissions: ['settings.awarding_institutions.view'],
          },
          {
            label: 'Qualification Types',
            href: '/admin/settings/qualification-types',
            icon: GraduationCap,
            activeStartsWith: '/admin/settings/qualification-types',
            requiredAnyPermissions: ['settings.qualification_types.view'],
          },
          {
            label: 'Fees',
            href: '/admin/settings/fees',
            icon: Coins,
            activeStartsWith: '/admin/settings/fees',
            requiredAnyPermissions: ['settings.fees.view'],
          },
          {
            label: 'Departments',
            href: '/admin/settings/departments',
            icon: Users,
            activeStartsWith: '/admin/settings/departments',
            requiredAnyPermissions: ['settings.departments.view'],
          },
        ],
      },
    ],
  },
]

