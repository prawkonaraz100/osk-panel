<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ApiError, api } from '../ResourcesCore/api'

type InventoryProjection = {
  entries: Array<{ id: string; source_type: 'free' | 'paid' | 'adjustment'; status: string; created_at: string }>
  summary: {
    available_total: number
    available_by_source_type: Record<'free' | 'paid' | 'adjustment', number>
    reserved_total: number
    consumed_total: number
    adjusted_out_total: number
  }
}

type Subject = {
  student_id: string
  course_enrollment_id: string
  exam_part: 'theory' | 'practical'
  assignment_eligible_now: boolean
  first_name: string
  last_name: string
  full_name: string
  email: string | null
  login: string | null
  course_category: string
  status: 'not_assigned' | 'not_conducted' | 'failed' | 'passed'
  latest_attempt_id: string | null
  latest_attempt_sequence: number | null
  latest_attempt_status: string | null
  latest_exam_category: string | null
  latest_exam_at: string | null
  latest_exam_language: string | null
  latest_attempt_started_at: string | null
  latest_attempt_finished_at: string | null
  exam_count: number
  passed_count: number
  failed_count: number
  pass_rate: number | null
}

type SubjectPage = {
  data: Subject[]
  meta: {
    page: number
    per_page: number
    total: number
    last_page: number
    statistics: {
      subject_count: number
      exam_count: number
      passed_count: number
      failed_count: number
      valid_conducted_count: number
      pass_rate: number | null
    }
  }
}

type Capability = {
  category_code: string
  exam_part: 'theory' | 'practical'
  languages: string[]
}

type CandidateSnapshot = {
  student_id?: string
  first_name: string
  last_name: string
  birth_date: string | null
  contact_email: string | null
  no_pesel_declared: boolean
}

type Attempt = {
  id: string
  student_id: string
  course_enrollment_id: string
  course_attempt_sequence: number
  candidate_snapshot: CandidateSnapshot
  exam_part: 'theory' | 'practical'
  driving_category_code: string
  language_code: string
  status: string
  started_at: string | null
  finished_at: string | null
  created_at: string
  version: number
}

type ExamAccess = {
  id: string
  attempt_id: string
  mode: 'remote_link' | 'local_current_workstation' | 'assigned_exam_station'
  station_id: string | null
  status: string
  expires_at: string | null
  version: number
  one_time_remote_url?: string | null
}

type ExamStation = {
  id: string
  administrative_status: string
  connectivity: 'online' | 'offline'
  occupancy: 'occupied' | 'free'
  has_current_credential: boolean
  available_for_new_execution: boolean
  last_authenticated_heartbeat_at: string | null
  created_at: string
}

type ExamResult = {
  attempt_id: string
  passed: boolean
  score: number | null
  max_score: number | null
  pass_threshold: number | null
  evidence_bundle_hash: string
}

type ReviewQuestion = {
  ordinal: number
  group: string
  question_snapshot: Record<string, unknown>
  candidate_answer: unknown
  is_correct: boolean | null
  points_awarded: number | null
  max_points: number
}

const loading = ref(false)
const saving = ref(false)
const error = ref('')
const notice = ref('')

const inventory = ref<InventoryProjection | null>(null)
const rows = ref<Subject[]>([])
const meta = ref<SubjectPage['meta']>({
  page: 1,
  per_page: 25,
  total: 0,
  last_page: 1,
  statistics: {
    subject_count: 0,
    exam_count: 0,
    passed_count: 0,
    failed_count: 0,
    valid_conducted_count: 0,
    pass_rate: null,
  },
})
const stations = ref<ExamStation[]>([])

const page = ref(1)
const search = ref('')
const selectedCategories = ref<string[]>([])
const selectedStatuses = ref<string[]>([])
const hideFinished = ref(false)
const sort = ref('latest_exam_at')
const direction = ref<'asc' | 'desc'>('desc')
const filterOpen = ref(false)

const expandedCourseId = ref<string | null>(null)
const history = ref<Record<string, Attempt[]>>({})
const selectedAttempt = ref<Attempt | null>(null)
const selectedAttemptEtag = ref<string | null>(null)
const selectedResult = ref<ExamResult | null>(null)
const selectedQuestions = ref<ReviewQuestion[]>([])
const detailsOpen = ref(false)

const generateOpen = ref(false)
const generationSubject = ref<Subject | null>(null)
const generationStep = ref<'subject' | 'configure' | 'launch'>('subject')
const standaloneSearch = ref('')
const standaloneOptions = ref<Subject[]>([])
const capability = ref<Capability | null>(null)
const languageCode = ref('')
const generatedAttempt = ref<Attempt | null>(null)
const generatedAttemptEtag = ref<string | null>(null)
const candidateForm = ref<CandidateSnapshot>({
  first_name: '',
  last_name: '',
  birth_date: null,
  contact_email: null,
  no_pesel_declared: false,
})
const launchMode = ref<'remote_link' | 'local_current_workstation' | 'assigned_exam_station'>('remote_link')
const selectedStationId = ref('')
const stationCredential = ref('')
const createdAccess = ref<ExamAccess | null>(null)
const oneTimeRemoteUrl = ref('')

