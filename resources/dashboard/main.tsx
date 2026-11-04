import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { setNonce } from 'get-nonce';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { Router } from 'wouter';
import { App } from '@/app';
import { configureApi } from '@/lib/api';
import { takeLinkToken } from '@/lib/link';
import { applyTheme, storedTheme } from '@/lib/theme';
import '@/styles.css';

const root = document.getElementById('dynamic-throttle');

if (root !== null) {
    const base = root.dataset.base ?? '';
    // First of all: a link's token leaves the address bar before anything else runs (SPEC-034 AC-21).
    const linkToken = takeLinkToken(base);
    configureApi({ base });

    // Libraries that insert a <style> element give it the page's nonce, which the CSP allows (SPEC-020 decision 3).
    const nonce = document.querySelector<HTMLMetaElement>('meta[name="csp-nonce"]')?.content;

    if (nonce !== undefined && nonce !== '') {
        setNonce(nonce);
    }

    applyTheme(storedTheme());

    const client = new QueryClient({ defaultOptions: { queries: { retry: 1 } } });

    createRoot(root).render(
        <StrictMode>
            <QueryClientProvider client={client}>
                <Router base={base}>
                    <App linkToken={linkToken} />
                </Router>
            </QueryClientProvider>
        </StrictMode>,
    );
}
