<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { ApiError, api } from '../ResourcesCore/api'

type LearningAccount = {
  id: string
  login_identifier: string
  language_code: string
  status: string
}

type Category = {
  id: string
  code: string
  label: string
  active: boolean
}

type MetricState = 'available' | 'no_activity' | 'unavailable' | 'outside_core_v1'

type TestMetrics = {
  state: MetricState
  passed_count: number | null
  failed_count: number | null
  conducted_count: number | null
  passed_percent: number | null
  failed_percent: number | null
}

type QuestionMetrics = {
  state: MetricState
  answered_count: number | null
  available_count: number | null
  total_attempts: number | null
  correct_attempts: number | null
  incorrect_attempts: number | null
  correct_percent: number | null
  incorrect_percent: number | null
}

type ContentMetrics = {
  state: MetricState
  completed_units: number | null
  total_units: number | null
  progress_percent: number | null
  completed_control_questions: number | null
  available_control_questions: number | null
  control_questions_percent: number | null
}

type Topic = {
  topic_key: string
  group: 'basic' | 'specialized'
  label: string
  label_language_code: string
  answered_count: number
  available_count: number
}

type Progress = {
  learning_account_id: string
  category_code: string
  projection_state: 'ready' | 'stale' | 'unbound' | 'source_unavailable'
  freshness: 'fresh' | 'stale' | 'unavailable'
  source_observed_at: string | null
  projected_at: string | null
  tests: TestMetrics
  questions: QuestionMetrics
  handbook: ContentMetrics
  lectures: ContentMetrics
  topics: Topic[]
}

const props = defineProps<{ studentId: string }>()

const accounts = ref<LearningAccount[]>([])
const categories = ref<Category[]>([])
const accountId = ref('')
const categoryCode = ref('')
const progress = ref<Progress | null>(null)
const loading = ref(false)
const error = ref('')

const basicTopics = computed(() => progress.value?.topics.filter((topic) => topic.group === 'basic') ?? [])
const specializedTopics = computed(() => progress.value?.topics.filter((topic) => topic.group === 'specialized') ?? [])

onMounted(loadSelectors)

watch([accountId, categoryCode], async ([account, category]) => {
  if (account && category) {
    await loadProgress()
  }
})

async function loadSelectors(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    const [accountResult, categoryResult] = await Promise.all([
      api<LearningAccount[]>(`/api/v1/students/${props.studentId}/learning-accounts`),
      api<Category[]>('/api/v1/driving-categories'),
    ])
    accounts.value = accountResult.data.filter((account) => account.status === 'active')
    categories.value = categoryResult.data.filter((category) => category.active && category.code !== 'PT')
    accountId.value = accounts.value[0]?.id ?? ''
    categoryCode.value = categories.value.find((category) => category.code === 'B')?.code ?? categories.value[0]?.code ?? ''
    if (!accountId.value || !categoryCode.value) {
      progress.value = null
    }
  } catch (caught: unknown) {
    handleError(caught)
  } finally {
    loading.value = false
  }
}

async function loadProgress(): Promise<void> {
  if (!accountId.value || !categoryCode.value) return
  loading.value = true
  error.value = ''
  try {
    const query = new URLSearchParams({
      learning_account_id: accountId.value,
      category: categoryCode.value,
    })
    progress.value = (
      await api<Progress>(`/api/v1/students/${props.studentId}/progress?${query.toString()}`)
    ).data
  } catch (caught: unknown) {
    progress.value = null
    handleError(caught)
  } finally {
    loading.value = false
  }
}

function percent(value: number | null): string {
  return value === null ? '—' : `${value}%`
}

function count(value: number | null): string {
  return value === null ? '—' : String(value)
}

function contentState(state: MetricState): string {
  if (state === 'outside_core_v1') return 'Poza zakresem Core V1'
  if (state === 'unavailable') return 'Brak wiarygodnych danych'
  if (state === 'no_activity') return 'Brak aktywności'
  return 'Dane dostępne'
}

function handleError(caught: unknown): void {
  error.value = caught instanceof ApiError ? caught.message : 'Nie udało się pobrać postępu kursanta.'
}
</script>