const categoryOptions = computed(() => {
  const values = rows.value.map((row) => row.course_category)
  if (generationSubject.value) values.push(generationSubject.value.course_category)
  return [...new Set(values)].sort((left, right) => left.localeCompare(right, 'pl'))
})

const availableStations = computed(() => stations.value.filter((station) => station.available_for_new_execution))

const statusOptions: Array<{ value: Subject['status']; label: string }> = [
  { value: 'not_assigned', label: 'Brak przypisanego' },
  { value: 'not_conducted', label: 'Nie przeprowadzony' },
  { value: 'failed', label: 'Niezaliczony' },
  { value: 'passed', label: 'Zaliczony' },
]

onMounted(load)

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    const [inventoryResult, stationResult] = await Promise.all([
      api<InventoryProjection>('/api/v1/internal-exam/inventory'),
      api<ExamStation[]>('/api/v1/exam-stations'),
    ])
    inventory.value = inventoryResult.data
    stations.value = stationResult.data
    await loadRows()
  } catch (caught: unknown) {
    handleError(caught)
  } finally {
    loading.value = false
  }
}

async function loadRows(): Promise<void> {
  const params = new URLSearchParams({
    page: String(page.value),
    per_page: '25',
    hide_finished: hideFinished.value ? '1' : '0',
    sort: sort.value,
    direction: direction.value,
  })
  if (search.value.trim()) params.set('q', search.value.trim())
  selectedCategories.value.forEach((value) => params.append('category[]', value))
  selectedStatuses.value.forEach((value) => params.append('status[]', value))

  const result = await api<SubjectPage>('/api/v1/internal-exam/subjects?' + params.toString())
  rows.value = result.data.data
  meta.value = result.data.meta
}

async function applyFilters(): Promise<void> {
  page.value = 1
  await loadRows()
}

async function goToPage(nextPage: number): Promise<void> {
  page.value = Math.min(Math.max(1, nextPage), meta.value.last_page)
  await loadRows()
}

function toggleArrayValue(target: string[], value: string): void {
  const index = target.indexOf(value)
  if (index >= 0) target.splice(index, 1)
  else target.push(value)
}

async function toggleHistory(row: Subject): Promise<void> {
  if (expandedCourseId.value === row.course_enrollment_id) {
    expandedCourseId.value = null
    return
  }
  expandedCourseId.value = row.course_enrollment_id
  if (!history.value[row.course_enrollment_id]) {
    try {
      const result = await api<Attempt[]>(
        '/api/v1/course-enrollments/' + row.course_enrollment_id + '/internal-exam-attempts',
      )
      history.value[row.course_enrollment_id] = result.data
    } catch (caught: unknown) {
      handleError(caught)
    }
  }
}

function openGenerateFor(row: Subject): void {
  resetGeneration()
  generationSubject.value = row
  generationStep.value = 'configure'
  generateOpen.value = true
  void loadCapability()
}

function openStandaloneGenerate(): void {
  resetGeneration()
  generationStep.value = 'subject'
  generateOpen.value = true
}

function resetGeneration(): void {
  error.value = ''
  notice.value = ''
  generationSubject.value = null
  standaloneSearch.value = ''
  standaloneOptions.value = []
  capability.value = null
  languageCode.value = ''
  generatedAttempt.value = null
  generatedAttemptEtag.value = null
  candidateForm.value = {
    first_name: '',
    last_name: '',
    birth_date: null,
    contact_email: null,
    no_pesel_declared: false,
  }
  launchMode.value = 'remote_link'
  selectedStationId.value = ''
  stationCredential.value = ''
  createdAccess.value = null
  oneTimeRemoteUrl.value = ''
}

async function searchStandalone(): Promise<void> {
  const query = standaloneSearch.value.trim()
  if (!query) {
    standaloneOptions.value = []
    return
  }

  saving.value = true
  error.value = ''
  try {
    const params = new URLSearchParams({
      page: '1',
      per_page: '12',
      q: query,
      sort: 'student_full_name',
      direction: 'asc',
    })
    const result = await api<SubjectPage>('/api/v1/internal-exam/subjects?' + params.toString())
    standaloneOptions.value = result.data.data.filter((row) => row.assignment_eligible_now)
  } catch (caught: unknown) {
    handleError(caught)
  } finally {
    saving.value = false
  }
}

async function chooseStandalone(row: Subject): Promise<void> {
  generationSubject.value = row
  generationStep.value = 'configure'
  await loadCapability()
}

