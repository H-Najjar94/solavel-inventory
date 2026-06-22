import React from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { RouterProvider } from 'react-router-dom';
import { router } from './router/router.jsx';
import { MetaProvider } from './stores/meta.jsx';
import { TenantProvider } from './stores/tenant.jsx';
import { ToastProvider } from './stores/toast.jsx';
import { applyTheme, getTheme } from './stores/theme.js';
import ErrorBoundary from './components/ErrorBoundary.jsx';
import './styles/solastock.css';

// Apply persisted theme before first paint (no flash).
applyTheme(getTheme());

const queryClient = new QueryClient();

const el = document.getElementById('solastock-root');
if (el) {
    createRoot(el).render(
        <React.StrictMode>
            <ErrorBoundary>
                <QueryClientProvider client={queryClient}>
                    <TenantProvider>
                        <MetaProvider>
                            <ToastProvider>
                                <RouterProvider router={router} />
                            </ToastProvider>
                        </MetaProvider>
                    </TenantProvider>
                </QueryClientProvider>
            </ErrorBoundary>
        </React.StrictMode>
    );
}
