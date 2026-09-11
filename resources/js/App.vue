<script setup lang="ts">
import CalendarWorkspace from './modules/CalendarTraining/CalendarWorkspace.vue'
import ResourceWorkspace from './modules/ResourcesCore/ResourceWorkspace.vue'
import StudentCourseWorkspace from './modules/StudentsCourses/StudentCourseWorkspace.vue'

const isCalendarRoute = window.location.pathname === '/kalendarz'
const isStudentCourseRoute = window.location.pathname === '/kursanci'
  || window.location.pathname.startsWith('/kursanci/')
</script>

<template>
  <CalendarWorkspace v-if="isCalendarRoute" />
  <StudentCourseWorkspace v-else-if="isStudentCourseRoute" />
  <ResourceWorkspace v-else />
</template>
