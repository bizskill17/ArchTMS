import React from 'react';
import ReactDOM from 'react-dom/client';
import AppWrapper from '@/AppWrapper';
import '@/index.css';
import { AuthProvider } from '@/contexts/SupabaseAuthContext';
import { BrowserRouter } from 'react-router-dom';

ReactDOM.createRoot(document.getElementById('root')).render(
  <React.StrictMode>
    <BrowserRouter>
      <AuthProvider>
        <AppWrapper />
      </AuthProvider>
    </BrowserRouter>
  </React.StrictMode>
);