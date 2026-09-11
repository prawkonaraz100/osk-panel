<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ApiError, api } from '../ResourcesCore/api'

type CourseSummary = {
  id: string
  training_type: 'basic' | 'supplementary'
  driving_category_code: string
  training_stage: string
  cancelled_at: string | null
}

type Student = {
  id: string
  first_name: string
  last_name: string
  birth_date: string | null
  no_pesel: boolean
  pesel_masked: string | null
  contact_email: string | null
  phone: string | null
  default_location_id: string | null
  archived_at: string | null
  created_at: string
  version: number
}

type StudentPreview = Student & {
  courses_summary: CourseSummary[]
  payments_summary: Record<string, unknown>
}

type Course = {
  id: string
  student_id: string
  training_type: 'basic' | 'supplementary'
  driving_category_code: string
  pkk_reference_masked: string | null
  started_at: string
  initial_cost: null
  declared_theory_minutes: number
  declared_practical_minutes: number
  lead_instructor_id: string
  location_id: string | null
  training_stage: string
  cancelled_at: string | null
  version: number
}

type RequirementProfile = {
  rule_set_version: string
  theory_training_required: boolean
  minimum_theory_minutes: number
  internal_theory_exam_required: boolean
  practical_training_required: boolean
  minimum_practical_minutes: number
  internal_practical_exam_required: boolean
  exemption_basis_code: string | null
}

type ExternalTraining = {
  id: string
  training_part: 'theory' | 'practical'
  recognized_minutes: number
  source_school_reference: string | null
  evidence_reference: string | null
  created_at: string
  revoked_at: string | null
}

type Category = { id: string; code: string; label: string; active: boolean }
type LocationResource = { id: string; name: string; archived_at: string | null }
type StaffResource = {
  id: string
  first_name: string
  last_name: string
  staff_type_codes: string[]
  archived_at: string | null
}
type Paginated<T> = {
  data: T[]
  meta: { page: number; per_page: number; total: number; last_page: number }
}

type StudentForm = {
  first_name: string
  last_name: string
  phone: string
  contact_email: string
  pesel: string
  no_pesel: boolean
  birth_date: string
  location_id: string
  add_course: boolean
}

type CourseForm = {
  id: string | null
  version: number | null
  training_type: 'basic' | 'supplementary'
  driving_category_code: string
  pkk_number: string
  pkk_masked: string
  started_at: string
  cost: string
  theory_hours_current: string
  theory_hours_previous: string
  practice_hours_current: string
  practice_hours_previous: string
  lead_instructor_id: string
  location_id: string
}

const pathParts = window.location.pathname.split('/').filter(Boolean)
const detailId = pathParts[0] === 'kursanci' ? (pathParts[1] ?? null) : null

const loading = ref(false)
const saving = ref(false)
const error = ref('')
const notice = ref('')
const search = ref('')
const sort = ref('full_name')
const direction = ref<'asc' | 'desc'>('asc')
const includeArchived = ref(false)
const filtersOpen = ref(false)
const selectedCategories = ref<string[]>([])
const selectedStages = ref<string[]>([])
const page = ref(1)
const students = ref<StudentPreview[]>([])
const meta = ref<Paginated<StudentPreview>['meta']>({ page: 1, per_page: 25, total: 0, last_page: 1 })

const currentStudent = ref<Student | null>(null)
const currentStudentEtag = ref<string | null>(null)
const currentCourses = ref<Course[]>([])

const categories = ref<Category[]>([])
const locations = ref<LocationResource[]>([])
const staff = ref<StaffResource[]>([])

const drawer = ref<'student' | 'preview' | 'course' | 'course-detail' | 'pkk-entry' | null>(null)
const editingStudent = ref(false)
const previewStudent = ref<StudentPreview | null>(null)
const studentForm = ref<StudentForm>(emptyStudentForm())
const courseForm = ref<CourseForm>(emptyCourseForm())
const selectedCourse = ref<Course | null>(null)
const selectedRequirement = ref<RequirementProfile | null>(null)
const externalTraining = ref<ExternalTraining[]>([])
const exemptionReason = ref('')
const exemptionEvidence = ref('')
const externalPart = ref<'theory' | 'practical'>('theory')
const externalHours = ref('')
const externalSchool = ref('')
const externalEvidence = ref('')
const externalReason = ref('')

const stageOptions = [
  { value: 'unassigned', label: 'Nieprzypisany' },
  { value: 'theory', label: 'Teoria' },
  { value: 'practice', label: 'Praktyka' },
  { value: 'documentation', label: 'Dokumentacja' },
  { value: 'word_exam', label: 'Egzamin WORD' },
  { value: 'supplementary_training', label: 'Szkolenie uzupełniające' },
  { value: 'training_completed', label: 'Szkolenie zakończone', blocked: true },
]

const activeCategories = computed(() => categories.value.filter((item) => item.active && item.code !== 'PT'))
const instructors = computed(() =>
  staff.value.filter((item) => !item.archived_at && item.staff_type_codes.includes('Instructor')),
)
const activeLocations = computed(() => locations.value.filter((item) => !item.archived_at))
const locationMap = computed(() => new Map(locations.value.map((item) => [item.id, item.name])))
const instructorMap = computed(
  () => new Map(staff.value.map((item) => [item.id, `${item.first_name} ${item.last_name}`])),
)
const pageTitle = computed(() =>
  currentStudent.value ? `${currentStudent.value.first_name} ${currentStudent.value.last_name}` : 'Kursanci',
)

onMounted(load)

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    await loadReferences()
    if (detailId) {
      await loadStudentDetail()
    } else {
      await loadStudents()
    }
  } catch (caught: unknown) {
    handleError(caught)
  } finally {
    loading.value = false
  }
}

async function loadReferences(): Promise<void> {
  const results = await Promise.allSettled([
    api<Category[]>('/api/v1/driving-categories'),
    api<LocationResource[]>('/api/v1/locations'),
    api<Paginated<StaffResource>>('/api/v1/staff?per_page=100'),
  ])

  if (results[0].status === 'fulfilled') categories.value = results[0].value.data
  if (results[1].status === 'fulfilled') locations.value = results[1].value.data
  if (results[2].status === 'fulfilled') staff.value = results[2].value.data.data
}

