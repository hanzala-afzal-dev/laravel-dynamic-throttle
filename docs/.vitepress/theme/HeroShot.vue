<script setup lang="ts">
// The home page's hero image: the dashboard's screenshot from art/, which Vite bundles.
import shot from '@art/dashboard-light.png';
</script>

<template>
    <img
        class="hero-shot"
        :src="shot"
        width="1440"
        height="900"
        alt="The dashboard's Overview page: health, the rules, blocks and would-blocks in the last hour, a traffic chart, and the emergency controls."
    />
</template>