async function loadCapability(): Promise<void> {
  const subject = generationSubject.value
  if (!subject) return

  saving.value = true
  error.value = ''
  try {
    const params = new URLSearchParams({
      category: subject.course_category,
      part: subject.exam_part,
    })
    const result = await api<Capability>('/api/v1/internal-exam/capabilities?' + params.toString())
    capability.value = result.data
    languageCode.value = result.data.languages[0] ?? ''
  } catch (caught: unknown) {
    handleError(caught)
  } finally {
    saving.value = false
  }
}

async function createAttempt(): Promise<void> {
  const subject = generationSubject.value
  if (!subject || !languageCode.value) {
    error.value = 'Wybierz kursanta i język egzaminu.'
    return
  }

  saving.value = true
  error.value = ''
  try {
    const result = await api<Attempt>(
      '/api/v1/course-enrollments/' + subject.course_enrollment_id + '/internal-exam-attempts',
      {
        method: 'POST',
        idempotent: true,
        body: JSON.stringify({
          exam_part: subject.exam_part,
          language_code: languageCode.value,
        }),
      },
    )
    generatedAttempt.value = result.data

    const fresh = await api<Attempt>('/api/v1/internal-exam-attempts/' + result.data.id)
    generatedAttempt.value = fresh.data
    generatedAttemptEtag.value = fresh.etag
    candidateForm.value = {
      first_name: fresh.data.candidate_snapshot.first_name,
      last_name: fresh.data.candidate_snapshot.last_name,
      birth_date: fresh.data.candidate_snapshot.birth_date,
      contact_email: fresh.data.candidate_snapshot.contact_email,
      no_pesel_declared: fresh.data.candidate_snapshot.no_pesel_declared,
    }
    generationStep.value = 'launch'
    notice.value = 'Próba została utworzona, a jedna jednostka egzaminu jest zarezerwowana.'
    await refreshAfterMutation()
  } catch (caught: unknown) {
    handleError(caught)
  } finally {
    saving.value = false
  }
}

async function saveCandidateSnapshot(): Promise<void> {
  const attempt = generatedAttempt.value
  const etag = generatedAttemptEtag.value
  if (!attempt || !etag) return

  saving.value = true
  error.value = ''
  try {
    const result = await api<Attempt>('/api/v1/internal-exam-attempts/' + attempt.id, {
      method: 'PATCH',
      headers: { 'If-Match': etag },
      body: JSON.stringify({ candidate_snapshot: candidateForm.value }),
    })
    generatedAttempt.value = result.data
    generatedAttemptEtag.value = result.etag
    notice.value = 'Dane kandydata dla tej próby zostały zapisane.'
  } catch (caught: unknown) {
    handleError(caught)
  } finally {
    saving.value = false
  }
}

async function createRemoteAccess(): Promise<void> {
  const attempt = generatedAttempt.value
  if (!attempt) return

  saving.value = true
  error.value = ''
  oneTimeRemoteUrl.value = ''
  try {
    const created = await api<ExamAccess>('/api/v1/internal-exam-attempts/' + attempt.id + '/accesses', {
      method: 'POST',
      idempotent: true,
      body: JSON.stringify({ mode: 'remote_link' }),
    })
    createdAccess.value = created.data

    const sent = await api<ExamAccess>('/api/v1/internal-exam-accesses/' + created.data.id + '/send', {
      method: 'POST',
      idempotent: true,
      body: JSON.stringify({}),
    })
    createdAccess.value = sent.data
    oneTimeRemoteUrl.value = sent.data.one_time_remote_url ?? ''
    notice.value = oneTimeRemoteUrl.value
      ? 'Nowy jednorazowy link egzaminacyjny został wygenerowany.'
      : 'Dostęp został utworzony. Ponowne odtworzenie sekretu nie jest możliwe.'
    await refreshAfterMutation()
  } catch (caught: unknown) {
    handleError(caught)
  } finally {
    saving.value = false
  }
}

async function createAndStartLocal(): Promise<void> {
  const attempt = generatedAttempt.value
  const credential = stationCredential.value.trim()
  if (!attempt || !credential) {
    error.value = 'Podaj aktualne poświadczenie tej stacji egzaminacyjnej.'
    return
  }

  saving.value = true
  error.value = ''
  try {
    const body: Record<string, unknown> = { mode: launchMode.value }
    if (launchMode.value === 'assigned_exam_station') {
      if (!selectedStationId.value) {
        error.value = 'Wybierz dostępną stację egzaminacyjną.'
        return
      }
      body.station_id = selectedStationId.value
    }

    const created = await api<ExamAccess>('/api/v1/internal-exam-attempts/' + attempt.id + '/accesses', {
      method: 'POST',
      idempotent: true,
      headers: launchMode.value === 'local_current_workstation'
        ? { 'X-Exam-Station-Credential': credential }
        : {},
      body: JSON.stringify(body),
    })
    createdAccess.value = created.data

    const started = await api<Attempt>('/api/v1/internal-exam-accesses/' + created.data.id + '/start', {
      method: 'POST',
      idempotent: true,
      headers: { 'X-Exam-Station-Credential': credential },
      body: JSON.stringify({}),
    })
    generatedAttempt.value = started.data
    notice.value = 'Egzamin został uruchomiony na uwierzytelnionej stacji. Jednostka została skonsumowana dokładnie przy starcie.'
    stationCredential.value = ''
    await refreshAfterMutation()
  } catch (caught: unknown) {
    handleError(caught)
  } finally {
    saving.value = false
  }
}

