import React from 'react';
import { Link } from 'react-router-dom';
import { t } from '../i18n/index.js';
export default function NotFoundPage() {
    return <section className="page error-screen"><h1>404</h1><p>{t('errors.notFound')}</p><Link to="/dashboard">{t('errors.dashboard')}</Link></section>;
}
