import React, { createContext, useCallback, useContext, useState } from 'react';

// Minimal global toast system (replaces the demo's ad-hoc toast).
const ToastContext = createContext(null);

export function ToastProvider({ children }) {
    const [toasts, setToasts] = useState([]);

    const push = useCallback((message, type = 'info') => {
        const id = `${Date.now()}-${Math.round(performance.now())}`;
        setToasts((t) => [...t, { id, message, type }]);
        setTimeout(() => setToasts((t) => t.filter((x) => x.id !== id)), 2600);
    }, []);

    return (
        <ToastContext.Provider value={{ push }}>
            {children}
            <div className="toast-dock" aria-live="polite">
                {toasts.map((t) => (
                    <div key={t.id} className={`toast toast--${t.type}`}>{t.message}</div>
                ))}
            </div>
        </ToastContext.Provider>
    );
}

export function useToast() {
    return useContext(ToastContext) ?? { push: () => {} };
}