async function copyRemoteUrl(): Promise<void> {
  if (!oneTimeRemoteUrl.value) return
  try {
    await navigator.clipboard.writeText(oneTimeRemoteUrl.value)
    notice.value = 'Link skopiowano do schowka. Nie jest przechowywany w systemie w postaci jawnej.'
  } catch {
    notice.value = 'Skopiuj link ręcznie z pola poniżej.'
  }
}

async function openAttemptDetails(attemptId: string): Promise<void> {
  saving.value = true
  error.value = ''
  try {
    const attemptResponse = await api<Attempt>('/api/v1/internal-exam-attempts/' + attemptId)
    selectedAttempt.value = attemptResponse.data
    selectedAttemptEtag.value = attemptResponse.etag
    selectedResult.value = null
    selectedQuestions.value = []

    if (attemptResponse.data.finished_at || attemptResponse.data.status === 'finished') {
      const [result, questions] = await Promise.all([
        api<ExamResult>('/api/v1/internal-exam-attempts/' + attemptId + '/result'),
        api<ReviewQuestion[]>('/api/v1/internal-exam-attempts/' + attemptId + '/questions'),
      ])
      selectedResult.value = result.data
      selectedQuestions.value = questions.data
    }
    detailsOpen.value = true
  } catch (caught: unknown) {
    handleError(caught)
  } finally {
    saving.value = false
  }
}

function downloadAnswerSheet(attemptId: string): void {
  window.open(
    '/api/v1/internal-exam-attempts/' + attemptId + '/documents/answer-sheet.pdf',
    '_blank',
    'noopener,noreferrer',
  )
}

function openPurchaseDeferred(): void {
  notice.value = 'Zakup dodatkowej puli egzaminów jest jawnie odroczony do modułu Platform Commerce. Dostępne jednostki i korekty działają już na ledgerze egzaminów.'
}

async function refreshAfterMutation(): Promise<void> {
  const [inventoryResult, stationResult] = await Promise.all([
    api<InventoryProjection>('/api/v1/internal-exam/inventory'),
    api<ExamStation[]>('/api/v1/exam-stations'),
  ])
  inventory.value = inventoryResult.data
  stations.value = stationResult.data
  history.value = {}
  expandedCourseId.value = null
  await loadRows()
}

function statusLabel(value: string): string {
  return {
    not_assigned: 'Brak przypisanego',
    not_conducted: 'Nie przeprowadzony',
    failed: 'Niezaliczony',
    passed: 'Zaliczony',
    created: 'Utworzony',
    in_progress: 'W toku',
    finished: 'Zakończony',
    technical_aborted: 'Przerwany technicznie',
  }[value] ?? value
}

function partLabel(value: string): string {
  return value === 'theory' ? 'Teoria' : 'Praktyka'
}

