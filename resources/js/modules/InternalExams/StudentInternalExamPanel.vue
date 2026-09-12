<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ApiError, api } from '../ResourcesCore/api'

type StudentCourseReference = {
  id: string
  driving_category_code: string
  pkk_reference_masked: string | null
  cancelled_at: string | null
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

type InventoryProjection = {
  summary: {
    available_total: number
    available_by_source_type: {
      free: number
      paid: number
      adjustment: number
    }
    reserved_total: number
    consumed_total: number
    adjusted_out_total: number
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

const props = defineProps<{
  studentId: string
  studentName: string
  studentEmail: string | null
  peselMasked: string | null
  archived: boolean
  courses: StudentCourseReference[]
}>()

const loading = ref(false)
const saving = ref(false)
const error = ref('')
const notice = ref('')

const inventory = ref<InventoryProjection | null>(null)
const subjects = ref<Subject[]>([])
const subjectMeta = ref<SubjectPage['meta']>({
  page: 1,
  per_page: 100,
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
const attempts = ref<Attempt[]>([])

const generateOpen = ref(false)
const generationSubject = ref<Subject | null>(null)
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
const launchMode = ref<'remote_link' | 'local_current_workstation'>('remote_link')
const stationCredential = ref('')
const createdAccess = ref<ExamAccess | null>(null)
const oneTimeRemoteUrl = ref('')

const detailsOpen = ref(false)
const selectedAttempt = ref<Attempt | null>(null)
const selectedResult = ref<ExamResult | null>(null)
const selectedQuestions = ref<ReviewQuestion[]>([])

const eligibleSubjects = computed(() =>
  subjects.value.filter((subject) => subject.assignment_eligible_now),
)

const lastAttempt = computed(() => attempts.value[0] ?? null)

onMounted(load)

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    const params = new URLSearchParams({
      student_id: props.studentId,
      page: '1',
      per_page: '100',
      sort: 'latest_exam_at',
      direction: 'desc',
    })

    const [inventoryResponse, subjectResponse] = await Promise.all([
      api<InventoryProjection>('/api/v1/internal-exam/inventory'),
      api<SubjectPage>('/api/v1/internal-exam/subjects?' + params.toString()),
    ])

    inventory.value = inventoryResponse.data
    subjects.value = subjectResponse.data.data
    subjectMeta.value = subjectResponse.data.meta
    await loadHistory()
  } catch (caught: unknown) {
    handleError(caught)
  } finally {
    loading.value = false
  }
}

async function loadHistory(): Promise<void> {
  const courseIds = [...new Set(subjects.value.map((subject) => subject.course_enrollment_id))]
  if (courseIds.length === 0) {
    attempts.value = []
    return
  }

  const responses = await Promise.all(
    courseIds.map((courseId) =>
      api<Attempt[]>('/api/v1/course-enrollments/' + courseId + '/internal-exam-attempts'),
    ),
  )

  attempts.value = responses
    .flatMap((response) => response.data)
    .sort((left, right) => attemptTimestamp(right) - attemptTimestamp(left))
}

function attemptTimestamp(attempt: Attempt): number {
  const raw = attempt.finished_at ?? attempt.started_at ?? attempt.created_at
  const value = Date.parse(raw)
  return Number.isNaN(value) ? 0 : value
}

function openGenerate(): void {
  error.value = ''
  notice.value = ''
  generationSubject.value = eligibleSubjects.value.length === 1 ? eligibleSubjects.value[0] ?? null : null
  capability.value = null
  languageCode.value = ''
  generatedAttempt.value = null
  generatedAttemptEtag.value = null
  stationCredential.value = ''
  createdAccess.value = null
  oneTimeRemoteUrl.value = ''
  launchMode.value = 'remote_link'
  generateOpen.value = true

  if (generationSubject.value) {
    void loadCapability()
  }
}

async function chooseSubject(subject: Subject): Promise<void> {
  generationSubject.value = subject
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
    const response = await api<Capability>('/api/v1/internal-exam/capabilities?' + params.toString())
    capability.value = response.data
    languageCode.value = response.data.languages[0] ?? ''
  } catch (caught: unknown) {
    handleError(caught)
  } finally {
    saving.value = false
  }
}

async function createAttempt(): Promise<void> {
  const subject = generationSubject.value
  if (!subject || !languageCode.value) {
    error.value = 'Wybierz kategorię/część oraz język egzaminu.'
    return
  }

  saving.value = true
  error.value = ''
  try {
    const created = await api<Attempt>(
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

    const fresh = await api<Attempt>('/api/v1/internal-exam-attempts/' + created.data.id)
    generatedAttempt.value = fresh.data
    generatedAttemptEtag.value = fresh.etag
    candidateForm.value = {
      first_name: fresh.data.candidate_snapshot.first_name,
      last_name: fresh.data.candidate_snapshot.last_name,
      birth_date: fresh.data.candidate_snapshot.birth_date,
      contact_email: fresh.data.candidate_snapshot.contact_email,
      no_pesel_declared: fresh.data.candidate_snapshot.no_pesel_declared,
    }
    notice.value = 'Próba utworzona. Jedna jednostka egzaminu została zarezerwowana.'
    await load()
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
    const response = await api<Attempt>('/api/v1/internal-exam-attempts/' + attempt.id, {
      method: 'PATCH',
      headers: { 'If-Match': etag },
      body: JSON.stringify({ candidate_snapshot: candidateForm.value }),
    })
    generatedAttempt.value = response.data
    generatedAttemptEtag.value = response.etag
    notice.value = 'Dane kandydata zapisane w wersjonowanym snapshotcie tej próby.'
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
    const access = await api<ExamAccess>('/api/v1/internal-exam-attempts/' + attempt.id + '/accesses', {
      method: 'POST',
      idempotent: true,
      body: JSON.stringify({ mode: 'remote_link' }),
    })
    createdAccess.value = access.data

    const sent = await api<ExamAccess>('/api/v1/internal-exam-accesses/' + access.data.id + '/send', {
      method: 'POST',
      idempotent: true,
      body: JSON.stringify({}),
    })
    createdAccess.value = sent.data
    oneTimeRemoteUrl.value = sent.data.one_time_remote_url ?? ''
    notice.value = oneTimeRemoteUrl.value
      ? 'Wygenerowano nowy jednorazowy link egzaminacyjny.'
      : 'Dostęp zdalny został przygotowany.'
    await load()
  } catch (caught: unknown) {
    handleError(caught)
  } finally {
    saving.value = false
  }
}

async function startLocal(): Promise<void> {
  const attempt = generatedAttempt.value
  const credential = stationCredential.value.trim()
  if (!attempt || !credential) {
    error.value = 'Podaj aktualne poświadczenie tej stacji egzaminacyjnej.'
    return
  }

  saving.value = true
  error.value = ''
  try {
    const access = await api<ExamAccess>('/api/v1/internal-exam-attempts/' + attempt.id + '/accesses', {
      method: 'POST',
      idempotent: true,
      headers: { 'X-Exam-Station-Credential': credential },
      body: JSON.stringify({ mode: 'local_current_workstation' }),
    })
    createdAccess.value = access.data

    const started = await api<Attempt>('/api/v1/internal-exam-accesses/' + access.data.id + '/start', {
      method: 'POST',
      idempotent: true,
      headers: { 'X-Exam-Station-Credential': credential },
      body: JSON.stringify({}),
    })
    generatedAttempt.value = started.data
    stationCredential.value = ''
    notice.value = 'Egzamin uruchomiony na tej uwierzytelnionej stacji.'
    await load()
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
    notice.value = 'Link skopiowano. Jawny token nie jest odzyskiwalny po zamknięciu widoku.'
  } catch {
    notice.value = 'Skopiuj link ręcznie z pola poniżej.'
  }
}

async function openDetails(attempt: Attempt): Promise<void> {
  saving.value = true
  error.value = ''
  selectedAttempt.value = attempt
  selectedResult.value = null
  selectedQuestions.value = []
  try {
    if (attempt.status === 'passed' || attempt.status === 'failed') {
      const [result, questions] = await Promise.all([
        api<ExamResult>('/api/v1/internal-exam-attempts/' + attempt.id + '/result'),
        api<ReviewQuestion[]>('/api/v1/internal-exam-attempts/' + attempt.id + '/questions'),
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

function coursePkk(subject: Subject | null): string {
  if (!subject) return '—'
  return props.courses.find((course) => course.id === subject.course_enrollment_id)?.pkk_reference_masked ?? '—'
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
  return new Intl.DateTimeFormat('pl-PL', {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(new Date(value))
}

function percent(value: number | null): string {
  if (value === null) return '0%'
  return new Intl.NumberFormat('pl-PL', {
    style: 'percent',
    maximumFractionDigits: 0,
  }).format(value)
}

function questionTitle(question: ReviewQuestion): string {
  for (const key of ['text', 'question', 'content', 'prompt']) {
    const value = question.question_snapshot[key]
    if (typeof value === 'string' && value.trim()) return value
  }
  return 'Pytanie ' + question.ordinal
}

function answerText(value: unknown): string {
  if (value === null || value === undefined) return 'Brak odpowiedzi'
  if (typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean') {
    return String(value)
  }
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
    error.value = 'Nie udało się wykonać operacji egzaminacyjnej.'
  }
}
</script>

<template>
  <section class="student-exam-panel">
    <div
      v-if="notice"
      class="notice success"
      role="status"
    >
      {{ notice }}
    </div>
    <div
      v-if="error"
      class="notice error"
      role="alert"
    >
      {{ error }}
    </div>

    <div
      v-if="loading"
      class="loading-card"
    >
      Ładowanie egzaminów kursanta…
    </div>

    <template v-else>
      <section class="detail-card student-exam-hero-card">
        <div class="card-heading split">
          <div>
            <span class="section-kicker">
              Ostatni egzamin wewnętrzny
            </span>
            <h2>
              {{ lastAttempt ? statusLabel(lastAttempt.status) : 'Brak przeprowadzonego egzaminu' }}
            </h2>
          </div>
          <button
            class="button primary"
            type="button"
            :disabled="archived || eligibleSubjects.length === 0 || (inventory?.summary.available_total ?? 0) < 1"
            @click="openGenerate"
          >
            Rozpocznij egzamin
          </button>
        </div>

        <div
          v-if="lastAttempt"
          class="student-exam-last"
        >
          <div>
            <span>Data</span>
            <strong>{{ formatDate(lastAttempt.finished_at ?? lastAttempt.started_at ?? lastAttempt.created_at) }}</strong>
          </div>
          <div>
            <span>Kategoria</span>
            <strong>{{ lastAttempt.driving_category_code }}</strong>
          </div>
          <div>
            <span>Część</span>
            <strong>{{ partLabel(lastAttempt.exam_part) }}</strong>
          </div>
          <div>
            <span>Język</span>
            <strong>{{ lastAttempt.language_code.toUpperCase() }}</strong>
          </div>
          <button
            class="text-button strong"
            type="button"
            @click="openDetails(lastAttempt)"
          >
            Szczegóły
          </button>
        </div>
        <p
          v-else
          class="module-note"
        >
          Po utworzeniu pierwszej próby pojawi się tutaj ostatni egzamin, a pełna historia pozostanie poniżej.
        </p>

        <div class="student-exam-inventory-note">
          <strong>{{ inventory?.summary.available_total ?? 0 }}</strong>
          <span>dostępnych jednostek egzaminacyjnych w OSK</span>
        </div>
      </section>

      <section class="student-exam-metrics">
        <article>
          <span>Zdawalność</span>
          <strong>{{ percent(subjectMeta.statistics.pass_rate) }}</strong>
        </article>
        <article>
          <span>Zaliczone</span>
          <strong>{{ subjectMeta.statistics.passed_count }}</strong>
        </article>
        <article>
          <span>Niezaliczone</span>
          <strong>{{ subjectMeta.statistics.failed_count }}</strong>
        </article>
        <article>
          <span>Wszystkie próby</span>
          <strong>{{ subjectMeta.statistics.exam_count }}</strong>
        </article>
      </section>

      <section class="detail-card">
        <div class="card-heading split">
          <div>
            <span class="section-kicker">
              Wszystkie egzaminy
            </span>
            <h2>
              Historia prób
            </h2>
          </div>
          <span class="module-note">
            {{ subjects.length }} kontekstów egzaminacyjnych
          </span>
        </div>

        <div
          v-if="attempts.length === 0"
          class="empty-inline"
        >
          <strong>Brak historii egzaminów.</strong>
          <span>Próby będą zapisywane osobno dla kursu i części egzaminu.</span>
        </div>

        <div
          v-else
          class="student-exam-history"
        >
          <button
            v-for="attempt in attempts"
            :key="attempt.id"
            class="student-exam-history-row"
            type="button"
            @click="openDetails(attempt)"
          >
            <div>
              <strong>
                {{ formatDate(attempt.finished_at ?? attempt.started_at ?? attempt.created_at) }}
              </strong>
              <span>
                Próba #{{ attempt.course_attempt_sequence }} · {{ partLabel(attempt.exam_part) }}
              </span>
            </div>
            <span class="student-exam-category">
              {{ attempt.driving_category_code }}
            </span>
            <span
              class="status-pill"
              :class="{ muted: !attempt.finished_at }"
            >
              {{ statusLabel(attempt.status) }}
            </span>
          </button>
        </div>
      </section>

      <section
        v-if="eligibleSubjects.length > 0"
        class="detail-card"
      >
        <div class="card-heading">
          <div>
            <span class="section-kicker">
              Wymagane części egzaminu
            </span>
            <h2>
              Dostępne do uruchomienia
            </h2>
          </div>
        </div>
        <div class="student-exam-contexts">
          <article
            v-for="subject in eligibleSubjects"
            :key="subject.course_enrollment_id + ':' + subject.exam_part"
          >
            <div>
              <strong>{{ subject.course_category }} · {{ partLabel(subject.exam_part) }}</strong>
              <span>{{ statusLabel(subject.status) }} · {{ subject.exam_count }} prób</span>
            </div>
            <button
              class="text-button strong"
              type="button"
              :disabled="archived || (inventory?.summary.available_total ?? 0) < 1"
              @click="chooseSubject(subject); generateOpen = true"
            >
              Rozpocznij
            </button>
          </article>
        </div>
      </section>
    </template>

    <div
      v-if="generateOpen"
      class="drawer-backdrop"
      @click.self="generateOpen = false"
    >
      <section
        class="drawer exam-drawer"
        role="dialog"
        aria-modal="true"
        aria-label="Egzamin wewnętrzny kursanta"
      >
        <header class="drawer-header">
          <div>
            <span class="section-kicker">
              {{ studentName }}
            </span>
            <h2>
              Rozpocznij egzamin
            </h2>
          </div>
          <button
            class="icon-button"
            type="button"
            aria-label="Zamknij"
            @click="generateOpen = false"
          >
            ×
          </button>
        </header>

        <div class="exam-drawer-stack">
          <section
            v-if="!generationSubject"
            class="drawer-section"
          >
            <span class="section-kicker">
              Kategoria i część
            </span>
            <div class="student-exam-contexts">
              <button
                v-for="subject in eligibleSubjects"
                :key="subject.course_enrollment_id + ':' + subject.exam_part"
                class="student-exam-context-choice"
                type="button"
                @click="chooseSubject(subject)"
              >
                <strong>{{ subject.course_category }} · {{ partLabel(subject.exam_part) }}</strong>
                <span>{{ subject.exam_count }} dotychczasowych prób</span>
              </button>
            </div>
          </section>

          <template v-else>
            <section class="drawer-section">
              <span class="section-kicker">
                Dane formalne
              </span>
              <dl class="details-list">
                <div>
                  <dt>Kursant</dt>
                  <dd>{{ studentName }}</dd>
                </div>
                <div>
                  <dt>Kategoria</dt>
                  <dd>{{ generationSubject.course_category }}</dd>
                </div>
                <div>
                  <dt>Część</dt>
                  <dd>{{ partLabel(generationSubject.exam_part) }}</dd>
                </div>
                <div>
                  <dt>PKK</dt>
                  <dd>{{ coursePkk(generationSubject) }}</dd>
                </div>
                <div>
                  <dt>PESEL</dt>
                  <dd>{{ peselMasked ?? 'Brak / nieuzupełniony' }}</dd>
                </div>
                <div>
                  <dt>E-mail</dt>
                  <dd>{{ studentEmail ?? '—' }}</dd>
                </div>
              </dl>
              <p class="module-note">
                PKK i PESEL pochodzą z trwałego profilu/kursu i nie są edytowane z poziomu snapshotu egzaminu.
              </p>
            </section>

            <section
              v-if="!generatedAttempt"
              class="drawer-section"
            >
              <label>
                Język egzaminu
                <select
                  v-model="languageCode"
                  :disabled="saving"
                >
                  <option
                    v-for="language in capability?.languages ?? []"
                    :key="language"
                    :value="language"
                  >
                    {{ language.toUpperCase() }}
                  </option>
                </select>
              </label>
              <div class="form-actions">
                <button
                  class="button ghost"
                  type="button"
                  @click="generationSubject = null; capability = null; languageCode = ''"
                >
                  Zmień kategorię
                </button>
                <button
                  class="button primary"
                  type="button"
                  :disabled="saving || !languageCode || (inventory?.summary.available_total ?? 0) < 1"
                  @click="createAttempt"
                >
                  {{ saving ? 'Tworzenie…' : 'Utwórz próbę' }}
                </button>
              </div>
            </section>

            <template v-else>
              <section class="drawer-section">
                <span class="section-kicker">
                  Dane na egzamin
                </span>
                <div class="form-grid compact-form-grid">
                  <label>
                    Imię
                    <input
                      v-model="candidateForm.first_name"
                      maxlength="120"
                    >
                  </label>
                  <label>
                    Nazwisko
                    <input
                      v-model="candidateForm.last_name"
                      maxlength="120"
                    >
                  </label>
                  <label>
                    E-mail
                    <input
                      v-model="candidateForm.contact_email"
                      type="email"
                      maxlength="320"
                    >
                  </label>
                  <label>
                    Data urodzenia
                    <input
                      v-model="candidateForm.birth_date"
                      type="date"
                    >
                  </label>
                  <label class="check full">
                    <input
                      v-model="candidateForm.no_pesel_declared"
                      type="checkbox"
                    >
                    Brak numeru PESEL
                  </label>
                </div>
                <div class="form-actions">
                  <button
                    class="button ghost"
                    type="button"
                    :disabled="saving"
                    @click="saveCandidateSnapshot"
                  >
                    Zapisz dane próby
                  </button>
                </div>
              </section>

              <section class="drawer-section">
                <span class="section-kicker">
                  Sposób przeprowadzenia
                </span>
                <div class="exam-launch-options student-launch-options">
                  <label>
                    <input
                      v-model="launchMode"
                      type="radio"
                      value="remote_link"
                    >
                    <strong>Link zdalny</strong>
                    <span>Kandydat otwiera jednorazowy link na własnym urządzeniu z internetem.</span>
                  </label>
                  <label>
                    <input
                      v-model="launchMode"
                      type="radio"
                      value="local_current_workstation"
                    >
                    <strong>Ta stacja</strong>
                    <span>Egzamin ruszy na bieżącej, uwierzytelnionej stacji egzaminacyjnej.</span>
                  </label>
                </div>

                <template v-if="launchMode === 'remote_link'">
                  <div class="form-actions">
                    <button
                      class="button primary"
                      type="button"
                      :disabled="saving || Boolean(createdAccess)"
                      @click="createRemoteAccess"
                    >
                      {{ saving ? 'Generowanie…' : 'Generuj link' }}
                    </button>
                  </div>
                  <div
                    v-if="oneTimeRemoteUrl"
                    class="secret-handoff exam-link-handoff"
                  >
                    <span>Jednorazowy link do przekazania kursantowi</span>
                    <input
                      :value="oneTimeRemoteUrl"
                      readonly
                    >
                    <small>Po zamknięciu widoku jawny token nie może zostać odtworzony z bazy.</small>
                    <button
                      class="button ghost"
                      type="button"
                      @click="copyRemoteUrl"
                    >
                      Kopiuj link
                    </button>
                  </div>
                </template>

                <template v-else>
                  <label>
                    Poświadczenie stacji
                    <input
                      v-model="stationCredential"
                      type="password"
                      autocomplete="off"
                      placeholder="Wklej aktualne poświadczenie urządzenia"
                    >
                  </label>
                  <p class="module-note">
                    Poświadczenie jest używane tylko do bieżącego żądania i nie trafia do domenowego snapshotu.
                  </p>
                  <div class="form-actions">
                    <button
                      class="button primary"
                      type="button"
                      :disabled="saving"
                      @click="startLocal"
                    >
                      {{ saving ? 'Uruchamianie…' : 'Uruchom na tej stacji' }}
                    </button>
                  </div>
                </template>
              </section>
            </template>
          </template>
        </div>
      </section>
    </div>

    <div
      v-if="detailsOpen && selectedAttempt"
      class="drawer-backdrop"
      @click.self="detailsOpen = false"
    >
      <section
        class="drawer exam-drawer"
        role="dialog"
        aria-modal="true"
        aria-label="Szczegóły egzaminu kursanta"
      >
        <header class="drawer-header">
          <div>
            <span class="section-kicker">
              Próba #{{ selectedAttempt.course_attempt_sequence }}
            </span>
            <h2>
              Szczegóły egzaminu
            </h2>
          </div>
          <button
            class="icon-button"
            type="button"
            aria-label="Zamknij"
            @click="detailsOpen = false"
          >
            ×
          </button>
        </header>

        <section class="drawer-section">
          <dl class="details-list">
            <div>
              <dt>Status</dt>
              <dd>{{ statusLabel(selectedAttempt.status) }}</dd>
            </div>
            <div>
              <dt>Kategoria</dt>
              <dd>{{ selectedAttempt.driving_category_code }}</dd>
            </div>
            <div>
              <dt>Część</dt>
              <dd>{{ partLabel(selectedAttempt.exam_part) }}</dd>
            </div>
            <div>
              <dt>Język</dt>
              <dd>{{ selectedAttempt.language_code.toUpperCase() }}</dd>
            </div>
            <div>
              <dt>Start</dt>
              <dd>{{ formatDate(selectedAttempt.started_at) }}</dd>
            </div>
            <div>
              <dt>Koniec</dt>
              <dd>{{ formatDate(selectedAttempt.finished_at) }}</dd>
            </div>
          </dl>
        </section>

        <section
          v-if="selectedResult"
          class="drawer-section"
        >
          <span class="section-kicker">
            Wynik
          </span>
          <div
            class="exam-result-card"
            :class="{ passed: selectedResult.passed }"
          >
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

        <section
          v-if="selectedQuestions.length > 0"
          class="drawer-section"
        >
          <span class="section-kicker">
            Przegląd pytań
          </span>
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

        <p
          v-if="!selectedResult"
          class="module-note"
        >
          Wynik i przegląd pytań pojawią się po zakończeniu tej próby.
        </p>
      </section>
    </div>
  </section>
</template>
