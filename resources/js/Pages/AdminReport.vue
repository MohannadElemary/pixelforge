<script setup>
import { computed, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppHeader from '../Components/AppHeader.vue';
import { compareMoney, money } from '../lib/money';

const props = defineProps({ filters: Object, report: Object, reconciliation: Object });
const filter = useForm({
    start_date: props.filters.start_date,
    end_date: props.filters.end_date,
    scope: props.filters.scope,
});
watch(
    () => props.filters,
    (value) => {
        filter.start_date = value.start_date;
        filter.end_date = value.end_date;
        filter.scope = value.scope;
    },
);
const allHistory = computed(() => props.filters.scope === 'all');
const scopeLabel = computed(() =>
    allHistory.value ? 'All recorded history' : 'Current environment key',
);
const status = computed(
    () =>
        ({
            matched: 'Matched',
            mismatch: 'Difference found',
            unavailable: 'Usage check unavailable',
            unconfigured: 'Usage check not connected',
        })[props.reconciliation.status] || 'Usage check unavailable',
);
const metrics = computed(() => [
    {
        key: 'generations',
        label: 'Completed images',
        hint: 'Successful generations in this period',
    },
    {
        key: 'credit_spend',
        label: allHistory.value ? 'Recorded customer spend' : 'Customer spend',
        hint: allHistory.value
            ? 'Recorded image prices, including older images'
            : 'Credits charged for completed images',
    },
    { key: 'provider_cost', label: 'Provider cost', hint: 'Recorded cost of completed images' },
    { key: 'profit', label: 'Profit', hint: 'Customer spend less provider cost' },
]);
const count = (value) => value.toLocaleString();
const day = (value) =>
    new Intl.DateTimeFormat(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        timeZone: 'UTC',
    }).format(new Date(value + 'T00:00:00Z'));
const checkedAt = computed(() =>
    props.reconciliation.checked_at
        ? new Intl.DateTimeFormat(undefined, {
              month: 'short',
              day: 'numeric',
              hour: '2-digit',
              minute: '2-digit',
              timeZone: 'UTC',
          }).format(new Date(props.reconciliation.checked_at)) + ' UTC'
        : null,
);
function applyFilters() {
    filter.get('/admin/reports', { preserveState: true, preserveScroll: true });
}
</script>

