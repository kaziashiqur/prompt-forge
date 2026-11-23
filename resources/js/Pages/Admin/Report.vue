<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    start: String,
    end: String,
    rows: Array,
    totals: Object,
    usageError: String,
});

const start = ref(props.start);
const end = ref(props.end);
const open = ref({});

function apply() {
    router.get(route('admin.report'), { start: start.value, end: end.value }, { preserveState: true });
}
</script>

<template>
    <Head title="Admin report" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">Credit spend, provider cost and profit</h2>
                <Link :href="route('generations.index')" class="text-sm text-indigo-600 hover:underline">Back to generating</Link>
            </div>
        </template>

        <div class="mx-auto max-w-6xl space-y-4 px-4 py-8 sm:px-6 lg:px-8">
            <form class="flex flex-wrap items-end gap-3 rounded-lg bg-white p-4 text-sm shadow-sm" @submit.prevent="apply">
                <label>From (UTC)<input v-model="start" type="date" class="ml-2 rounded-md border-gray-300" /></label>
                <label>To (UTC)<input v-model="end" type="date" class="ml-2 rounded-md border-gray-300" /></label>
                <button class="rounded-md bg-indigo-600 px-3 py-2 font-semibold text-white">Show</button>
                <p class="text-gray-500">Days are UTC and follow Pixelforge's rule: a generation counts on the day it completed.</p>
            </form>

            <p v-if="usageError" class="rounded-md bg-amber-50 p-3 text-sm text-amber-800">
                Couldn't load Pixelforge usage to compare: {{ usageError }}
            </p>

            <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-left text-gray-600">
                        <tr>
                            <th class="px-4 py-2">Date</th>
                            <th class="px-4 py-2 text-right">Images</th>
                            <th class="px-4 py-2 text-right">Charged to users</th>
                            <th class="px-4 py-2 text-right">Provider cost (ours)</th>
                            <th class="px-4 py-2 text-right">Pixelforge /v1/usage</th>
                            <th class="px-4 py-2 text-right">Profit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="row in rows" :key="row.date">
                            <tr class="border-t" :class="row.users.length ? 'cursor-pointer hover:bg-gray-50' : ''" @click="open[row.date] = !open[row.date]">
                                <td class="px-4 py-2 font-medium">
                                    <span v-if="row.users.length">{{ open[row.date] ? '▾' : '▸' }}</span> {{ row.date }}
                                </td>
                                <td class="px-4 py-2 text-right">{{ row.generations }}</td>
                                <td class="px-4 py-2 text-right">{{ row.charged }}</td>
                                <td class="px-4 py-2 text-right font-mono">{{ row.cost }}</td>
                                <td class="px-4 py-2 text-right font-mono">
                                    <template v-if="row.pixelforge_cost !== null">
                                        {{ row.pixelforge_cost }}
                                        <span :class="row.matches ? 'text-green-600' : 'text-red-600'">{{ row.matches ? '✓' : '✗' }}</span>
                                    </template>
                                    <span v-else class="text-gray-400">n/a</span>
                                </td>
                                <td class="px-4 py-2 text-right font-semibold">{{ row.profit }}</td>
                            </tr>
                            <tr v-for="u in open[row.date] ? row.users : []" :key="row.date + u.user" class="bg-gray-50 text-gray-600">
                                <td class="px-8 py-1.5">{{ u.user }}</td>
                                <td class="px-4 py-1.5 text-right">{{ u.generations }}</td>
                                <td class="px-4 py-1.5 text-right">{{ u.charged }}</td>
                                <td class="px-4 py-1.5 text-right font-mono">{{ u.cost }}</td>
                                <td></td>
                                <td class="px-4 py-1.5 text-right">{{ u.profit }}</td>
                            </tr>
                        </template>
                    </tbody>
                    <tfoot class="border-t-2 bg-gray-50 font-semibold">
                        <tr>
                            <td class="px-4 py-2">Total</td>
                            <td class="px-4 py-2 text-right">{{ totals.generations }}</td>
                            <td class="px-4 py-2 text-right">{{ totals.charged }}</td>
                            <td class="px-4 py-2 text-right font-mono">{{ totals.cost }}</td>
                            <td class="px-4 py-2 text-right font-mono">
                                <template v-if="totals.pixelforge_cost !== null">
                                    {{ totals.pixelforge_cost }}
                                    <span :class="totals.matches ? 'text-green-600' : 'text-red-600'">{{ totals.matches ? '✓' : '✗' }}</span>
                                </template>
                            </td>
                            <td class="px-4 py-2 text-right">{{ totals.profit }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
