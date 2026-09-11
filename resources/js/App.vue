<script setup lang="ts">
import ResourceWorkspace from './modules/ResourcesCore/ResourceWorkspace.vue'
import StudentCourseWorkspace from './modules/StudentsCourses/StudentCourseWorkspace.vue'

const isStudentCourseRoute = window.location.pathname === '/kursanci'
  || window.location.pathname.startsWith('/kursanci/')
</script>

<template>
  <StudentCourseWorkspace v-if="isStudentCourseRoute" />
  <ResourceWorkspace v-else />
</template>