function formatDate(value: string | null): string {
  if (!value) return '—'
  return new Intl.DateTimeFormat('pl-PL', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
}

function percent(value: number | null): string {
  if (value === null) return '—'
  return new Intl.NumberFormat('pl-PL', { style: 'percent', maximumFractionDigits: 0 }).format(value)
}

function questionTitle(question: ReviewQuestion): string {
  const snapshot = question.question_snapshot
  for (const key of ['text', 'question', 'content', 'prompt']) {
    const value = snapshot[key]
    if (typeof value === 'string' && value.trim()) return value
  }
  const identifier = snapshot.identifier
  return typeof identifier === 'string' && identifier
    ? 'Pytanie ' + question.ordinal + ' · ' + identifier
    : 'Pytanie ' + question.ordinal
}

function answerText(value: unknown): string {
  if (value === null || value === undefined) return 'Brak odpowiedzi'
  if (typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean') return String(value)
  try {
    return JSON.stringify(value)
  } catch {
    return 'Odpowiedź zapisana'
  }
}

function handleError(caught: unknown): void {
  if (caught instanceof ApiError) {
    error.value = Object.values(caught.fields).flat().join(' ') || caught.message
  } else if (caught instanceof Error) {
    error.value = caught.message
  } else {
    error.value = 'Nie udało się wykonać operacji.'
  }
}
</script>

<template>
  <div class="app-shell">
    <aside class="sidebar">
      <a class="brand" href="/">
        OSK
        <strong>Panel</strong>
      </a>
      <nav class="main-nav" aria-label="Główna nawigacja">
        <a href="/kursanci">Kursanci</a>
        <a href="/lokalizacje">Lokalizacje</a>
        <a href="/pracownicy">Pracownicy</a>
        <a href="/pojazdy">Pojazdy</a>
        <a href="/kalendarz">Kalendarz</a>
        <a href="/licencje/panel">Licencje</a>
        <a href="/egzamin-wewnetrzny/panel" class="active">Egzaminy</a>
      </nav>
      <div class="sidebar-foot">Stage 5 · Core v1</div>
    </aside>

    <main class="workspace">
      <header class="workspace-header">
        <div>
          <div class="eyebrow">PrawkoNaRaz · OSK</div>
          <h1>Egzaminy wewnętrzne</h1>
        </div>
        <div class="header-actions">
          <button class="button ghost" type="button" @click="openPurchaseDeferred">
            Wykup egzaminy
          </button>
          <button class="button primary" type="button" @click="openStandaloneGenerate">
            Generuj egzamin
          </button>
        </div>
      </header>

      <div v-if="notice" class="notice success" role="status">
        {{ notice }}
      </div>
      <div v-if="error" class="notice error" role="alert">
        {{ error }}
      </div>
      <div v-if="loading" class="loading-card">
        Ładowanie egzaminów…
      </div>

      <template v-else>
        <section class="exam-summary-grid">
          <article class="exam-summary-card">
            <span>Dostępne</span>
            <strong>{{ inventory?.summary.available_total ?? 0 }}</strong>
            <small>wszystkie jednostki</small>
          </article>
          <article class="exam-summary-card">
            <span>Darmowe</span>
            <strong>{{ inventory?.summary.available_by_source_type.free ?? 0 }}</strong>
            <small>dostępne teraz</small>
          </article>
          <article class="exam-summary-card">
            <span>Opłacone</span>
            <strong>{{ inventory?.summary.available_by_source_type.paid ?? 0 }}</strong>
            <small>dostępne teraz</small>
          </article>
          <article class="exam-summary-card">
            <span>Korekty</span>
            <strong>{{ inventory?.summary.available_by_source_type.adjustment ?? 0 }}</strong>
            <small>dostępne teraz</small>
          </article>
        </section>

        <section class="exam-stat-strip">
          <div>
            <span>Kursanci / wymagania</span>
            <strong>{{ meta.statistics.subject_count }}</strong>
          </div>
          <div>
            <span>Próby</span>
            <strong>{{ meta.statistics.exam_count }}</strong>
          </div>
          <div>
            <span>Zaliczone</span>
            <strong>{{ meta.statistics.passed_count }}</strong>
          </div>
          <div>
            <span>Niezaliczone</span>
            <strong>{{ meta.statistics.failed_count }}</strong>
          </div>
          <div>
            <span>Zdawalność</span>
            <strong>{{ percent(meta.statistics.pass_rate) }}</strong>
          </div>
        </section>

        <section class="toolbar exam-toolbar">
          <div>
            <span class="section-kicker">Przypisane egzaminy</span>
            <p class="section-description">
              Wiersz pokazuje kurs i część egzaminu, a historia prób pozostaje rozwijana osobno.
            </p>
          </div>
          <div class="exam-filter-actions">
            <input
              v-model="search"
              type="search"
              placeholder="Imię, nazwisko, e-mail lub login…"
              @keyup.enter="applyFilters"
            >
            <button class="button ghost" type="button" @click="filterOpen = !filterOpen">
              Filtry
            </button>
            <select v-model="sort" @change="applyFilters">
              <option value="latest_exam_at">Data egzaminu</option>
              <option value="identity_or_login">E-mail / login</option>
              <option value="student_full_name">Kursant</option>
              <option value="latest_exam_category">Kategoria</option>
              <option value="latest_exam_status">Status</option>
              <option value="latest_exam_language">Język</option>
              <option value="exam_count">Liczba prób</option>
            </select>
            <select v-model="direction" @change="applyFilters">
              <option value="desc">Malejąco</option>
              <option value="asc">Rosnąco</option>
            </select>
            <label class="check">
              <input v-model="hideFinished" type="checkbox" @change="applyFilters">
              Ukryj zakończone
            </label>
            <button class="button primary compact-button" type="button" @click="applyFilters">
              Szukaj
            </button>
          </div>
        </section>

        <section v-if="filterOpen" class="filter-panel exam-filter-panel">
          <div class="filter-block">
            <strong>Kategoria kursu</strong>
            <div class="exam-chip-row">
              <label v-for="category in categoryOptions" :key="category" class="exam-chip">
                <input
                  type="checkbox"
                  :checked="selectedCategories.includes(category)"
                  @change="toggleArrayValue(selectedCategories, category)"
                >
                {{ category }}
              </label>
              <span v-if="categoryOptions.length === 0" class="module-note">Brak kategorii w bieżącym wyniku.</span>
            </div>
          </div>
          <div class="filter-block">
            <strong>Status</strong>
            <div class="exam-chip-row">
              <label v-for="option in statusOptions" :key="option.value" class="exam-chip">
                <input
                  type="checkbox"
                  :checked="selectedStatuses.includes(option.value)"
                  @change="toggleArrayValue(selectedStatuses, option.value)"
                >
                {{ option.label }}
              </label>
            </div>
          </div>
          <div class="form-actions">
            <button class="button ghost" type="button" @click="selectedCategories = []; selectedStatuses = []; applyFilters()">
              Wyczyść
            </button>
            <button class="button primary" type="button" @click="applyFilters">
              Zastosuj
            </button>
          </div>
        </section>

        <section class="table-card">
          <table class="exam-table">
            <thead>
              <tr>
                <th>Kursant</th>
                <th>Kategoria</th>
                <th>Część</th>
                <th>Ostatni egzamin</th>
                <th>Status</th>
                <th>Język</th>
                <th>Próby</th>
                <th />
              </tr>
            </thead>
            <tbody>
              <template v-for="row in rows" :key="row.course_enrollment_id + ':' + row.exam_part">
                <tr>
                  <td>
                    <strong>{{ row.full_name }}</strong>
                    <small>{{ row.email ?? row.login ?? 'Brak e-mail/loginu' }}</small>
                  </td>
                  <td>{{ row.latest_exam_category ?? row.course_category }}</td>
                  <td>{{ partLabel(row.exam_part) }}</td>
                  <td>{{ formatDate(row.latest_exam_at) }}</td>
                  <td>
                    <span class="status-pill" :class="{ muted: row.status === 'not_assigned' || row.status === 'not_conducted' }">
                      {{ statusLabel(row.status) }}
                    </span>
                  </td>
                  <td>{{ row.latest_exam_language?.toUpperCase() ?? '—' }}</td>
                  <td>{{ row.exam_count }}</td>
                  <td class="actions-column">
                    <button
                      v-if="row.assignment_eligible_now"
                      class="text-button strong"
                      type="button"
                      @click="openGenerateFor(row)"
                    >
                      Generuj
                    </button>
                    <button class="text-button" type="button" @click="toggleHistory(row)">
                      {{ expandedCourseId === row.course_enrollment_id ? 'Zwiń' : 'Historia' }}
                    </button>
                  </td>
                </tr>
                <tr
                  v-if="expandedCourseId === row.course_enrollment_id"
                  class="exam-expanded-row"
                >
                  <td colspan="8">
                    <div class="exam-history">
                      <div
                        v-for="attempt in history[row.course_enrollment_id] ?? []"
                        :key="attempt.id"
                        class="exam-history-row"
                      >
                        <div>
                          <strong>
                            #{{ attempt.course_attempt_sequence }} · {{ partLabel(attempt.exam_part) }} · {{ statusLabel(attempt.status) }}
                          </strong>
                          <span>
                            {{ attempt.driving_category_code }} · {{ attempt.language_code.toUpperCase() }} · {{ formatDate(attempt.finished_at ?? attempt.started_at ?? attempt.created_at) }}
                          </span>
                        </div>
                        <button class="text-button strong" type="button" @click="openAttemptDetails(attempt.id)">
                          Szczegóły
                        </button>
                      </div>
                      <div v-if="(history[row.course_enrollment_id] ?? []).length === 0" class="empty-inline compact-empty">
                        <strong>Brak prób egzaminacyjnych.</strong>
                        <span>Możesz wygenerować pierwszą próbę dla tego kursu.</span>
                      </div>
                    </div>
                  </td>
                </tr>
              </template>
              <tr v-if="rows.length === 0">
                <td colspan="8">
                  <div class="empty-inline">
                    <strong>Brak wyników dla wybranych filtrów.</strong>
                    <span>Zmień wyszukiwanie albo statusy.</span>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </section>

        <div class="pagination-row">
          <span>Strona {{ meta.page }} z {{ meta.last_page }} · {{ meta.total }} pozycji</span>
          <div class="row-actions">
            <button class="button ghost" type="button" :disabled="meta.page <= 1" @click="goToPage(meta.page - 1)">
              Poprzednia
            </button>
            <button class="button ghost" type="button" :disabled="meta.page >= meta.last_page" @click="goToPage(meta.page + 1)">
              Następna
            </button>
          </div>
        </div>
      </template>

      <div v-if="generateOpen" class="drawer-backdrop" @click.self="generateOpen = false">
        <section class="drawer exam-drawer" role="dialog" aria-modal="true" aria-label="Generuj egzamin wewnętrzny">
          <header class="drawer-header">
            <div>
              <span class="section-kicker">Egzamin wewnętrzny</span>
              <h2>Generuj egzamin</h2>
            </div>
            <button class="icon-button" type="button" aria-label="Zamknij" @click="generateOpen = false">
              ×
            </button>
          </header>

          <div v-if="generationStep === 'subject'" class="exam-drawer-stack">
            <p class="module-note">
              Wyszukaj istniejącego kursanta. Formalna próba zawsze pozostaje powiązana z trwałym kursem.
            </p>
            <div class="student-search compact-search">
              <input
                v-model="standaloneSearch"
                type="search"
                placeholder="Imię, nazwisko, e-mail lub login…"
                @keyup.enter="searchStandalone"
              >
              <button class="button primary" type="button" :disabled="saving" @click="searchStandalone">
                Szukaj
              </button>
            </div>
            <div class="search-result-list">
              <button
                v-for="option in standaloneOptions"
                :key="option.course_enrollment_id + ':' + option.exam_part"
                class="search-result"
                type="button"
                @click="chooseStandalone(option)"
              >
                <strong>{{ option.full_name }}</strong>
                <span>{{ option.course_category }} · {{ partLabel(option.exam_part) }} · {{ option.email ?? option.login ?? 'bez e-mail/loginu' }}</span>
              </button>
            </div>
            <div v-if="standaloneSearch && standaloneOptions.length === 0 && !saving" class="empty-inline compact-empty">
              <strong>Brak kwalifikujących się kursów.</strong>
              <span>Nowego kursanta lub kurs najpierw zapisz w module Kursanci.</span>
            </div>
          </div>

          <div v-else-if="generationStep === 'configure' && generationSubject" class="exam-drawer-stack">
            <section class="drawer-section">
              <span class="section-kicker">Kandydat</span>
              <dl class="details-list">
                <div><dt>Kursant</dt><dd>{{ generationSubject.full_name }}</dd></div>
                <div><dt>Kategoria</dt><dd>{{ generationSubject.course_category }}</dd></div>
                <div><dt>Część</dt><dd>{{ partLabel(generationSubject.exam_part) }}</dd></div>
                <div><dt>Dostępna pula</dt><dd>{{ inventory?.summary.available_total ?? 0 }}</dd></div>
              </dl>
            </section>
            <label>Język egzaminu
              <select v-model="languageCode" :disabled="saving">
                <option v-for="language in capability?.languages ?? []" :key="language" :value="language">
                  {{ language.toUpperCase() }}
                </option>
              </select>
            </label>
            <p v-if="capability && capability.languages.length === 0" class="notice error">
              Brak aktywnej capability językowej dla tej kategorii i części.
            </p>
            <div class="form-actions">
              <button class="button ghost" type="button" @click="generationStep = 'subject'; generationSubject = null">
                Wróć
              </button>
              <button
                class="button primary"
                type="button"
                :disabled="saving || !languageCode || (inventory?.summary.available_total ?? 0) < 1"
                @click="createAttempt"
              >
                {{ saving ? 'Tworzenie…' : 'Utwórz próbę i zarezerwuj jednostkę' }}
              </button>
            </div>
          </div>

          <div v-else-if="generationStep === 'launch' && generatedAttempt" class="exam-drawer-stack">
            <section class="drawer-section">
              <span class="section-kicker">Dane kandydata w tej próbie</span>
              <div class="form-grid compact-form-grid">
                <label>Imię
                  <input v-model="candidateForm.first_name" maxlength="120">
                </label>
                <label>Nazwisko
                  <input v-model="candidateForm.last_name" maxlength="120">
                </label>
                <label>E-mail
                  <input v-model="candidateForm.contact_email" type="email" maxlength="320">
                </label>
                <label>Data urodzenia
                  <input v-model="candidateForm.birth_date" type="date">
                </label>
                <label class="check full">
                  <input v-model="candidateForm.no_pesel_declared" type="checkbox">
                  Brak numeru PESEL
                </label>
              </div>
              <div class="form-actions">
                <button class="button ghost" type="button" :disabled="saving" @click="saveCandidateSnapshot">
                  Zapisz dane próby
                </button>
              </div>
            </section>

            <section class="drawer-section">
              <span class="section-kicker">Sposób przeprowadzenia</span>
              <div class="exam-launch-options">
                <label>
                  <input v-model="launchMode" type="radio" value="remote_link">
                  <strong>Link zdalny</strong>
                  <span>Kandydat otrzymuje jednorazowy link. Sekret nie jest odzyskiwalny z bazy.</span>
                </label>
                <label>
                  <input v-model="launchMode" type="radio" value="local_current_workstation">
                  <strong>Ta stacja</strong>
                  <span>Stacja jest identyfikowana wyłącznie przez aktualne poświadczenie urządzenia.</span>
                </label>
                <label>
                  <input v-model="launchMode" type="radio" value="assigned_exam_station">
                  <strong>Wybrana stacja</strong>
                  <span>Przypisz próbę do dostępnej stacji i uwierzytelnij ją przy starcie.</span>
                </label>
              </div>

              <template v-if="launchMode === 'remote_link'">
                <div class="form-actions">
                  <button class="button primary" type="button" :disabled="saving || Boolean(createdAccess)" @click="createRemoteAccess">
                    {{ saving ? 'Generowanie…' : 'Wygeneruj link' }}
                  </button>
                </div>
                <div v-if="oneTimeRemoteUrl" class="secret-handoff exam-link-handoff">
                  <span>Jednorazowy link do przekazania kandydatowi</span>
                  <input :value="oneTimeRemoteUrl" readonly>
                  <small>Po zamknięciu tego widoku system nie odtworzy jawnego tokenu.</small>
                  <button class="button ghost" type="button" @click="copyRemoteUrl">
                    Kopiuj link
                  </button>
                </div>
              </template>

              <template v-else>
                <label v-if="launchMode === 'assigned_exam_station'">Stacja
                  <select v-model="selectedStationId">
                    <option value="">Wybierz stację</option>
                    <option v-for="station in availableStations" :key="station.id" :value="station.id">
                      {{ station.id.slice(0, 8) }} · {{ station.connectivity }} · {{ station.occupancy }}
                    </option>
                  </select>
                </label>
                <label>Poświadczenie stacji
                  <input
                    v-model="stationCredential"
                    type="password"
                    autocomplete="off"
                    placeholder="Wklej aktualne poświadczenie urządzenia"
                  >
                </label>
                <p class="module-note">
                  Poświadczenie służy tylko do bieżącego żądania i nie jest zapisywane w stanie domenowym.
                </p>
                <div class="form-actions">
                  <button class="button primary" type="button" :disabled="saving" @click="createAndStartLocal">
                    {{ saving ? 'Uruchamianie…' : 'Utwórz dostęp i uruchom egzamin' }}
                  </button>
                </div>
              </template>
            </section>
          </div>
        </section>
      </div>

      <div v-if="detailsOpen && selectedAttempt" class="drawer-backdrop" @click.self="detailsOpen = false">
        <section class="drawer exam-drawer" role="dialog" aria-modal="true" aria-label="Szczegóły egzaminu">
          <header class="drawer-header">
            <div>
              <span class="section-kicker">Próba #{{ selectedAttempt.course_attempt_sequence }}</span>
              <h2>Szczegóły egzaminu</h2>
            </div>
            <button class="icon-button" type="button" aria-label="Zamknij" @click="detailsOpen = false">
              ×
            </button>
          </header>

          <section class="drawer-section">
            <dl class="details-list">
              <div><dt>Status</dt><dd>{{ statusLabel(selectedAttempt.status) }}</dd></div>
              <div><dt>Kategoria</dt><dd>{{ selectedAttempt.driving_category_code }}</dd></div>
              <div><dt>Część</dt><dd>{{ partLabel(selectedAttempt.exam_part) }}</dd></div>
              <div><dt>Język</dt><dd>{{ selectedAttempt.language_code.toUpperCase() }}</dd></div>
              <div><dt>Start</dt><dd>{{ formatDate(selectedAttempt.started_at) }}</dd></div>
              <div><dt>Koniec</dt><dd>{{ formatDate(selectedAttempt.finished_at) }}</dd></div>
            </dl>
          </section>

          <section v-if="selectedResult" class="drawer-section">
            <span class="section-kicker">Wynik</span>
            <div class="exam-result-card" :class="{ passed: selectedResult.passed }">
              <strong>{{ selectedResult.passed ? 'Zaliczony' : 'Niezaliczony' }}</strong>
              <span>
                {{ selectedResult.score ?? '—' }} / {{ selectedResult.max_score ?? '—' }}
                · próg {{ selectedResult.pass_threshold ?? '—' }}
              </span>
            </div>
            <button
              v-if="selectedAttempt.exam_part === 'theory'"
              class="button ghost"
              type="button"
              @click="downloadAnswerSheet(selectedAttempt.id)"
            >
              Pobierz arkusz odpowiedzi PDF
            </button>
          </section>

          <section v-if="selectedQuestions.length" class="drawer-section">
            <span class="section-kicker">Przegląd pytań</span>
            <div class="exam-question-list">
              <article
                v-for="question in selectedQuestions"
                :key="question.ordinal"
                class="exam-question-card"
              >
                <div>
                  <strong>{{ questionTitle(question) }}</strong>
                  <span>{{ question.group === 'basic' ? 'Podstawowe' : 'Specjalistyczne' }}</span>
                </div>
                <p>{{ answerText(question.candidate_answer) }}</p>
                <small>
                  {{ question.is_correct === null ? 'Brak oceny' : question.is_correct ? 'Poprawna' : 'Niepoprawna' }}
                  · {{ question.points_awarded ?? 0 }}/{{ question.max_points }} pkt
                </small>
              </article>
            </div>
          </section>

          <p v-if="!selectedResult" class="module-note">
            Wynik i przegląd pytań są dostępne po zakończeniu próby. Bieżąca próba pozostaje częścią historii.
          </p>
        </section>
      </div>
    </main>
  </div>
</template>
