<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

defineProps({ active: { type: String, default: 'studio' } });
const page = usePage();
const user = computed(() => page.props.user);
</script>

<template>
    <header class="topbar">
        <Link href="/studio" class="brand"
            ><span class="brand-mark" aria-hidden="true">✳</span> pixelforge<span class="brand-dot"
                >.</span
            ></Link
        >
        <nav class="topbar-nav" aria-label="Main navigation">
            <Link href="/studio" :aria-current="active === 'studio' ? 'page' : undefined"
                >Studio</Link
            >
            <Link
                v-if="user.is_admin"
                href="/admin/reports"
                :aria-current="active === 'reports' ? 'page' : undefined"
                >Reports</Link
            >
        </nav>
        <div class="account">
            <span class="avatar" aria-hidden="true">{{ user.name.slice(0, 1).toUpperCase() }}</span
            ><span class="account-name">{{ user.name }}</span
            ><Link href="/logout" method="post" as="button" class="signout">Sign out</Link>
        </div>
    </header>
</template>
