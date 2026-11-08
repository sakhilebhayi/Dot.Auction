import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';
import typography from '@tailwindcss/typography';

/**
 * Tailwind stays for layout utilities and for Jetstream's stock markup, but it owns no colour of its own: every scale below
 * resolves to a CSS variable from resources/css/tokens.css (the single source), so `bg-gray-800`, `text-indigo-600` or
 * `dark:bg-gray-900` in a Jetstream view follow the Chalk/Graphite theme instead of Tailwind's palette.
 */
const token = (name) => ({ opacityValue }) =>
    opacityValue === undefined || opacityValue === '1'
        ? `var(${name})`
        : `color-mix(in srgb, var(${name}) calc(${opacityValue} * 100%), transparent)`;

const ramp = (map) => Object.fromEntries(Object.entries(map).map(([step, name]) => [step, token(name)]));

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './vendor/laravel/jetstream/**/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    darkMode: 'class',

    theme: {
        extend: {
            colors: {
                white: token('--n-0'),
                black: token('--n-950'),
                gray: ramp({
                    50: '--n-50', 100: '--n-100', 200: '--n-200', 300: '--n-300', 400: '--n-400',
                    500: '--n-500', 600: '--n-600', 700: '--n-700', 800: '--n-800', 900: '--n-900', 950: '--n-950',
                }),
                indigo: ramp({
                    50: '--blue-100', 100: '--blue-100', 200: '--blue-300', 300: '--blue-300', 400: '--blue-300',
                    500: '--blue-600', 600: '--blue-600', 700: '--blue-700', 800: '--blue-700', 900: '--blue-900',
                }),
                red: ramp({
                    50: '--red-100', 100: '--red-100', 200: '--red-300', 300: '--red-300', 400: '--red-300',
                    500: '--red-500', 600: '--red-600', 700: '--red-700', 800: '--red-700', 900: '--red-900',
                }),
                green: ramp({
                    50: '--teal-100', 100: '--teal-100', 200: '--teal-300', 300: '--teal-300', 400: '--teal-300',
                    500: '--teal-600', 600: '--teal-600', 700: '--teal-700', 800: '--teal-700', 900: '--teal-900',
                }),
                yellow: ramp({
                    50: '--gold-100', 100: '--gold-100', 200: '--gold-300', 300: '--gold-300', 400: '--gold-500',
                    500: '--gold-500', 600: '--gold-600', 700: '--gold-700', 800: '--gold-700', 900: '--gold-900',
                }),
                // Semantic roles for new markup. These switch with the theme.
                ground: token('--c-ground'),
                surface: token('--c-surface'),
                raised: token('--c-raised'),
                sunken: token('--c-sunken'),
                ink: { DEFAULT: token('--c-ink'), 2: token('--c-ink-2'), 3: token('--c-ink-3') },
                line: { DEFAULT: token('--c-line'), strong: token('--c-line-strong') },
                action: token('--c-action'),
            },
            fontFamily: {
                sans: ['var(--f-ui)', ...defaultTheme.fontFamily.sans],
                mono: ['var(--f-mono)', ...defaultTheme.fontFamily.mono],
            },
        },
    },

    plugins: [forms, typography],
};