<template>
  <section class="progress-panel">
    <div class="progress-heading">
      <div>
        <span class="section-kicker">Postęp</span>
        <h2>Postępy kursanta</h2>
      </div>
      <div class="progress-selectors">
        <label>
          Konto nauki
          <select v-model="accountId">
            <option
              v-for="account in accounts"
              :key="account.id"
              :value="account.id"
            >
              {{ account.login_identifier }} · {{ account.language_code.toUpperCase() }}
            </option>
          </select>
        </label>
        <label>
          Kategoria
          <select v-model="categoryCode">
            <option
              v-for="category in categories"
              :key="category.id"
              :value="category.code"
            >
              {{ category.code }}
            </option>
          </select>
        </label>
      </div>
    </div>

    <p
      v-if="error"
      class="progress-message error"
    >
      {{ error }}
    </p>
    <p
      v-else-if="!accounts.length"
      class="progress-message"
    >
      Kursant nie ma jeszcze aktywnego konta nauki.
    </p>
    <p
      v-else-if="loading && !progress"
      class="progress-message"
    >
      Pobieranie postępu…
    </p>

    <template v-if="progress">
      <p
        v-if="progress.projection_state === 'unbound'"
        class="progress-message"
      >
        Konto nie jest jeszcze powiązane z autorytatywnym źródłem postępu.
      </p>
      <p
        v-else-if="progress.projection_state === 'source_unavailable'"
        class="progress-message"
      >
        Źródło postępu jest obecnie niedostępne. Nie zastępujemy brakujących danych zerami.
      </p>
      <p
        v-else-if="progress.projection_state === 'stale'"
        class="progress-message warning"
      >
        Pokazujemy ostatni wiarygodny zapis. Dane wymagają odświeżenia.
      </p>

      <div class="progress-kpis">
        <article class="progress-card">
          <span>Zdane testy</span>
          <strong>{{ count(progress.tests.passed_count) }}</strong>
          <small>{{ percent(progress.tests.passed_percent) }}</small>
        </article>
        <article class="progress-card">
          <span>Niezdane testy</span>
          <strong>{{ count(progress.tests.failed_count) }}</strong>
          <small>{{ percent(progress.tests.failed_percent) }}</small>
        </article>
        <article class="progress-card">
          <span>Przerobione pytania</span>
          <strong>{{ count(progress.questions.answered_count) }} / {{ count(progress.questions.available_count) }}</strong>
          <small>Poprawne {{ percent(progress.questions.correct_percent) }} · błędne {{ percent(progress.questions.incorrect_percent) }}</small>
        </article>
      </div>

      <div class="content-grid">
        <article class="progress-card content-card">
          <div>
            <span>Podręcznik</span>
            <strong>{{ percent(progress.handbook.progress_percent) }}</strong>
          </div>
          <small>{{ contentState(progress.handbook.state) }}</small>
          <small>Zaliczone: {{ count(progress.handbook.completed_units) }} / {{ count(progress.handbook.total_units) }}</small>
          <small>Pytania kontrolne: {{ percent(progress.handbook.control_questions_percent) }}</small>
        </article>
        <article class="progress-card content-card">
          <div>
            <span>Wykłady z lektorem</span>
            <strong>{{ percent(progress.lectures.progress_percent) }}</strong>
          </div>
          <small>{{ contentState(progress.lectures.state) }}</small>
          <small>Zaliczone: {{ count(progress.lectures.completed_units) }} / {{ count(progress.lectures.total_units) }}</small>
          <small>Pytania kontrolne: {{ percent(progress.lectures.control_questions_percent) }}</small>
        </article>
      </div>

      <section class="topic-section">
        <h3>Pytania podstawowe</h3>
        <div
          v-if="basicTopics.length"
          class="topic-list"
        >
          <div
            v-for="topic in basicTopics"
            :key="topic.topic_key"
            class="topic-row"
          >
            <span>{{ topic.label }}</span>
            <strong>{{ topic.answered_count }} / {{ topic.available_count }}</strong>
          </div>
        </div>
        <p
          v-else
          class="progress-message compact"
        >
          Brak danych tematycznych.
        </p>
      </section>

      <section class="topic-section">
        <h3>Pytania specjalistyczne</h3>
        <div
          v-if="specializedTopics.length"
          class="topic-list"
        >
          <div
            v-for="topic in specializedTopics"
            :key="topic.topic_key"
            class="topic-row"
          >
            <span>{{ topic.label }}</span>
            <strong>{{ topic.answered_count }} / {{ topic.available_count }}</strong>
          </div>
        </div>
        <p
          v-else
          class="progress-message compact"
        >
          Brak danych tematycznych.
        </p>
      </section>
    </template>
  </section>
</template>

<style scoped>
.progress-panel {
  display: grid;
  gap: 18px;
}
.progress-heading {
  display: flex;
  justify-content: space-between;
  gap: 18px;
  align-items: end;
}
.progress-heading h2 {
  margin: 4px 0 0;
}
.progress-selectors {
  display: flex;
  gap: 12px;
}
.progress-selectors label {
  display: grid;
  gap: 6px;
  font-size: 13px;
}
.progress-selectors select {
  min-width: 170px;
}
.progress-message {
  padding: 14px 16px;
  border: 1px solid #e4e4e7;
  border-radius: 12px;
  background: #fafafa;
}
.progress-message.warning {
  background: #fffbeb;
}
.progress-message.error {
  background: #fef2f2;
}
.progress-message.compact {
  margin: 0;
  padding: 10px 12px;
}
.progress-kpis,
.content-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 12px;
}
.content-grid {
  grid-template-columns: repeat(2, minmax(0, 1fr));
}
.progress-card {
  display: grid;
  gap: 6px;
  padding: 18px;
  border: 1px solid #e4e4e7;
  border-radius: 14px;
  background: #fff;
}
.progress-card > strong {
  font-size: 24px;
}
.progress-card small {
  color: #71717a;
}
.content-card > div,
.topic-row {
  display: flex;
  justify-content: space-between;
  gap: 12px;
}
.topic-section {
  display: grid;
  gap: 10px;
}
.topic-section h3 {
  margin: 0;
}
.topic-list {
  display: grid;
  gap: 8px;
}
.topic-row {
  padding: 10px 0;
  border-bottom: 1px solid #eeeeef;
}
@media (max-width: 900px) {
  .progress-heading,
  .progress-selectors {
    align-items: stretch;
    flex-direction: column;
  }
  .progress-kpis,
  .content-grid {
    grid-template-columns: 1fr;
  }
}
</style>
