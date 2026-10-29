<script setup>
defineProps({
    kind: { type: String, default: 'table' },
});
</script>

<template>
    <div class="flex flex-col gap-3" aria-busy="true">
        <p class="sr-only">Memuat halaman</p>

        <template v-if="kind === 'overview'">
            <section class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                <article v-for="card in 4" :key="card" class="noc-card space-y-3 p-3">
                    <div class="skeleton h-3 w-24" />
                    <div class="skeleton h-5 w-36" />
                    <div class="skeleton h-8 w-full" />
                </article>
            </section>
            <section class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-5">
                <article v-for="port in 5" :key="port" class="noc-card space-y-2 p-3">
                    <div class="skeleton h-3 w-16" />
                    <div class="skeleton h-4 w-24" />
                    <div class="skeleton h-3 w-20" />
                </article>
            </section>
            <section class="grid gap-3 xl:grid-cols-[1.45fr_0.7fr]">
                <article class="noc-card p-3">
                    <div class="skeleton h-4 w-40" />
                    <div class="skeleton mt-3 h-56 w-full" />
                </article>
                <article class="noc-card space-y-3 p-3">
                    <div v-for="row in 5" :key="row" class="skeleton h-8 w-full" />
                </article>
            </section>
            <article class="noc-card space-y-2 p-3">
                <div class="skeleton h-4 w-40" />
                <div v-for="row in 6" :key="row" class="skeleton h-8 w-full" />
            </article>
        </template>

        <template v-else-if="kind === 'chart'">
            <section class="grid gap-3 md:grid-cols-3">
                <article v-for="card in 3" :key="card" class="noc-card space-y-3 p-3">
                    <div class="skeleton h-3 w-20" />
                    <div class="skeleton h-6 w-28" />
                </article>
            </section>
            <article class="noc-card p-3">
                <div class="skeleton h-4 w-48" />
                <div class="skeleton mt-3 h-72 w-full" />
            </article>
            <section class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                <article v-for="card in 4" :key="card" class="noc-card space-y-2 p-3">
                    <div class="skeleton h-3 w-24" />
                    <div class="skeleton h-16 w-full" />
                </article>
            </section>
        </template>

        <template v-else-if="kind === 'settings'">
            <div class="skeleton h-9 w-full max-w-xl rounded-xl" />
            <article class="noc-card max-w-xl space-y-3 p-4">
                <div class="skeleton h-4 w-32" />
                <div class="skeleton h-10 w-full" />
                <div class="skeleton h-10 w-full" />
                <div class="skeleton h-10 w-28" />
            </article>
        </template>

        <template v-else>
            <div class="flex flex-wrap gap-2">
                <div class="skeleton h-8 w-56 rounded-xl" />
                <div class="skeleton h-8 w-28 rounded-full" />
            </div>
            <section class="grid gap-3 md:grid-cols-3">
                <article v-for="card in 3" :key="card" class="noc-card space-y-2 p-3">
                    <div class="skeleton h-3 w-20" />
                    <div class="skeleton h-5 w-16" />
                </article>
            </section>
            <article class="noc-card space-y-2 p-3">
                <div class="flex gap-3">
                    <div v-for="head in 5" :key="head" class="skeleton h-3 flex-1" />
                </div>
                <div v-for="row in 8" :key="row" class="skeleton h-9 w-full" />
            </article>
        </template>
    </div>
</template>