<template>
    <Head title="Reports" />
    <div class="reports-page">
        <AppHeader active="reports" />
        <main class="studio-main report-main">
            <div class="page-heading">
                <div>
                    <span class="eyebrow">A CLEARER PICTURE</span>
                    <h1>Your studio, <em>in numbers.</em></h1>
                </div>
                <span class="workspace-tag"><i></i> Admin reports · USD · UTC</span>
            </div>

            <section class="report-filter-panel" aria-labelledby="period-title">
                <div>
                    <h2 id="period-title">Reporting period</h2>
                    <p>Both dates included. Up to 92 days, in UTC.</p>
                </div>
                <form class="report-filters" @submit.prevent="applyFilters">
                    <label for="start-date"
                        >From
                        <input
                            id="start-date"
                            v-model="filter.start_date"
                            type="date"
                            :max="filter.end_date || undefined"
                            required
                            :aria-invalid="Boolean(filter.errors.start_date)"
                            aria-describedby="report-date-errors"
                        />
                    </label>
                    <label for="end-date"
                        >Through
                        <input
                            id="end-date"
                            v-model="filter.end_date"
                            type="date"
                            :min="filter.start_date || undefined"
                            required
                            :aria-invalid="Boolean(filter.errors.end_date)"
                            aria-describedby="report-date-errors"
                        />
                    </label>
                    <label for="report-scope" class="report-scope-filter"
                        >Report scope
                        <select
                            id="report-scope"
                            v-model="filter.scope"
                            :aria-invalid="Boolean(filter.errors.scope)"
                            aria-describedby="report-date-errors"
                        >
                            <option value="current">Current environment key</option>
                            <option value="all">All recorded history</option>
                        </select>
                    </label>
                    <button class="button primary" :disabled="filter.processing">
                        {{ filter.processing ? 'Loading…' : 'Apply filters'
                        }}<span aria-hidden="true">↗</span>
                    </button>
                    <div id="report-date-errors" class="report-date-errors" aria-live="polite">
                        <p v-for="(error, key) in filter.errors" :key="key" class="field-error">
                            {{ error }}
                        </p>
                    </div>
                </form>
            </section>

            <p class="report-period">
                <strong>{{ scopeLabel }}</strong> · {{ day(filters.start_date) }} –
                {{ day(filters.end_date) }} · USD · UTC completion dates
            </p>
            <p class="report-scope-note">
                {{
                    allHistory
                        ? 'Totals include every recorded key in this period. Customer spend shows recorded image prices; older images may predate wallet charges.'
                        : 'Totals and activity include only images recorded for the current environment key. Other keys and older records without a key match are excluded.'
                }}
            </p>
            <section class="report-metrics" aria-label="Period totals">
                <article v-for="metric in metrics" :key="metric.key" class="report-metric">
                    <h2>{{ metric.label }}</h2>
                    <p>
                        {{
                            metric.key === 'generations'
                                ? count(report.totals[metric.key])
                                : money(report.totals[metric.key])
                        }}
                    </p>
                    <span>{{ metric.hint }}</span>
                </article>
            </section>

            <section class="report-section" aria-labelledby="daily-title">
                <div class="report-section-heading">
                    <div>
                        <h2 id="daily-title">Day by day</h2>
                        <p>Successful images, grouped by completion date in UTC.</p>
                    </div>
                    <span class="report-unit">USD</span>
                </div>
                <div
                    v-if="report.days.length"
                    class="table-scroll"
                    role="region"
                    aria-label="Daily totals"
                    tabindex="0"
                >
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th scope="col">Date (UTC)</th>
                                <th scope="col" class="numeric">Images</th>
                                <th scope="col" class="numeric">Customer spend</th>
                                <th scope="col" class="numeric">Provider cost</th>
                                <th scope="col" class="numeric">Profit</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in report.days" :key="row.date">
                                <th scope="row">
                                    <time :datetime="row.date">{{ day(row.date) }}</time>
                                </th>
                                <td class="numeric">{{ count(row.generations) }}</td>
                                <td class="numeric">{{ money(row.credit_spend) }}</td>
                                <td class="numeric">{{ money(row.provider_cost) }}</td>
                                <td class="numeric">{{ money(row.profit) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-else class="report-empty">No completed images in this period.</p>
            </section>

            <section class="report-section" aria-labelledby="customers-title">
                <div class="report-section-heading">
                    <div>
                        <h2 id="customers-title">Customer activity</h2>
                        <p>One row per customer, per UTC completion day.</p>
                    </div>
                    <span class="report-unit">{{ count(report.entries.total) }} rows · USD</span>
                </div>
                <div
                    v-if="report.entries.data.length"
                    class="table-scroll"
                    role="region"
                    aria-label="Customer activity"
                    tabindex="0"
                >
                    <table class="report-table customer-table">
                        <thead>
                            <tr>
                                <th scope="col">Date (UTC)</th>
                                <th scope="col">Customer</th>
                                <th scope="col" class="numeric">Images</th>
                                <th scope="col" class="numeric">Customer spend</th>
                                <th scope="col" class="numeric">Provider cost</th>
                                <th scope="col" class="numeric">Profit</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in report.entries.data"
                                :key="`${row.date}-${row.user_id}`"
                            >
                                <th scope="row">
                                    <time :datetime="row.date">{{ day(row.date) }}</time>
                                </th>
                                <td class="customer-cell">
                                    <strong>{{ row.name }}</strong
                                    ><span>{{ row.email }}</span>
                                </td>
                                <td class="numeric">{{ count(row.generations) }}</td>
                                <td class="numeric">{{ money(row.credit_spend) }}</td>
                                <td class="numeric">{{ money(row.provider_cost) }}</td>
                                <td class="numeric">{{ money(row.profit) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-else class="report-empty">
                    Customer activity will appear here after images finish.
                </p>
                <nav
                    v-if="report.entries.last_page > 1"
                    class="pagination report-pagination"
                    aria-label="Customer activity pages"
                >
                    <Link
                        v-if="report.entries.prev_page_url"
                        :href="report.entries.prev_page_url"
                        preserve-state
                        preserve-scroll
                        >← Previous</Link
                    >
                    <span>{{ report.entries.current_page }} / {{ report.entries.last_page }}</span>
                    <Link
                        v-if="report.entries.next_page_url"
                        :href="report.entries.next_page_url"
                        preserve-state
                        preserve-scroll
                        >Next →</Link
                    >
                </nav>
            </section>

            <section
                class="report-section reconciliation-section"
                aria-labelledby="reconciliation-title"
            >
                <div class="report-section-heading">
                    <div>
                        <h2 id="reconciliation-title">
                            Provider usage check · Current environment key
                        </h2>
                        <p>
                            This comparison always uses the current environment key, regardless of
                            the report scope above.
                        </p>
                    </div>
                    <span :class="['reconciliation-status', reconciliation.status]">{{
                        status
                    }}</span>
                </div>
                <div class="reconciliation-body">
                    <p class="reconciliation-message" role="status">{{ reconciliation.message }}</p>
                    <p
                        v-if="['unavailable', 'unconfigured'].includes(reconciliation.status)"
                        class="hint"
                    >
                        Your recorded report totals above remain available.
                    </p>
                    <dl class="reconciliation-amounts">
                        <div>
                            <dt>Provider usage</dt>
                            <dd>{{ money(reconciliation.provider_cost) }}</dd>
                        </div>
                        <div>
                            <dt>Recorded for current key</dt>
                            <dd>{{ money(reconciliation.recorded_provider_cost) }}</dd>
                        </div>
                        <div>
                            <dt>Difference</dt>
                            <dd>{{ money(reconciliation.difference) }}</dd>
                        </div>
                    </dl>
                    <p class="hint">
                        Difference is provider usage minus recorded cost. A positive amount means
                        the provider reported more usage.
                    </p>
                    <p
                        v-if="compareMoney(reconciliation.unscoped_provider_cost, '0') === 1"
                        class="unscoped-notice"
                    >
                        {{ money(reconciliation.unscoped_provider_cost) }} of older recorded costs
                        has no API key match.
                        {{
                            allHistory
                                ? 'Included in the history totals above.'
                                : 'Excluded from the current key totals above.'
                        }}
                        Always excluded from this comparison.
                    </p>
                    <p
                        v-if="compareMoney(reconciliation.other_key_provider_cost, '0') === 1"
                        class="unscoped-notice"
                    >
                        {{ money(reconciliation.other_key_provider_cost) }} of recorded provider
                        costs belongs to other keys.
                        {{
                            allHistory
                                ? 'Included in the history totals above.'
                                : 'Excluded from the current key totals above.'
                        }}
                        Always excluded from this comparison.
                    </p>
                    <p v-if="checkedAt" class="reconciliation-checked">
                        Last checked {{ checkedAt }}
                    </p>
                    <details v-if="reconciliation.days.length" class="reconciliation-details">
                        <summary>Daily usage comparison</summary>
                        <div
                            class="table-scroll"
                            role="region"
                            aria-label="Daily provider comparison"
                            tabindex="0"
                        >
                            <table class="report-table">
                                <thead>
                                    <tr>
                                        <th scope="col">Date (UTC)</th>
                                        <th scope="col" class="numeric">Provider usage</th>
                                        <th scope="col" class="numeric">
                                            Recorded for current key
                                        </th>
                                        <th scope="col" class="numeric">Difference</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="row in reconciliation.days" :key="row.date">
                                        <th scope="row">{{ day(row.date) }}</th>
                                        <td class="numeric">{{ money(row.provider_cost) }}</td>
                                        <td class="numeric">
                                            {{ money(row.recorded_provider_cost) }}
                                        </td>
                                        <td class="numeric">{{ money(row.difference) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </details>
                </div>
            </section>
        </main>
        <footer class="site-footer">
            <span>pixelforge.</span><span>Every image, accounted for.</span
            ><span>USD · UTC reporting</span>
        </footer>
    </div>
</template>