async function loadStudents(): Promise<void> {
  const params = new URLSearchParams()
  params.set('page', String(page.value))
  params.set('per_page', '25')
  params.set('sort', sort.value)
  params.set('direction', direction.value)
  if (search.value.trim()) params.set('q', search.value.trim())
  if (includeArchived.value) params.set('include_archived', 'true')
  selectedCategories.value.forEach((value) => params.append('category', value))
  selectedStages.value.forEach((value) => params.append('training_stage', value))

  const result = await api<Paginated<StudentPreview>>(`/api/v1/students?${params.toString()}`)
  students.value = result.data.data
  meta.value = result.data.meta
}

async function loadStudentDetail(): Promise<void> {
  if (!detailId) return
  const [studentResult, courseResult] = await Promise.all([
    api<Student>(`/api/v1/students/${detailId}`),
    api<Course[]>(`/api/v1/students/${detailId}/course-enrollments`),
  ])
  currentStudent.value = studentResult.data
  currentStudentEtag.value = studentResult.etag
  currentCourses.value = courseResult.data
}

function emptyStudentForm(): StudentForm {
  return {
    first_name: '',
    last_name: '',
    phone: '',
    contact_email: '',
    pesel: '',
    no_pesel: false,
    birth_date: '',
    location_id: '',
    add_course: false,
  }
}

function emptyCourseForm(): CourseForm {
  return {
    id: null,
    version: null,
    training_type: 'basic',
    driving_category_code: 'B',
    pkk_number: '',
    pkk_masked: '',
    started_at: '',
    cost: '',
    theory_hours_current: '',
    theory_hours_previous: '',
    practice_hours_current: '',
    practice_hours_previous: '',
    lead_instructor_id: '',
    location_id: '',
  }
}

function openStudentCreate(): void {
  editingStudent.value = false
  currentStudentEtag.value = null
  studentForm.value = emptyStudentForm()
  courseForm.value = emptyCourseForm()
  drawer.value = 'student'
}

function openStudentEdit(): void {
  if (!currentStudent.value) return
  editingStudent.value = true
  studentForm.value = {
    first_name: currentStudent.value.first_name,
    last_name: currentStudent.value.last_name,
    phone: currentStudent.value.phone ?? '',
    contact_email: currentStudent.value.contact_email ?? '',
    pesel: '',
    no_pesel: currentStudent.value.no_pesel,
    birth_date: currentStudent.value.birth_date ?? '',
    location_id: currentStudent.value.default_location_id ?? '',
    add_course: false,
  }
  drawer.value = 'student'
}

async function openPreview(student: StudentPreview): Promise<void> {
  error.value = ''
  try {
    previewStudent.value = (await api<StudentPreview>(`/api/v1/students/${student.id}/preview`)).data
    drawer.value = 'preview'
  } catch (caught: unknown) {
    handleError(caught)
  }
}

function openPkkEntrypoint(): void {
  drawer.value = 'pkk-entry'
}

function openCourseCreate(): void {
  courseForm.value = emptyCourseForm()
  drawer.value = 'course'
}

function openCourseEdit(course: Course): void {
  courseForm.value = {
    id: course.id,
    version: course.version,
    training_type: course.training_type,
    driving_category_code: course.driving_category_code,
    pkk_number: '',
    pkk_masked: course.pkk_reference_masked ?? '',
    started_at: toDateTimeLocal(course.started_at),
    cost: '',
    theory_hours_current: minutesToHours(course.declared_theory_minutes, 45),
    theory_hours_previous: '',
    practice_hours_current: minutesToHours(course.declared_practical_minutes, 60),
    practice_hours_previous: '',
    lead_instructor_id: course.lead_instructor_id,
    location_id: course.location_id ?? '',
  }
  drawer.value = 'course'
}

async function openCourseDetail(course: Course): Promise<void> {
  selectedCourse.value = course
  exemptionReason.value = ''
  exemptionEvidence.value = ''
  externalPart.value = 'theory'
  externalHours.value = ''
  externalSchool.value = ''
  externalEvidence.value = ''
  externalReason.value = ''
  drawer.value = 'course-detail'
  await refreshSelectedCourse()
}

function closeDrawer(): void {
  drawer.value = null
  previewStudent.value = null
  selectedCourse.value = null
  selectedRequirement.value = null
  externalTraining.value = []
  error.value = ''
}

async function applyListFilters(): Promise<void> {
  page.value = 1
  loading.value = true
  error.value = ''
  try {
    await loadStudents()
  } catch (caught: unknown) {
    handleError(caught)
  } finally {
    loading.value = false
  }
}

async function changePage(next: number): Promise<void> {
  if (next < 1 || next > meta.value.last_page || next === page.value) return
  page.value = next
  await applyListFilters()
}

async function saveStudent(): Promise<void> {
  saving.value = true
  error.value = ''
  try {
    const body: Record<string, unknown> = {
      first_name: studentForm.value.first_name.trim(),
      last_name: studentForm.value.last_name.trim(),
      phone: studentForm.value.phone.trim() || null,
      contact_email: studentForm.value.contact_email.trim() || null,
      no_pesel: studentForm.value.no_pesel,
      birth_date: studentForm.value.no_pesel ? (studentForm.value.birth_date || null) : null,
      location_id: studentForm.value.location_id || null,
    }

    if (studentForm.value.pesel.trim()) {
      body.pesel = studentForm.value.pesel.trim()
    }

    if (editingStudent.value && currentStudent.value) {
      const result = await api<Student>(`/api/v1/students/${currentStudent.value.id}`, {
        method: 'PATCH',
        headers: currentStudentEtag.value ? { 'If-Match': currentStudentEtag.value } : undefined,
        body: JSON.stringify(body),
      })
      currentStudent.value = result.data
      currentStudentEtag.value = result.etag
      notice.value = 'Dane kursanta zostały zapisane.'
      closeDrawer()
      await loadStudentDetail()
      return
    }

    if (studentForm.value.add_course) {
      validateCourseForm(true)
      body.initial_course = coursePayload(courseForm.value, true)
    }

    const result = await api<Student>('/api/v1/students', {
      method: 'POST',
      idempotent: true,
      body: JSON.stringify(body),
    })
    notice.value = 'Kursant został dodany.'
    closeDrawer()
    window.location.href = `/kursanci/${result.data.id}`
  } catch (caught: unknown) {
    handleError(caught)
  } finally {
    saving.value = false
  }
}

