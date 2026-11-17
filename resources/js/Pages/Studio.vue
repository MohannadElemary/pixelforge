<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import AppHeader from '../Components/AppHeader.vue';
import { compareMoney, money } from '../lib/money';
const props = defineProps({ generations: Object, configured: Boolean, creditOptions: Array });
const page = usePage();
const wallet = computed(() => page.props.wallet);
const creditsOpen = ref(false);
const purchase = useForm({
    amount: props.creditOptions?.[0]?.amount || '',
    request_id: crypto.randomUUID(),
});
const purchaseSuccess = ref('');
const form = useForm({
    prompt: '',
    size: '1024x1024',
    quality: 'medium',
    reference_images: [],
    request_id: crypto.randomUUID(),
});
const referenceText = ref('');
const estimate = ref(null);
const estimateError = ref('');
const estimating = ref(false);
const brokenImages = ref({});
const referencesOpen = ref(false);
const sizes = [
    { value: '1024x1024', name: 'Square', shape: 'square' },
    { value: '1536x1024', name: 'Landscape', shape: 'landscape' },
    { value: '1024x1536', name: 'Portrait', shape: 'portrait' },
];
let timer,
    poll,
    estimateController,
    version = 0;
const references = computed(() =>
    referenceText.value
        .split('\n')
        .map((s) => s.trim())
        .filter(Boolean),
);
const input = computed(() => ({
    prompt: form.prompt,
    size: form.size,
    quality: form.quality,
    reference_images: references.value,
}));
const date = (value) =>
    new Intl.DateTimeFormat(undefined, {
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    }).format(new Date(value));
const safeUrl = (url) => (/^https?:\/\//i.test(url || '') ? url : null);
const hasPending = computed(
    () =>
        compareMoney(wallet.value?.reserved, '0') === 1 ||
        props.generations.data.some((g) => !['succeeded', 'failed'].includes(g.status)),
);
const canAfford = computed(() => {
    const comparison = compareMoney(wallet.value?.available, estimate.value?.reservation);
    return comparison !== null && comparison >= 0;
});
watch(
    input,
    () => {
        clearTimeout(timer);
        estimateController?.abort();
        version++;
        estimate.value = null;
        estimateError.value = '';
        estimating.value = false;
        if (!form.prompt.trim()) return;
        estimating.value = true;
        const current = version;
        timer = setTimeout(async () => {
            estimateController = new AbortController();
            try {
                const result = await axios.post('/estimate', input.value, {
                    signal: estimateController.signal,
                });
                if (current === version) estimate.value = result.data;
            } catch (error) {
                if (current === version && error.code !== 'ERR_CANCELED')
                    estimateError.value =
                        error.response?.data?.message ||
                        'Could not load pricing. Edit your prompt to try again.';
            } finally {
                if (current === version) estimating.value = false;
            }
        }, 400);
    },
    { deep: true },
);
function submit() {
    if (!estimate.value || !canAfford.value || !props.configured || form.processing) return;
    form.reference_images = references.value;
    form.post('/generations', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('prompt');
            referenceText.value = '';
            form.request_id = crypto.randomUUID();
        },
    });
}
function buyCredits() {
    if (purchase.processing) return;
    purchaseSuccess.value = '';
    purchase.post('/credits/purchases', {
        preserveScroll: true,
        onSuccess: () => {
            purchase.request_id = crypto.randomUUID();
            purchaseSuccess.value =
                page.props.flash?.success || 'Simulated credits added to your balance.';
        },
    });
}
async function openCredits() {
    creditsOpen.value = true;
    await nextTick();
    document.querySelector('#purchase-credits input')?.focus({ preventScroll: true });
    document.getElementById('credits')?.scrollIntoView({ block: 'center' });
}
onMounted(() => {
    poll = setInterval(() => {
        if (hasPending.value && document.visibilityState === 'visible')
            router.reload({ only: ['generations', 'wallet'] });
    }, 5000);
});
onUnmounted(() => {
    clearInterval(poll);
    clearTimeout(timer);
    estimateController?.abort();
});
</script>

