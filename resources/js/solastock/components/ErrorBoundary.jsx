import React from 'react';
import { t } from '../i18n/index.js';

export default class ErrorBoundary extends React.Component {
    constructor(props) {
        super(props);
        this.state = { error: null };
    }

    static getDerivedStateFromError(error) {
        return { error };
    }

    render() {
        if (this.state.error) {
            return (
                <div className="error-screen">
                    <h1>{t('common.error.title')}</h1>
                    <p>{this.state.error.message}</p>
                    <button onClick={() => window.location.reload()}>{t('common.error.reload')}</button>
                </div>
            );
        }
        return this.props.children;
    }
}
