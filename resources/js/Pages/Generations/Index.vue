<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { estimate, formatUsd } from '@/pricing.js';

const props = defineProps({
    pricing: Object,
    sizes: Array,
    qualities: Array,
    creditPacks: Array,
    balance: String,
    generations: Array,
});

const page = usePage();
const isAdmin = computed(() => page.props.auth.user?.is_admin);
const flash = computed(() => page.props.flash?.success);

const form = useForm({
    prompt: '',
    size: '1024x1024',
    quality: 'medium',
    reference_images: [],
});
const referenceText = ref('');

watch(referenceText, (value) => {
    form.reference_images = value.split('\n').map((s) => s.trim()).filter(Boolean);
});

const price = computed(() => {
    if (!props.pricing || !form.prompt) return null;
    const count = Math.min(form.reference_images.length, 4);
    return estimate(props.pricing, form.prompt, form.size, form.quality, count);
});

function submit() {
    form.post(route('generations.store'), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('prompt');
        },
    });
}

function buy(amount) {
    router.post(route('credits.buy'), { amount }, { preserveScroll: true, only: ['balance', 'flash'] });
}

const pending = computed(() => props.generations.some((g) => !['succeeded', 'failed'].includes(g.status)));
let timer = null;
onMounted(() => {
    timer = setInterval(() => {
        if (pending.value) {
            router.reload({ only: ['generations', 'balance'], preserveScroll: true });
        }
    }, 2500);
});
onBeforeUnmount(() => clearInterval(timer));

const statusStyle = {
    submitting: 'bg-gray-100 text-gray-700',
    queued: 'bg-amber-100 text-amber-800',
    processing: 'bg-blue-100 text-blue-800',
    succeeded: 'bg-green-100 text-green-800',
    failed: 'bg-red-100 text-red-800',
};
const statusLabel = {
    submitting: 'Sending',
    queued: 'Queued',
    processing: 'Processing',
    succeeded: 'Succeeded',
    failed: 'Failed',
};
</script>