async function saveCourse(): Promise<void> {
  if (!currentStudent.value) return
  saving.value = true
  error.value = ''
  try {
    const editing = courseForm.value.id !== null
    validateCourseForm(!editing)
    const body = coursePayload(courseForm.value, !editing)

    if (editing && courseForm.value.id && courseForm.value.version !== null) {
      await api<Course>(`/api/v1/course-enrollments/${courseForm.value.id}`, {
        method: 'PATCH',
        headers: { 'If-Match': `"v${courseForm.value.version}"` },
        body: JSON.stringify(body),
      })
      notice.value = 'Kurs został zaktualizowany.'
    } else {
      await api<Course>(`/api/v1/students/${currentStudent.value.id}/course-enrollments`, {
        method: 'POST',
        idempotent: true,
        body: JSON.stringify(body),
      })
      notice.value = 'Kurs został dodany.'
    }

    closeDrawer()
    await loadStudentDetail()
  } catch (caught: unknown) {
    handleError(caught)
  } finally {
    saving.value = false
  }
}

function validateCourseForm(create: boolean): void {
  if (!courseForm.value.started_at) throw new Error('Podaj datę i godzinę rozpoczęcia kursu.')
  if (!courseForm.value.lead_instructor_id) throw new Error('Wybierz instruktora prowadzącego.')
  if (!courseForm.value.practice_hours_current) throw new Error('Podaj liczbę godzin praktyki.')
  if (create && !courseForm.value.pkk_number.trim()) throw new Error('Podaj numer PKK.')

  if (
    !create &&
    selectedCourseContextChanged() &&
    !courseForm.value.pkk_number.trim()
  ) {
    throw new Error('Zmiana kategorii lub rodzaju szkolenia wymaga ponownego podania PKK.')
  }
}

function selectedCourseContextChanged(): boolean {
  if (!courseForm.value.id) return false
  const original = currentCourses.value.find((item) => item.id === courseForm.value.id)
  if (!original) return false

  return original.training_type !== courseForm.value.training_type
    || original.driving_category_code !== courseForm.value.driving_category_code
}

function coursePayload(form: CourseForm, create: boolean): Record<string, unknown> {
  const payload: Record<string, unknown> = {
    training_type: form.training_type,
    driving_category_code: form.driving_category_code,
    started_at: new Date(form.started_at).toISOString(),
    declared_theory_minutes: hoursToMinutes(form.theory_hours_current, 45),
    declared_practical_minutes: hoursToMinutes(form.practice_hours_current, 60),
    lead_instructor_id: form.lead_instructor_id,
    location_id: form.location_id || null,
  }

  if (form.pkk_number.trim()) payload.pkk_number = form.pkk_number.trim()
  if (create) {
    payload.recognized_external_theory_minutes = hoursToMinutes(form.theory_hours_previous, 45)
    payload.recognized_external_practical_minutes = hoursToMinutes(form.practice_hours_previous, 60)
  }

  return payload
}

async function changeStage(course: Course, event: Event): Promise<void> {
  const select = event.target as HTMLSelectElement
  const target = select.value
  if (target === course.training_stage) return
  if (target === 'training_completed') {
    select.value = course.training_stage
    error.value = 'Zakończenie szkolenia zostanie odblokowane po podpięciu ewidencji godzin i egzaminu wewnętrznego.'
    return
  }

  saving.value = true
  error.value = ''
  try {
    await api<Course>(`/api/v1/course-enrollments/${course.id}/stage-transitions`, {
      method: 'POST',
      idempotent: true,
      headers: { 'If-Match': `"v${course.version}"` },
      body: JSON.stringify({ target_stage: target }),
    })
    notice.value = 'Etap szkolenia został zmieniony.'
    await loadStudentDetail()
  } catch (caught: unknown) {
    select.value = course.training_stage
    handleError(caught)
  } finally {
    saving.value = false
  }
}

async function cancelCourse(course: Course): Promise<void> {
  const reason = window.prompt('Podaj powód anulowania kursu:')
  if (!reason?.trim()) return

  await mutateCourse(course, `/api/v1/course-enrollments/${course.id}/cancel`, { reason: reason.trim() }, 'Kurs został anulowany.')
}

async function restoreCourse(course: Course): Promise<void> {
  await mutateCourse(course, `/api/v1/course-enrollments/${course.id}/restore`, {}, 'Kurs został przywrócony.')
}

async function mutateCourse(course: Course, path: string, body: Record<string, unknown>, message: string): Promise<void> {
  saving.value = true
  error.value = ''
  try {
    await api<Course>(path, {
      method: 'POST',
      idempotent: true,
      headers: { 'If-Match': `"v${course.version}"` },
      body: JSON.stringify(body),
    })
    notice.value = message
    await loadStudentDetail()
    if (selectedCourse.value?.id === course.id) await refreshSelectedCourse()
  } catch (caught: unknown) {
    handleError(caught)
  } finally {
    saving.value = false
  }
}

async function archiveStudent(): Promise<void> {
  if (!currentStudent.value) return
  saving.value = true
  error.value = ''
  try {
    const result = await api<Student>(`/api/v1/students/${currentStudent.value.id}/archive`, {
      method: 'POST',
      idempotent: true,
      body: JSON.stringify({ reason: 'Archiwizacja z profilu kursanta' }),
    })
    currentStudent.value = result.data
    currentStudentEtag.value = result.etag
    notice.value = 'Kursant został zarchiwizowany.'
  } catch (caught: unknown) {
    handleError(caught)
  } finally {
    saving.value = false
  }
}

async function restoreStudent(): Promise<void> {
  if (!currentStudent.value) return
  saving.value = true
  error.value = ''
  try {
    const result = await api<Student>(`/api/v1/students/${currentStudent.value.id}/restore`, {
      method: 'POST',
      idempotent: true,
      body: JSON.stringify({}),
    })
    currentStudent.value = result.data
    currentStudentEtag.value = result.etag
    notice.value = 'Kursant został przywrócony.'
  } catch (caught: unknown) {
    handleError(caught)
  } finally {
    saving.value = false
  }
}

async function refreshSelectedCourse(): Promise<void> {
  if (!selectedCourse.value) return
  const id = selectedCourse.value.id
  const [courseResult, requirementResult, externalResult] = await Promise.all([
    api<Course>(`/api/v1/course-enrollments/${id}`),
    api<RequirementProfile>(`/api/v1/course-enrollments/${id}/requirements`),
    api<ExternalTraining[]>(`/api/v1/course-enrollments/${id}/recognized-external-training`),
  ])
  selectedCourse.value = courseResult.data
  selectedRequirement.value = requirementResult.data
  externalTraining.value = externalResult.data
}

