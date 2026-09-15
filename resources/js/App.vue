<script setup lang="ts">
import CalendarWorkspace from './modules/CalendarTraining/CalendarWorkspace.vue'
import DashboardWorkspace from './modules/CommerceDashboard/DashboardWorkspace.vue'
import PurchaseHistoryWorkspace from './modules/CommerceDashboard/PurchaseHistoryWorkspace.vue'
import AuthWorkspace from './modules/IdentityTenant/AuthWorkspace.vue'
import InternalExamWorkspace from './modules/InternalExams/InternalExamWorkspace.vue'
import LearningAccessWorkspace from './modules/LearningAccess/LearningAccessWorkspace.vue'
import SettingsWorkspace from './modules/OrganizationSettings/SettingsWorkspace.vue'
import ResourceWorkspace from './modules/ResourcesCore/ResourceWorkspace.vue'
import StudentCourseWorkspace from './modules/StudentsCourses/StudentCourseWorkspace.vue'

const isAuthRoute = ['/login', '/forgot-password', '/reset-password'].includes(window.location.pathname)
const isDashboardRoute = window.location.pathname === '/'
const isPurchaseHistoryRoute = window.location.pathname === '/historia-zakupow'
const isCalendarRoute = window.location.pathname === '/kalendarz'
const isStudentCourseRoute = window.location.pathname === '/kursanci'
  || window.location.pathname.startsWith('/kursanci/')
const isLearningAccessRoute = window.location.pathname === '/licencje/panel'
const isInternalExamRoute = window.location.pathname === '/egzamin-wewnetrzny/panel'
const isSettingsRoute = window.location.pathname === '/ustawienia'
</script>

<template>
  <AuthWorkspace v-if="isAuthRoute" />
  <DashboardWorkspace v-else-if="isDashboardRoute" />
  <PurchaseHistoryWorkspace v-else-if="isPurchaseHistoryRoute" />
  <CalendarWorkspace v-else-if="isCalendarRoute" />
  <InternalExamWorkspace v-else-if="isInternalExamRoute" />
  <LearningAccessWorkspace v-else-if="isLearningAccessRoute" />
  <SettingsWorkspace v-else-if="isSettingsRoute" />
  <StudentCourseWorkspace v-else-if="isStudentCourseRoute" />
  <ResourceWorkspace v-else />
</template>
