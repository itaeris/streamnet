import { ref } from 'vue';

const theme = ref(document.documentElement.dataset.theme === 'light' ? 'light' : 'dark');

export function useTheme() {
    function setTheme(next) {
        theme.value = next === 'light' ? 'light' : 'dark';

        if (theme.value === 'light') {
            document.documentElement.dataset.theme = 'light';
        } else {
            delete document.documentElement.dataset.theme;
        }

        localStorage.setItem('theme', theme.value);
    }

    function toggleTheme() {
        setTheme(theme.value === 'light' ? 'dark' : 'light');
    }

    return { theme, setTheme, toggleTheme };
}
