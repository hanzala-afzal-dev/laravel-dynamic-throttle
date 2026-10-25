import DefaultTheme from 'vitepress/theme';
import { h } from 'vue';
import HeroShot from './HeroShot.vue';
import './custom.css';

export default {
    extends: DefaultTheme,
    Layout: () => h(DefaultTheme.Layout, null, { 'home-hero-image': () => h(HeroShot) }),
};
