import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { App } from '@/app';
import '@/styles.css';

const root = document.getElementById('dynamic-throttle');

if (root !== null) {
    createRoot(root).render(
        <StrictMode>
            <App />
        </StrictMode>,
    );
}
