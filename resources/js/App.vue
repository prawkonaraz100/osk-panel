<script setup lang="ts">
import CalendarWorkspace from './modules/CalendarTraining/CalendarWorkspace.vue'
import InternalExamWorkspace from './modules/InternalExams/InternalExamWorkspace.vue'
import LearningAccessWorkspace from './modules/LearningAccess/LearningAccessWorkspace.vue'
import ResourceWorkspace from './modules/ResourcesCore/ResourceWorkspace.vue'
import StudentCourseWorkspace from './modules/StudentsCourses/StudentCourseWorkspace.vue'

const isCalendarRoute = window.location.pathname === '/kalendarz'
const isStudentCourseRoute = window.location.pathname === '/kursanci'
  || window.location.pathname.startsWith('/kursanci/')
const isLearningAccessRoute = window.location.pathname === '/licencje/panel'
const isInternalExamRoute = window.location.pathname === '/egzamin-wewnetrzny/panel'
</script>

<template>
  <CalendarWorkspace v-if="isCalendarRoute" />
  <InternalExamWorkspace v-else-if="isInternalExamRoute" />
  <LearningAccessWorkspace v-else-if="isLearningAccessRoute" />
  <StudentCourseWorkspace v-else-if="isStudentCourseRoute" />
  <ResourceWorkspace v-else />
</template>
