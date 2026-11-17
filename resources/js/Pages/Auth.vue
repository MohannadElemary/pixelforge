<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
const props = defineProps({ register: Boolean });
const form = useForm({name: '', email: '', password: '', password_confirmation: '', remember: false});
const submit = () => form.post(props.register ? '/register' : '/login', { onFinish: () => form.reset('password', 'password_confirmation') });
</script>

<template>
    <Head :title="register ? 'Create an account' : 'Welcome back'" />
    <main class="auth-page">
        <section class="auth-story">
            <a href="/" class="brand"><span class="brand-mark">✳</span> pixelforge<span class="brand-dot">.</span></a>
            <div class="story-content">
                <span class="eyebrow">A LITTLE SPACE TO CREATE</span>
                <h1>Start with<br>an <em>idea.</em></h1>
                <p>A few words. A new perspective.<br>Your next image starts here.</p>
                <div class="abstract-art" aria-hidden="true"><div class="art-orbit"></div><div class="art-disc"></div><div class="art-line"></div><span>IMAGINATION, IN FRAME</span></div>
            </div>
            <div class="story-foot"><span>Made for your imagination.</span><span>01 — ∞</span></div>
        </section>
        <section class="auth-form-side">
            <div class="auth-form-wrap">
                <span class="eyebrow">YOUR CREATIVE WORKSPACE</span>
                <h2>{{ register ? 'A fresh beginning.' : 'Good to see you.' }}</h2>
                <p class="muted">{{ register ? 'Create an account and make something your own.' : 'Sign in to pick up where your imagination left off.' }}</p>
                <form @submit.prevent="submit" class="auth-form">
                    <label v-if="register">Name<input v-model="form.name" autocomplete="name" required maxlength="100" placeholder="Your name"><span class="field-error" v-if="form.errors.name">{{ form.errors.name }}</span></label>
                    <label>Email address<input v-model="form.email" type="email" autocomplete="email" required placeholder="you@example.com"><span class="field-error" v-if="form.errors.email">{{ form.errors.email }}</span></label>
                    <label>Password<input v-model="form.password" type="password" :autocomplete="register ? 'new-password' : 'current-password'" required :minlength="register ? 10 : undefined" :placeholder="register ? 'At least 10 characters' : 'Your password'"><span class="field-error" v-if="form.errors.password">{{ form.errors.password }}</span></label>
                    <label v-if="register">Confirm password<input v-model="form.password_confirmation" type="password" autocomplete="new-password" required placeholder="Once more, please"></label>
                    <label v-else class="checkbox-label"><input v-model="form.remember" type="checkbox"> Keep me signed in</label>
                    <button class="button primary" :disabled="form.processing">{{ form.processing ? 'One moment…' : register ? 'Create account' : 'Sign in' }}<span aria-hidden="true">↗</span></button>
                </form>
                <p class="auth-switch">{{ register ? 'Already have an account?' : 'New around here?' }} <Link :href="register ? '/login' : '/register'">{{ register ? 'Sign in' : 'Create an account' }}</Link></p>
            </div>
            <p class="auth-note">Your ideas and image history, all in one place.</p>
        </section>
    </main>
</template>