async function applyTheoryExemption(): Promise<void> {
  if (!selectedCourse.value || !exemptionReason.value.trim()) {
    error.value = 'Podaj powód decyzji o zwolnieniu z teorii.'
    return
  }

  saving.value = true
  error.value = ''
  try {
    await api<RequirementProfile>(`/api/v1/course-enrollments/${selectedCourse.value.id}/exemption-decisions`, {
      method: 'POST',
      idempotent: true,
      headers: { 'If-Match': `"v${selectedCourse.value.version}"` },
      body: JSON.stringify({
        basis_code: 'art_23a',
        evidence_reference: exemptionEvidence.value.trim() || null,
        reason: exemptionReason.value.trim(),
      }),
    })
    notice.value = 'Decyzja o zwolnieniu z teorii została zapisana w historii.'
    exemptionReason.value = ''
    exemptionEvidence.value = ''
    await refreshSelectedCourse()
    await loadStudentDetail()
  } catch (caught: unknown) {
    handleError(caught)
  } finally {
    saving.value = false
  }
}

async function addExternalTraining(): Promise<void> {
  if (!selectedCourse.value || !externalHours.value || !externalReason.value.trim()) {
    error.value = 'Podaj część szkolenia, liczbę godzin i powód uznania.'
    return
  }

  saving.value = true
  error.value = ''
  try {
    const unit = externalPart.value === 'theory' ? 45 : 60
    await api<ExternalTraining>(`/api/v1/course-enrollments/${selectedCourse.value.id}/recognized-external-training`, {
      method: 'POST',
      idempotent: true,
      headers: { 'If-Match': `"v${selectedCourse.value.version}"` },
      body: JSON.stringify({
        training_part: externalPart.value,
        recognized_minutes: hoursToMinutes(externalHours.value, unit),
        source_school_reference: externalSchool.value.trim() || null,
        evidence_reference: externalEvidence.value.trim() || null,
        reason: externalReason.value.trim(),
      }),
    })
    notice.value = 'Szkolenie z poprzedniego OSK zostało dopisane do historii.'
    externalHours.value = ''
    externalSchool.value = ''
    externalEvidence.value = ''
    externalReason.value = ''
    await refreshSelectedCourse()
    await loadStudentDetail()
  } catch (caught: unknown) {
    handleError(caught)
  } finally {
    saving.value = false
  }
}

async function revokeExternal(record: ExternalTraining): Promise<void> {
  if (!selectedCourse.value || record.revoked_at) return
  const reason = window.prompt('Podaj powód wycofania uznanych godzin:')
  if (!reason?.trim()) return

  saving.value = true
  error.value = ''
  try {
    await api<ExternalTraining>(
      `/api/v1/course-enrollments/${selectedCourse.value.id}/recognized-external-training/${record.id}/revoke`,
      {
        method: 'POST',
        idempotent: true,
        headers: { 'If-Match': `"v${selectedCourse.value.version}"` },
        body: JSON.stringify({ reason: reason.trim() }),
      },
    )
    notice.value = 'Wpis został wycofany bez usuwania historii.'
    await refreshSelectedCourse()
    await loadStudentDetail()
  } catch (caught: unknown) {
    handleError(caught)
  } finally {
    saving.value = false
  }
}

function hoursToMinutes(value: string, unit: 45 | 60): number {
  const hours = Number(value || 0)
  if (!Number.isFinite(hours) || hours < 0) throw new Error('Liczba godzin musi być nieujemna.')
  const minutes = hours * unit
  if (!Number.isInteger(minutes)) throw new Error('Podana liczba godzin nie daje pełnej liczby minut.')

  return minutes
}

function minutesToHours(minutes: number, unit: 45 | 60): string {
  if (!minutes) return ''
  return String(minutes / unit)
}

function stageLabel(value: string): string {
  return stageOptions.find((item) => item.value === value)?.label ?? value
}

function formatDate(value: string | null): string {
  if (!value) return '—'
  return new Intl.DateTimeFormat('pl-PL', { dateStyle: 'medium' }).format(new Date(value))
}

