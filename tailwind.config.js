import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',

    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.js',
    ],

    theme: {
        extend: {
            fontFamily: {
               sans: ['system-ui', 'sans-serif'],
            },

            colors: {
                // SPKD logo colours. "brand" (teal) is the primary colour; "navy" is for large dark
                // surfaces. Keep Tailwind's red for errors, destructive actions and negative statuses.
                brand: {
                    50: '#effafa',
                    100: '#d5f2f3',
                    200: '#afe4e7',
                    300: '#79cfd5',
                    400: '#3fb2bb',
                    500: '#1f969e',
                    600: '#1b7d86',
                    700: '#1b656d',
                    800: '#1d535a',
                    900: '#1c464c',
                    950: '#0c2d32',
                },

                navy: {
                    50: '#f0f4fa',
                    100: '#dde5f3',
                    200: '#c1d0ea',
                    300: '#97b0db',
                    400: '#6689c9',
                    500: '#4568b9',
                    600: '#344f9c',
                    700: '#2b407e',
                    800: '#223366',
                    900: '#17295a',
                    950: '#0f1a3b',
                },

                primary: {
                    DEFAULT: '#1B7D86',
                    light: '#1F969E',
                    dark: '#1B656D',
                },

                secondary: {
                    DEFAULT: '#FFFFFF',
                    dark: '#111827',
                },

                success: '#16A34A',

                danger: '#DC2626',

                warning: '#F59E0B',

                surface: '#F9FAFB',

                border: '#E5E7EB',
            },
        },
    },

    plugins: [forms],
};