<template>
    <Head title="Generate images" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">Generate images</h2>
                <div class="flex items-center gap-3 text-sm">
                    <Link v-if="isAdmin" :href="route('admin.report')" class="text-indigo-600 hover:underline">Admin report</Link>
                    <span class="rounded-full bg-gray-900 px-3 py-1 font-medium text-white">Balance {{ balance }}</span>
                    <button
                        v-for="amount in creditPacks"
                        :key="amount"
                        type="button"
                        class="rounded-md border border-gray-300 px-2.5 py-1 font-medium hover:bg-gray-50"
                        @click="buy(amount)"
                    >
                        + ${{ amount }}
                    </button>
                </div>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto grid max-w-6xl gap-6 px-4 sm:px-6 lg:grid-cols-5 lg:px-8">
                <form class="space-y-4 rounded-lg bg-white p-6 shadow-sm lg:col-span-2" @submit.prevent="submit">
                    <div v-if="flash" class="rounded-md bg-green-50 px-3 py-2 text-sm text-green-800">{{ flash }}</div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700" for="prompt">Prompt</label>
                        <textarea
                            id="prompt"
                            v-model="form.prompt"
                            rows="5"
                            maxlength="4000"
                            class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            placeholder="A lighthouse on a cliff at dawn, oil painting"
                        />
                        <div class="mt-1 flex justify-between text-xs text-gray-500">
                            <span>Tip: [fail], [slow] and [duplicate] trigger test behaviour.</span>
                            <span>{{ [...form.prompt].length }}/4000</span>
                        </div>
                        <p v-if="form.errors.prompt" class="mt-1 text-sm text-red-600">{{ form.errors.prompt }}</p>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700" for="size">Size</label>
                            <select id="size" v-model="form.size" class="mt-1 w-full rounded-md border-gray-300 shadow-sm">
                                <option v-for="s in sizes" :key="s" :value="s">{{ s }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700" for="quality">Quality</label>
                            <select id="quality" v-model="form.quality" class="mt-1 w-full rounded-md border-gray-300 shadow-sm capitalize">
                                <option v-for="q in qualities" :key="q" :value="q">{{ q }}</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700" for="refs">Reference image URLs (optional, up to 4, one per line)</label>
                        <textarea
                            id="refs"
                            v-model="referenceText"
                            rows="2"
                            class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm"
                            placeholder="https://example.com/style.png"
                        />
                        <p v-if="form.reference_images.length > 4" class="mt-1 text-sm text-red-600">At most 4 reference images.</p>
                        <p v-if="form.errors.reference_images" class="mt-1 text-sm text-red-600">{{ form.errors.reference_images }}</p>
                        <p v-for="(msg, key) in form.errors" v-show="key.startsWith('reference_images.')" :key="key" class="mt-1 text-sm text-red-600">{{ msg }}</p>
                    </div>

                    <div class="rounded-md bg-gray-50 p-3 text-sm">
                        <template v-if="!pricing">Pricing is unavailable right now.</template>
                        <template v-else-if="price">
                            <div class="flex justify-between font-medium text-gray-900">
                                <span>Estimated cost</span>
                                <span>~{{ formatUsd(price.expected) }}</span>
                            </div>
                            <div class="mt-1 text-gray-600">
                                Between {{ formatUsd(price.min) }} and {{ formatUsd(price.max) }}. We hold the maximum and refund the
                                difference when the image is done. Failed images are free.
                            </div>
                        </template>
                        <template v-else>Write a prompt to see the price.</template>
                    </div>

                    <button
                        type="submit"
                        :disabled="form.processing || !form.prompt || form.reference_images.length > 4"
                        class="w-full rounded-md bg-indigo-600 px-4 py-2 font-semibold text-white hover:bg-indigo-500 disabled:opacity-50"
                    >
                        {{ form.processing ? 'Submitting…' : price ? `Generate (up to ${formatUsd(price.max)})` : 'Generate' }}
                    </button>
                </form>

                <div class="space-y-3 lg:col-span-3">
                    <p v-if="!generations.length" class="rounded-lg bg-white p-6 text-gray-500 shadow-sm">No generations yet.</p>

                    <div v-for="g in generations" :key="g.id" class="flex gap-4 rounded-lg bg-white p-4 shadow-sm">
                        <div class="flex h-24 w-24 shrink-0 items-center justify-center overflow-hidden rounded-md bg-gray-100 text-center text-xs text-gray-400">
                            <a v-if="g.image_url" :href="g.image_url" target="_blank" rel="noopener">
                                <img :src="g.image_url" :alt="g.prompt" class="h-24 w-24 object-cover" />
                            </a>
                            <span v-else-if="g.image_expired">Image link expired</span>
                            <span v-else-if="g.status === 'failed'">No image</span>
                            <span v-else class="animate-pulse">Working…</span>
                        </div>

                        <div class="min-w-0 flex-1 text-sm">
                            <div class="flex items-start justify-between gap-2">
                                <p class="line-clamp-2 font-medium text-gray-900">{{ g.prompt }}</p>
                                <span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium" :class="statusStyle[g.status]">
                                    {{ statusLabel[g.status] }}
                                </span>
                            </div>
                            <p class="mt-1 text-gray-500">
                                {{ g.size }} · <span class="capitalize">{{ g.quality }}</span>
                                <template v-if="g.reference_images.length"> · {{ g.reference_images.length }} reference image(s)</template>
                            </p>
                            <p class="mt-1 text-gray-700">
                                Estimated {{ g.estimate.expected }} <span class="text-gray-400">({{ g.estimate.min }} to {{ g.estimate.max }})</span>
                                <template v-if="g.charge !== null">
                                    · <span class="font-semibold" :title="g.charge_exact">Charged {{ g.charge }}</span>
                                </template>
                            </p>
                            <p v-if="g.error" class="mt-1 text-red-600">{{ g.error.message || g.error.code }}<template v-if="!/not charged/i.test(g.error.message || '')"> You were not charged.</template></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