function formatDateTime(value: string | null): string {
  if (!value) return '—'
  return new Intl.DateTimeFormat('pl-PL', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
}

function toDateTimeLocal(value: string): string {
  const date = new Date(value)
  const local = new Date(date.getTime() - date.getTimezoneOffset() * 60_000)
  return local.toISOString().slice(0, 16)
}

function describeCourses(items: CourseSummary[]): string {
  if (items.length === 0) return 'Brak kursu'
  const active = items.filter((item) => !item.cancelled_at)
  const source = active.length > 0 ? active : items
  return source.slice(0, 2).map((item) => `${item.driving_category_code} · ${stageLabel(item.training_stage)}`).join(', ')
}

function handleError(caught: unknown): void {
  if (caught instanceof ApiError) {
    const details = Object.values(caught.fields).flat().join(' ')
    error.value = details || caught.message
    return
  }
  if (caught instanceof Error) {
    error.value = caught.message
    return
  }
  error.value = 'Nie udało się wykonać operacji.'
}
</script>

<template>
  <div class="app-shell">
    <aside class="sidebar">
      <a class="brand" href="/">OSK <strong>Panel</strong></a>
      <nav class="main-nav" aria-label="Główna nawigacja">
        <a href="/kursanci" class="active">Kursanci</a>
        <a href="/lokalizacje">Lokalizacje</a>
        <a href="/pracownicy">Pracownicy</a>
        <a href="/pojazdy">Pojazdy</a>
      </nav>
      <div class="sidebar-foot">Stage 5 · Core v1</div>
    </aside>

    <main class="workspace">
      <header class="workspace-header">
        <div>
          <div class="eyebrow">PrawkoNaRaz · OSK</div>
          <h1>{{ pageTitle }}</h1>
        </div>
        <div class="header-actions">
          <a v-if="detailId" class="button ghost" href="/kursanci">Wróć do listy</a>
          <button v-if="!detailId" class="button ghost" type="button" @click="openPkkEntrypoint">Dodaj z PKK</button>
          <button v-if="!detailId" class="button primary" type="button" @click="openStudentCreate">Dodaj ręcznie</button>
        </div>
      </header>

      <div v-if="notice" class="notice success" role="status">
        <span>{{ notice }}</span>
        <button type="button" aria-label="Zamknij" @click="notice = ''">×</button>
      </div>
      <div v-if="error" class="notice error" role="alert">
        <span>{{ error }}</span>
        <button type="button" aria-label="Zamknij" @click="error = ''">×</button>
      </div>

      <div v-if="loading" class="loading-card">Ładowanie danych…</div>

      <template v-else-if="!detailId">
        <section class="student-list-head">
          <div>
            <span class="section-kicker">Twoi kursanci</span>
            <h2>Wyszukano {{ meta.total }} kursantów</h2>
          </div>
          <form class="student-search" @submit.prevent="applyListFilters">
            <input v-model="search" type="search" placeholder="Szukaj kursanta...">
            <button class="button ghost" type="submit">Szukaj</button>
            <button class="button ghost" type="button" @click="filtersOpen = !filtersOpen">
              {{ filtersOpen ? 'Ukryj filtry' : 'Filtry' }}
            </button>
          </form>
        </section>

        <section v-if="filtersOpen" class="filter-panel">
          <div class="filter-block">
            <strong>Kategoria kursu</strong>
            <div class="checkbox-grid">
              <label v-for="item in activeCategories" :key="item.id" class="check">
                <input v-model="selectedCategories" type="checkbox" :value="item.code">
                {{ item.code }}
              </label>
            </div>
          </div>
          <div class="filter-block">
            <strong>Etap szkolenia</strong>
            <div class="checkbox-grid">
              <label v-for="item in stageOptions" :key="item.value" class="check">
                <input v-model="selectedStages" type="checkbox" :value="item.value">
                {{ item.label }}
              </label>
            </div>
          </div>
          <div class="filter-row">
            <label class="check">
              <input v-model="includeArchived" type="checkbox">
              Pokaż archiwalnych
            </label>
            <label>
              Sortowanie
              <select v-model="sort">
                <option value="full_name">Imię i nazwisko</option>
                <option value="phone">Telefon</option>
                <option value="created_at">Data dodania</option>
              </select>
            </label>
            <label>
              Kierunek
              <select v-model="direction">
                <option value="asc">Rosnąco</option>
                <option value="desc">Malejąco</option>
              </select>
            </label>
            <button class="button primary" type="button" @click="applyListFilters">Zastosuj</button>
          </div>
          <p class="module-note">Filtr „Egzamin wewnętrzny niezdany” pojawi się razem z modułem Egzaminu wewnętrznego.</p>
        </section>

        <section class="table-card">
          <table class="student-table">
            <thead>
              <tr>
                <th>Kursant</th>
                <th>Dane do nauki</th>
                <th>Kursy</th>
                <th>Telefon</th>
                <th>Dodano</th>
                <th>Egzamin wewnętrzny</th>
                <th class="actions-column">Akcje</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="student in students" :key="student.id" :class="{ archived: student.archived_at }">
                <td>
                  <div class="person-cell">
                    <div class="avatar">{{ student.first_name.charAt(0) }}{{ student.last_name.charAt(0) }}</div>
                    <div>
                      <strong>{{ student.first_name }} {{ student.last_name }}</strong>
                      <small>{{ student.archived_at ? 'Archiwalny profil' : (student.contact_email ?? 'Brak e-maila kontaktowego') }}</small>
                    </div>
                  </div>
                </td>
                <td><span class="status-pill muted">Moduł Learning Access</span></td>
                <td>
                  <strong>{{ student.courses_summary.length }}</strong>
                  <small class="table-subline">{{ describeCourses(student.courses_summary) }}</small>
                </td>
                <td>{{ student.phone ?? '—' }}</td>
                <td>{{ formatDate(student.created_at) }}</td>
                <td><span class="status-pill muted">Moduł egzaminu</span></td>
                <td>
                  <div class="row-actions">
                    <button class="text-button" type="button" @click="openPreview(student)">Podgląd</button>
                    <a class="text-link strong" :href="`/kursanci/${student.id}`">Przejdź do</a>
                  </div>
                </td>
              </tr>
              <tr v-if="students.length === 0">
                <td colspan="7" class="empty-cell">Brak kursantów spełniających kryteria.</td>
              </tr>
            </tbody>
          </table>
        </section>

        <div class="pagination-row">
          <span>Strona {{ meta.page }} z {{ meta.last_page }}</span>
          <div class="row-actions">
            <button class="button ghost" type="button" :disabled="meta.page <= 1" @click="changePage(meta.page - 1)">Poprzednia</button>
            <button class="button ghost" type="button" :disabled="meta.page >= meta.last_page" @click="changePage(meta.page + 1)">Następna</button>
          </div>
        </div>
      </template>

      <template v-else-if="currentStudent">
        <section class="detail-hero">
          <div class="detail-identity">
            <div class="avatar large">{{ currentStudent.first_name.charAt(0) }}{{ currentStudent.last_name.charAt(0) }}</div>
            <div>
              <div class="detail-title">{{ currentStudent.first_name }} {{ currentStudent.last_name }}</div>
              <div class="detail-subtitle">
                {{ currentStudent.archived_at ? 'Profil archiwalny' : 'Aktywny kursant' }} · dodano {{ formatDate(currentStudent.created_at) }}
              </div>
            </div>
          </div>
          <div class="detail-actions">
            <button v-if="!currentStudent.archived_at" class="button ghost" type="button" @click="openStudentEdit">Edytuj dane</button>
            <button v-if="!currentStudent.archived_at" class="button danger-outline" type="button" @click="archiveStudent">Archiwizuj</button>
            <button v-else class="button primary" type="button" @click="restoreStudent">Przywróć profil</button>
          </div>
        </section>

        <div class="student-tabs" role="tablist">
          <button class="active" type="button">Profil kursanta</button>
          <button type="button" disabled>Egzamin wewnętrzny</button>
          <button type="button" disabled>Postęp</button>
        </div>

        <div class="detail-grid">
          <section class="detail-card">
            <div class="card-heading">
              <div><span class="section-kicker">Dane kursanta</span><h2>Profil</h2></div>
            </div>
            <dl class="details-list">
              <div><dt>Data urodzenia</dt><dd>{{ currentStudent.birth_date ?? '—' }}</dd></div>
              <div><dt>PESEL</dt><dd>{{ currentStudent.no_pesel ? 'Brak numeru PESEL' : (currentStudent.pesel_masked ?? 'Nieuzupełniony') }}</dd></div>
              <div><dt>E-mail kontaktowy</dt><dd>{{ currentStudent.contact_email ?? '—' }}</dd></div>
              <div><dt>Telefon</dt><dd>{{ currentStudent.phone ?? '—' }}</dd></div>
              <div><dt>Lokalizacja</dt><dd>{{ currentStudent.default_location_id ? (locationMap.get(currentStudent.default_location_id) ?? 'Przypisana') : 'Brak lokalizacji' }}</dd></div>
            </dl>
          </section>

          <section class="detail-card">
            <div class="card-heading">
              <div><span class="section-kicker">Dostęp do nauki</span><h2>Konto kursanta</h2></div>
            </div>
            <div class="pending-module">
              <strong>Learning Access jest kolejnym niezależnym modułem.</strong>
              <p>Login, język, hasło i licencje nie są przechowywane w profilu Student i nie są tutaj pozorowane.</p>
            </div>
          </section>
        </div>

        <section class="detail-card">
          <div class="card-heading split">
            <div><span class="section-kicker">Kursy · PKK</span><h2>Kursy kursanta</h2></div>
            <button class="button primary" type="button" :disabled="Boolean(currentStudent.archived_at)" @click="openCourseCreate">Dodaj kurs</button>
          </div>

          <div v-if="currentCourses.length === 0" class="empty-inline">
            <strong>Brak przypisanego kursu.</strong>
            <span>Dodaj kurs, aby ustawić PKK, kategorię i etap szkolenia.</span>
          </div>

          <div v-else class="course-list">
            <article v-for="course in currentCourses" :key="course.id" class="course-row" :class="{ cancelled: course.cancelled_at }">
              <div class="course-main">
                <div class="course-category">{{ course.driving_category_code }}</div>
                <div>
                  <strong>{{ course.training_type === 'basic' ? 'Szkolenie podstawowe' : 'Szkolenie uzupełniające' }}</strong>
                  <span>PKK {{ course.pkk_reference_masked ?? '—' }} · od {{ formatDate(course.started_at) }}</span>
                </div>
              </div>

              <div class="course-stage">
                <label>Etap
                  <select :value="course.training_stage" :disabled="Boolean(course.cancelled_at) || saving" @change="changeStage(course, $event)">
                    <option
                      v-for="stage in stageOptions"
                      :key="stage.value"
                      :value="stage.value"
                      :disabled="stage.blocked"
                    >{{ stage.label }}</option>
                  </select>
                </label>
              </div>

              <div class="course-actions">
                <button class="text-button strong" type="button" @click="openCourseDetail(course)">Szczegóły</button>
                <button v-if="!course.cancelled_at" class="text-button" type="button" @click="openCourseEdit(course)">Edytuj</button>
                <button v-if="!course.cancelled_at" class="text-button danger" type="button" @click="cancelCourse(course)">Anuluj</button>
                <button v-else class="text-button" type="button" @click="restoreCourse(course)">Przywróć</button>
              </div>
            </article>
          </div>
        </section>

        <div class="detail-grid">
          <section class="detail-card muted-module-card">
            <span class="section-kicker">Płatności</span>
            <h2>Finanse kursanta</h2>
            <p>Koszt kursu, należności i wpłaty zostaną podpięte w osobnym module Student Finance.</p>
          </section>
          <section class="detail-card muted-module-card">
            <span class="section-kicker">Egzamin i postęp</span>
            <h2>Kolejne moduły</h2>
            <p>Wyniki egzaminu wewnętrznego i postęp nauki mają własne źródła danych i zostaną dołączone bez dublowania stanu.</p>
          </section>
        </div>
      </template>

      <div v-if="drawer" class="drawer-backdrop" @click.self="closeDrawer">
        <section class="drawer" :class="{ compact: drawer === 'preview' || drawer === 'pkk-entry' }" role="dialog" aria-modal="true">
          <header class="drawer-header">
            <div>
              <span class="section-kicker">
                {{ drawer === 'student' ? 'Kursant' : drawer === 'course' ? 'Kurs · PKK' : drawer === 'course-detail' ? 'Kurs' : 'Kursanci' }}
              </span>
              <h2>
                {{ drawer === 'student'
                  ? (editingStudent ? 'Edytuj kursanta' : 'Dodaj kursanta')
                  : drawer === 'course'
                    ? (courseForm.id ? 'Edytuj kurs' : 'Dodaj kurs')
                    : drawer === 'course-detail'
                      ? `Kurs ${selectedCourse?.driving_category_code ?? ''}`
                      : drawer === 'preview'
                        ? 'Podgląd kursanta'
                        : 'Dodaj z PKK' }}
              </h2>
            </div>
            <button class="icon-button" type="button" aria-label="Zamknij" @click="closeDrawer">×</button>
          </header>

          <form v-if="drawer === 'student'" class="form-grid" @submit.prevent="saveStudent">
            <label>Imię *
              <input v-model="studentForm.first_name" required maxlength="120">
            </label>
            <label>Nazwisko *
              <input v-model="studentForm.last_name" required maxlength="120">
            </label>
            <label>Telefon
              <input v-model="studentForm.phone" maxlength="40">
            </label>
            <label>E-mail do kontaktu
              <input v-model="studentForm.contact_email" type="email" maxlength="320">
            </label>
            <label>PESEL
              <input v-model="studentForm.pesel" inputmode="numeric" :disabled="studentForm.no_pesel" placeholder="Wpisz tylko przy dodaniu lub zmianie">
            </label>
            <label class="toggle-field">
              <span>Kursant nie posiada numeru PESEL</span>
              <input v-model="studentForm.no_pesel" type="checkbox">
            </label>
            <label v-if="studentForm.no_pesel">Data urodzenia *
              <input v-model="studentForm.birth_date" type="date" required>
            </label>
            <label>Lokalizacja
              <select v-model="studentForm.location_id">
                <option value="">Brak</option>
                <option v-for="item in activeLocations" :key="item.id" :value="item.id">{{ item.name }}</option>
              </select>
            </label>

            <fieldset v-if="!editingStudent" class="full">
              <legend>Kurs (PKK) — opcjonalnie</legend>
              <label class="check wide-check">
                <input v-model="studentForm.add_course" type="checkbox">
                Dodaj pierwszy kurs razem z kursantem
              </label>
            </fieldset>

            <template v-if="!editingStudent && studentForm.add_course">
              <CourseFormFields
                v-model="courseForm"
                :categories="activeCategories"
                :instructors="instructors"
                :locations="activeLocations"
                :editing="false"
              />
            </template>

            <div class="full module-note-box">
              <strong>Licencja do platformy</strong>
              <span>Przypisanie loginu i licencji zostanie dodane w dedykowanym module Learning Access/Licenses.</span>
            </div>

            <div class="form-actions full">
              <button class="button ghost" type="button" @click="closeDrawer">Anuluj</button>
              <button class="button primary" type="submit" :disabled="saving">{{ saving ? 'Zapisywanie…' : 'Zapisz' }}</button>
            </div>
          </form>

          <div v-else-if="drawer === 'preview' && previewStudent" class="preview-stack">
            <dl class="details-list">
              <div><dt>Kursant</dt><dd>{{ previewStudent.first_name }} {{ previewStudent.last_name }}</dd></div>
              <div><dt>Data urodzenia</dt><dd>{{ previewStudent.birth_date ?? '—' }}</dd></div>
              <div><dt>PESEL</dt><dd>{{ previewStudent.no_pesel ? 'Brak numeru PESEL' : (previewStudent.pesel_masked ?? 'Nieuzupełniony') }}</dd></div>
              <div><dt>E-mail</dt><dd>{{ previewStudent.contact_email ?? '—' }}</dd></div>
              <div><dt>Telefon</dt><dd>{{ previewStudent.phone ?? '—' }}</dd></div>
              <div><dt>Dodano</dt><dd>{{ formatDate(previewStudent.created_at) }}</dd></div>
            </dl>
            <div class="preview-courses">
              <strong>Kursy ({{ previewStudent.courses_summary.length }})</strong>
              <div v-for="course in previewStudent.courses_summary" :key="course.id" class="mini-course">
                <span>{{ course.driving_category_code }}</span>
                <small>{{ stageLabel(course.training_stage) }}{{ course.cancelled_at ? ' · anulowany' : '' }}</small>
              </div>
              <p v-if="previewStudent.courses_summary.length === 0" class="module-note">Brak kursów.</p>
            </div>
            <a class="button primary" :href="`/kursanci/${previewStudent.id}`">Przejdź do profilu</a>
          </div>

          <div v-else-if="drawer === 'pkk-entry'" class="pending-module">
            <strong>Import kursanta bezpośrednio z systemu PKK wymaga aktywnego provider adaptera.</strong>
            <p>Nie symulujemy pobrania z zewnętrznego systemu. Obecny slice pozwala bezpiecznie zapisać lokalnego kursanta i jego course-scoped PKK; pobranie profilu z providera zostanie podłączone w module PKK.</p>
            <div class="form-actions">
              <button class="button ghost" type="button" @click="closeDrawer">Zamknij</button>
              <button class="button primary" type="button" @click="closeDrawer(); openStudentCreate()">Dodaj ręcznie</button>
            </div>
          </div>

          <form v-else-if="drawer === 'course'" class="form-grid" @submit.prevent="saveCourse">
            <CourseFormFields
              v-model="courseForm"
              :categories="activeCategories"
              :instructors="instructors"
              :locations="activeLocations"
              :editing="Boolean(courseForm.id)"
            />
            <div class="form-actions full">
              <button class="button ghost" type="button" @click="closeDrawer">Anuluj</button>
              <button class="button primary" type="submit" :disabled="saving">{{ saving ? 'Zapisywanie…' : 'Zapisz kurs' }}</button>
            </div>
          </form>

          <div v-else-if="drawer === 'course-detail' && selectedCourse" class="course-detail-stack">
            <section class="drawer-section">
              <div class="drawer-section-heading">
                <div>
                  <span class="section-kicker">Dane kursu</span>
                  <h3>{{ selectedCourse.training_type === 'basic' ? 'Szkolenie podstawowe' : 'Szkolenie uzupełniające' }}</h3>
                </div>
                <button class="button ghost" type="button" @click="openCourseEdit(selectedCourse)">Edytuj</button>
              </div>
              <dl class="details-list">
                <div><dt>Kategoria</dt><dd>{{ selectedCourse.driving_category_code }}</dd></div>
                <div><dt>PKK</dt><dd>{{ selectedCourse.pkk_reference_masked ?? '—' }}</dd></div>
                <div><dt>Start</dt><dd>{{ formatDateTime(selectedCourse.started_at) }}</dd></div>
                <div><dt>Instruktor</dt><dd>{{ instructorMap.get(selectedCourse.lead_instructor_id) ?? 'Przypisany instruktor' }}</dd></div>
                <div><dt>Lokalizacja</dt><dd>{{ selectedCourse.location_id ? (locationMap.get(selectedCourse.location_id) ?? 'Przypisana') : '—' }}</dd></div>
                <div><dt>Etap</dt><dd>{{ stageLabel(selectedCourse.training_stage) }}</dd></div>
              </dl>
            </section>

            <section v-if="selectedRequirement" class="drawer-section">
              <span class="section-kicker">Wymagania szkolenia</span>
              <div class="requirement-grid">
                <div>
                  <span>Teoria</span>
                  <strong>{{ selectedRequirement.theory_training_required ? `${selectedRequirement.minimum_theory_minutes / 45} h` : 'Niewymagana' }}</strong>
                </div>
                <div>
                  <span>Praktyka</span>
                  <strong>{{ selectedRequirement.practical_training_required ? `${selectedRequirement.minimum_practical_minutes / 60} h` : 'Niewymagana' }}</strong>
                </div>
                <div>
                  <span>Egzamin wewn. teor.</span>
                  <strong>{{ selectedRequirement.internal_theory_exam_required ? 'Wymagany' : 'Niewymagany' }}</strong>
                </div>
                <div>
                  <span>Egzamin wewn. prakt.</span>
                  <strong>{{ selectedRequirement.internal_practical_exam_required ? 'Wymagany' : 'Niewymagany' }}</strong>
                </div>
              </div>
              <p v-if="selectedRequirement.exemption_basis_code" class="module-note">Podstawa zwolnienia: {{ selectedRequirement.exemption_basis_code }}</p>
            </section>

            <section class="drawer-section">
              <span class="section-kicker">Zwolnienie z teorii</span>
              <p class="module-note">Użyj tylko, gdy kursant zdał państwową teorię przed kursem i masz podstawę do decyzji art. 23a.</p>
              <div class="inline-form">
                <input v-model="exemptionEvidence" placeholder="Dowód / referencja (opcjonalnie)">
                <input v-model="exemptionReason" placeholder="Powód decyzji *">
                <button class="button ghost" type="button" :disabled="saving" @click="applyTheoryExemption">Zapisz decyzję</button>
              </div>
            </section>

            <section class="drawer-section">
              <span class="section-kicker">Szkolenie w innym OSK</span>
              <div v-if="externalTraining.length" class="external-list">
                <div v-for="record in externalTraining" :key="record.id" class="external-row" :class="{ revoked: record.revoked_at }">
                  <div>
                    <strong>{{ record.training_part === 'theory' ? 'Teoria' : 'Praktyka' }} · {{ record.recognized_minutes / (record.training_part === 'theory' ? 45 : 60) }} h</strong>
                    <span>{{ record.source_school_reference ?? 'Bez oznaczenia szkoły' }} · {{ formatDate(record.created_at) }}</span>
                  </div>
                  <button v-if="!record.revoked_at" class="text-button danger" type="button" @click="revokeExternal(record)">Wycofaj</button>
                  <span v-else class="status-pill muted">Wycofane</span>
                </div>
              </div>
              <div class="inline-form external-form">
                <select v-model="externalPart">
                  <option value="theory">Teoria</option>
                  <option value="practical">Praktyka</option>
                </select>
                <input v-model="externalHours" type="number" min="0" step="0.25" placeholder="Godziny *">
                <input v-model="externalSchool" placeholder="Poprzedni OSK">
                <input v-model="externalEvidence" placeholder="Dowód / dokument">
                <input v-model="externalReason" class="wide" placeholder="Powód uznania *">
                <button class="button primary" type="button" :disabled="saving" @click="addExternalTraining">Dodaj wpis</button>
              </div>
            </section>
          </div>
        </section>
      </div>
    </main>
  </div>
</template>

<script lang="ts">
import { defineComponent, type PropType } from 'vue'

type EmbeddedCourseForm = {
  id: string | null
  version: number | null
  training_type: 'basic' | 'supplementary'
  driving_category_code: string
  pkk_number: string
  pkk_masked: string
  started_at: string
  cost: string
  theory_hours_current: string
  theory_hours_previous: string
  practice_hours_current: string
  practice_hours_previous: string
  lead_instructor_id: string
  location_id: string
}
type EmbeddedCategory = { id: string; code: string; label: string; active: boolean }
type EmbeddedInstructor = { id: string; first_name: string; last_name: string }
type EmbeddedLocation = { id: string; name: string }

export const CourseFormFields = defineComponent({
  name: 'CourseFormFields',
  props: {
    modelValue: { type: Object as PropType<EmbeddedCourseForm>, required: true },
    categories: { type: Array as PropType<EmbeddedCategory[]>, required: true },
    instructors: { type: Array as PropType<EmbeddedInstructor[]>, required: true },
    locations: { type: Array as PropType<EmbeddedLocation[]>, required: true },
    editing: { type: Boolean, required: true },
  },
  emits: ['update:modelValue'],
  methods: {
    set<K extends keyof EmbeddedCourseForm>(key: K, value: EmbeddedCourseForm[K]) {
      this.$emit('update:modelValue', { ...this.modelValue, [key]: value })
    },
  },
  template: `
    <label>Rodzaj *
      <select :value="modelValue.training_type" required @change="set('training_type', ($event.target as HTMLSelectElement).value as EmbeddedCourseForm['training_type'])">
        <option value="basic">Szkolenie podstawowe</option>
        <option value="supplementary">Szkolenie uzupełniające</option>
      </select>
    </label>
    <label>Kategoria *
      <select :value="modelValue.driving_category_code" required @change="set('driving_category_code', ($event.target as HTMLSelectElement).value)">
        <option v-for="item in categories" :key="item.id" :value="item.code">{{ item.code }}</option>
      </select>
    </label>
    <label class="full">PKK *
      <input
        :value="modelValue.pkk_number"
        :required="!editing"
        maxlength="128"
        :placeholder="editing && modelValue.pkk_masked ? 'Obecny: ' + modelValue.pkk_masked + ' · wpisz tylko przy zmianie' : 'Numer PKK'"
        @input="set('pkk_number', ($event.target as HTMLInputElement).value)"
      >
      <small v-if="editing">Pełny numer nie jest odczytywany z bazy. Puste pole pozostawia bieżący PKK bez zmian.</small>
    </label>
    <label>Data i godzina rozpoczęcia *
      <input :value="modelValue.started_at" type="datetime-local" required @input="set('started_at', ($event.target as HTMLInputElement).value)">
    </label>
    <label>Koszt
      <input :value="modelValue.cost" disabled placeholder="Obsługiwany w module Finanse">
      <small>Koszt nie jest drugim polem finansowym na CourseEnrollment.</small>
    </label>
    <label>Godzin teorii
      <input :value="modelValue.theory_hours_current" type="number" min="0" step="0.25" @input="set('theory_hours_current', ($event.target as HTMLInputElement).value)">
      <small>1 godzina teorii = 45 min. To plan/deklaracja, nie zaliczony czas.</small>
    </label>
    <label v-if="!editing">Teoria odbyta w innej szkole
      <input :value="modelValue.theory_hours_previous" type="number" min="0" step="0.25" @input="set('theory_hours_previous', ($event.target as HTMLInputElement).value)">
    </label>
    <label>Godzin praktyki *
      <input :value="modelValue.practice_hours_current" type="number" min="0" step="0.25" required @input="set('practice_hours_current', ($event.target as HTMLInputElement).value)">
      <small>1 godzina praktyki = 60 min. To plan/deklaracja, nie zaliczony czas.</small>
    </label>
    <label v-if="!editing">Praktyka odbyta w innej szkole
      <input :value="modelValue.practice_hours_previous" type="number" min="0" step="0.25" @input="set('practice_hours_previous', ($event.target as HTMLInputElement).value)">
    </label>
    <label>Instruktor *
      <select :value="modelValue.lead_instructor_id" required @change="set('lead_instructor_id', ($event.target as HTMLSelectElement).value)">
        <option value="">Wybierz instruktora</option>
        <option v-for="item in instructors" :key="item.id" :value="item.id">{{ item.first_name }} {{ item.last_name }}</option>
      </select>
    </label>
    <label>Lokalizacja
      <select :value="modelValue.location_id" @change="set('location_id', ($event.target as HTMLSelectElement).value)">
        <option value="">Brak</option>
        <option v-for="item in locations" :key="item.id" :value="item.id">{{ item.name }}</option>
      </select>
    </label>
  `,
})
</script>
