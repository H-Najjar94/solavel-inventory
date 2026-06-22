import React from 'react';
import { Link } from 'react-router-dom';
export default function NotFoundPage() {
    return <section className="page error-screen"><h1>404</h1><p>Page not found.</p><Link to="/dashboard">Go to dashboard</Link></section>;
}