<template>
    <Head title="Studio" />
    <div class="studio-page">
        <AppHeader />
        <main class="studio-main">
            <div class="page-heading">
                <div>
                    <span class="eyebrow">FROM A THOUGHT TO A FRAME</span>
                    <h1>Make room for an <em>idea.</em></h1>
                </div>
                <span class="workspace-tag"><i></i> Personal workspace</span>
            </div>
            <section id="credits" class="wallet-panel" aria-label="Your credits">
                <div class="wallet-overview">
                    <dl class="wallet-amounts">
                        <div>
                            <dt>Available credits</dt>
                            <dd>{{ money(wallet?.available) }}</dd>
                        </div>
                        <div>
                            <dt>Total balance</dt>
                            <dd>{{ money(wallet?.balance) }}</dd>
                        </div>
                        <div>
                            <dt>Reserved for images</dt>
                            <dd>{{ money(wallet?.reserved) }}</dd>
                        </div>
                    </dl>
                    <button
                        class="secondary-button"
                        type="button"
                        :aria-expanded="creditsOpen"
                        aria-controls="purchase-credits"
                        @click="creditsOpen = !creditsOpen"
                    >
                        Buy credits <span aria-hidden="true">{{ creditsOpen ? '−' : '+' }}</span>
                    </button>
                </div>
                <p class="wallet-note">
                    Reserved credits are held while images finish. Only successful images are
                    charged.
                </p>
                <form
                    v-if="creditsOpen"
                    id="purchase-credits"
                    class="purchase-form"
                    @submit.prevent="buyCredits"
                >
                    <div class="purchase-intro">
                        <h2>Make room for more ideas.</h2>
                        <p>Simulated purchase. No real payment is taken.</p>
                    </div>
                    <fieldset :disabled="purchase.processing">
                        <legend>Choose an amount in USD</legend>
                        <div class="credit-options">
                            <label
                                v-for="option in creditOptions"
                                :key="option.amount"
                                :class="{ selected: purchase.amount === option.amount }"
                            >
                                <input
                                    v-model="purchase.amount"
                                    type="radio"
                                    name="credit-amount"
                                    :value="option.amount"
                                    required
                                />
                                {{ option.label }}
                            </label>
                        </div>
                    </fieldset>
                    <button
                        class="button primary purchase-button"
                        :disabled="purchase.processing || !purchase.amount"
                    >
                        {{ purchase.processing ? 'Adding credits…' : 'Add simulated credits'
                        }}<span aria-hidden="true">↗</span>
                    </button>
                    <p
                        v-for="(error, key) in purchase.errors"
                        :key="key"
                        class="field-error"
                        role="alert"
                    >
                        {{ error }}
                    </p>
                </form>
                <p v-if="purchaseSuccess" class="success-notice" role="status">
                    {{ purchaseSuccess }}
                </p>
            </section>
            <div class="workspace">
                <aside class="create-panel">
                    <div class="panel-heading">
                        <h2>New image</h2>
                        <span>01 / CREATE</span>
                    </div>
                    <div v-if="!configured" class="service-notice" role="status">
                        <span class="notice-dot"></span>
                        <div>
                            <strong>Generation is not connected yet</strong>
                            <p>
                                You can explore the studio and live pricing. Image creation will be
                                available once the service is connected.
                            </p>
                        </div>
                    </div>
                    <form @submit.prevent="submit">
                        <label class="prompt-label" for="prompt"
                            >Your prompt <span>{{ [...form.prompt].length }} / 4,000</span></label
                        >
                        <textarea
                            id="prompt"
                            v-model="form.prompt"
                            maxlength="4000"
                            rows="5"
                            placeholder="A quiet glass house in a misty forest, soft morning light, muted greens…"
                            required
                        ></textarea>
                        <p v-if="form.errors.prompt" class="field-error" role="alert">
                            {{ form.errors.prompt }}
                        </p>
                        <fieldset>
                            <legend>Aspect ratio</legend>
                            <div class="size-options">
                                <button
                                    v-for="size in sizes"
                                    :key="size.value"
                                    type="button"
                                    :class="['size-option', { selected: form.size === size.value }]"
                                    :aria-pressed="form.size === size.value"
                                    @click="form.size = size.value"
                                >
                                    <span :class="['shape', size.shape]"></span
                                    ><strong>{{ size.name }}</strong
                                    ><small>{{ size.value.replace('x', ' × ') }}</small>
                                </button>
                            </div>
                        </fieldset>
                        <fieldset>
                            <legend>Quality <span>More detail, more tokens</span></legend>
                            <div class="quality-options">
                                <button
                                    v-for="quality in ['low', 'medium', 'high']"
                                    :key="quality"
                                    type="button"
                                    :class="{ selected: form.quality === quality }"
                                    :aria-pressed="form.quality === quality"
                                    @click="form.quality = quality"
                                >
                                    {{ quality }}
                                </button>
                            </div>
                        </fieldset>
                        <div class="reference-section">
                            <button
                                type="button"
                                class="reference-toggle"
                                :aria-expanded="referencesOpen"
                                @click="referencesOpen = !referencesOpen"
                            >
                                <span
                                    ><span class="reference-icon">↗</span> Reference images
                                    <small>Optional</small></span
                                ><span>{{ referencesOpen ? '−' : '+' }}</span>
                            </button>
                            <div v-if="referencesOpen" class="reference-fields">
                                <label for="references">Image URLs</label
                                ><textarea
                                    id="references"
                                    v-model="referenceText"
                                    rows="3"
                                    placeholder="https://example.com/image.png"
                                ></textarea>
                                <p class="hint">
                                    Up to 4 http(s) image URLs, one per line. Each adds input
                                    tokens.
                                </p>
                            </div>
                        </div>
                        <p
                            v-for="(error, key) in form.errors"
                            :key="key"
                            v-show="key !== 'prompt'"
                            class="field-error"
                        >
                            {{ error }}
                        </p>
                        <div class="estimate-box" aria-live="polite">
                            <div class="estimate-top">
                                <span>Estimated cost</span
                                ><strong
                                    >{{
                                        estimating
                                            ? 'Calculating…'
                                            : estimate
                                              ? money(estimate.typical)
                                              : '—'
                                    }}
                                    <small v-if="estimate">USD</small></strong
                                >
                            </div>
                            <p v-if="estimate" class="estimate-range">
                                Expected range {{ money(estimate.min) }}–{{ money(estimate.max) }}
                            </p>
                            <p v-if="estimate" class="estimate-range">
                                Credits reserved {{ money(estimate.reservation) }}
                            </p>
                            <p v-else-if="estimateError" class="field-error">{{ estimateError }}</p>
                            <p v-else-if="!estimating" class="hint">
                                Add a prompt to see your live estimate.
                            </p>
                            <p class="estimate-note">
                                Includes 25% markup. We reserve the maximum estimate, then charge
                                actual token use. Unused credits return to your available balance.
                                Failed images are free.
                            </p>
                            <div
                                v-if="estimate && !canAfford"
                                class="insufficient-credits"
                                role="status"
                            >
                                <p>You need enough available credits to cover this reservation.</p>
                                <button type="button" class="text-button" @click="openCredits">
                                    Buy credits ↗
                                </button>
                            </div>
                        </div>
                        <button
                            class="button primary generate-button"
                            :disabled="!configured || !estimate || !canAfford || form.processing"
                        >
                            {{ form.processing ? 'Adding to your studio…' : 'Generate image'
                            }}<span aria-hidden="true">↗</span>
                        </button>
                        <p class="below-button">
                            A little patience. Usually ready in under a minute.
                        </p>
                    </form>
                </aside>
                <section class="gallery-panel" aria-labelledby="gallery-title">
                    <div class="gallery-heading">
                        <div>
                            <h2 id="gallery-title">
                                Your collection <span class="count">{{ generations.total }}</span>
                            </h2>
                            <p>Every idea, in one place.</p>
                        </div>
                        <span class="live-label"
                            ><i :class="{ pulsing: hasPending }"></i
                            >{{ hasPending ? 'Updating live' : 'All caught up' }}</span
                        >
                    </div>
                    <div v-if="!generations.data.length" class="empty-gallery">
                        <div class="empty-art" aria-hidden="true">
                            <div class="empty-frame back"></div>
                            <div class="empty-frame front">
                                <svg width="100" height="100" viewBox="0 0 100 100" fill="none">
                                    <circle
                                        cx="65"
                                        cy="30"
                                        r="12"
                                        fill="currentColor"
                                        opacity=".55"
                                    />
                                    <path
                                        d="M8 81L36 42L58 69L72 50L94 81H8Z"
                                        fill="currentColor"
                                        opacity=".7"
                                    />
                                </svg>
                            </div>
                            <span class="art-spark">✳</span>
                        </div>
                        <span class="eyebrow">A BLANK CANVAS, FOR NOW</span>
                        <h3>Your first idea belongs here.</h3>
                        <p>
                            Describe what you have in mind.<br />We’ll save every image and its
                            exact cost here.
                        </p>
                        <span class="empty-arrow" aria-hidden="true">↖</span>
                    </div>
                    <div v-else class="generation-grid">
                        <article
                            v-for="generation in generations.data"
                            :key="generation.id"
                            class="generation-card"
                        >
                            <div class="image-stage" :class="generation.status">
                                <img
                                    v-if="
                                        generation.status === 'succeeded' &&
                                        safeUrl(generation.image_url) &&
                                        !brokenImages[generation.id]
                                    "
                                    :src="safeUrl(generation.image_url)"
                                    :alt="generation.prompt"
                                    loading="lazy"
                                    @error="brokenImages[generation.id] = true"
                                />
                                <div v-else class="image-placeholder">
                                    <span
                                        :class="{
                                            'working-symbol': !['succeeded', 'failed'].includes(
                                                generation.status,
                                            ),
                                        }"
                                        >{{
                                            generation.status === 'failed'
                                                ? '↗'
                                                : generation.status === 'succeeded'
                                                  ? '▧'
                                                  : '✳'
                                        }}</span
                                    ><strong>{{
                                        generation.status === 'succeeded'
                                            ? 'Image link has expired'
                                            : generation.status === 'failed'
                                              ? 'A new idea awaits'
                                              : generation.needs_attention
                                                ? 'Waiting for confirmation'
                                                : generation.status === 'processing'
                                                  ? 'Taking shape…'
                                                  : 'Your idea is in line'
                                    }}</strong
                                    ><small v-if="generation.status === 'succeeded'"
                                        >Images are available for 24 hours.</small
                                    >
                                </div>
                                <span :class="['status-pill', generation.status]">{{
                                    generation.status
                                }}</span>
                                <a
                                    v-if="
                                        generation.status === 'succeeded' &&
                                        safeUrl(generation.image_url) &&
                                        !brokenImages[generation.id]
                                    "
                                    class="image-open"
                                    :href="safeUrl(generation.image_url)"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    aria-label="Open full image"
                                    >↗</a
                                >
                            </div>
                            <div class="card-body">
                                <p class="card-prompt">{{ generation.prompt }}</p>
                                <div class="card-meta">
                                    <span
                                        >{{ generation.size.replace('x', ' × ') }} ·
                                        {{ generation.quality }}</span
                                    ><time :datetime="generation.created_at">{{
                                        date(generation.created_at)
                                    }}</time>
                                </div>
                                <p v-if="generation.message" class="generation-message">
                                    {{ generation.message }}
                                </p>
                                <p
                                    v-if="
                                        generation.reserved_cost &&
                                        !generation.wallet_settled_at &&
                                        !['succeeded', 'failed'].includes(generation.status)
                                    "
                                    class="generation-message"
                                >
                                    {{ money(generation.reserved_cost) }} reserved until this image
                                    finishes.
                                </p>
                                <Link
                                    v-if="generation.needs_attention"
                                    :href="`/generations/${generation.id}/retry`"
                                    method="post"
                                    as="button"
                                    class="check-button"
                                    preserve-scroll
                                    >Check again ↗</Link
                                >
                                <div class="card-costs">
                                    <div>
                                        <span>ESTIMATED</span
                                        ><strong>{{ money(generation.estimate.typical) }}</strong>
                                    </div>
                                    <div>
                                        <span>{{
                                            generation.status === 'failed'
                                                ? 'NO CHARGE'
                                                : 'ACTUAL COST'
                                        }}</span
                                        ><strong>{{ money(generation.actual_cost) }}</strong>
                                    </div>
                                </div>
                                <details v-if="generation.usage" class="usage-details">
                                    <summary>Token breakdown <span>+</span></summary>
                                    <dl>
                                        <template
                                            v-for="(label, key) in {
                                                input_text_tokens: 'Input text',
                                                input_image_tokens: 'Reference images',
                                                output_tokens: 'Output',
                                                reasoning_tokens: 'Reasoning',
                                            }"
                                            :key="key"
                                            ><dt>{{ label }}</dt>
                                            <dd>
                                                {{ generation.usage[key].toLocaleString() }} × ${{
                                                    generation.rates[key]
                                                }}
                                                / 1M
                                            </dd></template
                                        >
                                        <dt>Provider cost</dt>
                                        <dd>{{ money(generation.provider_cost) }}</dd>
                                        <dt>Your cost, including 25%</dt>
                                        <dd>{{ money(generation.actual_cost) }}</dd>
                                    </dl>
                                </details>
                            </div>
                        </article>
                    </div>
                    <nav
                        v-if="generations.last_page > 1"
                        class="pagination"
                        aria-label="Collection pages"
                    >
                        <Link
                            v-if="generations.prev_page_url"
                            :href="generations.prev_page_url"
                            preserve-state
                            >← Previous</Link
                        ><span>{{ generations.current_page }} / {{ generations.last_page }}</span
                        ><Link
                            v-if="generations.next_page_url"
                            :href="generations.next_page_url"
                            preserve-state
                            >Next →</Link
                        >
                    </nav>
                    <div class="gallery-foot">
                        <span>YOURS TO IMAGINE.</span><span>Images live here for 24 hours.</span>
                    </div>
                </section>
            </div>
        </main>
        <footer class="site-footer">
            <span>pixelforge.</span><span>A small space for big ideas.</span
            ><span>USD · Exact token pricing</span>
        </footer>
    </div>
</template>
