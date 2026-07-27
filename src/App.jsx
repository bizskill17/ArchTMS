import React, { useState, useEffect } from 'react';
import { Helmet } from 'react-helmet';
import { motion } from 'framer-motion';
import { Toaster } from '@/components/ui/toaster';
import Sidebar from '@/components/Sidebar';
import Dashboard from '@/components/Dashboard';
import TaskMaster from '@/components/TaskMaster';
import UserMaster from '@/components/UserMaster';
import CategoryMaster from '@/components/CategoryMaster';
import SiteMaster from '@/components/SiteMaster';
import VendorMaster from '@/components/VendorMaster';
import VendorTaskMaster from '@/components/VendorTaskMaster';
import RecurringTasks from '@/components/RecurringTasks';
import ActionLog from '@/components/ActionLog';
import AdminSettings from '@/components/AdminSettings';
import DeveloperSettings from '@/components/DeveloperSettings';
import Schema from '@/components/Schema';
import DesignationMaster from '@/components/DesignationMaster';
import PendingTasks from '@/components/PendingTasks';
import CompletedTasks from '@/components/CompletedTasks';
import KanbanView from '@/components/KanbanView';
import { AlertCircle, Loader } from 'lucide-react';
import { useAuth } from '@/contexts/SupabaseAuthContext';
import { supabase } from '@/lib/customSupabaseClient';
import { useToast } from '@/components/ui/use-toast';
import { Routes, Route, Navigate, useLocation, useNavigate } from 'react-router-dom';
import AuthPage from '@/components/AuthPage';
import ResetPasswordPage from '@/components/ResetPasswordPage';
import { initializeScheduler } from '@/lib/taskProcessor';

const ProtectedRoute = ({ children }) => {
  const { session, loading, isRecoveryFlow } = useAuth();
  const location = useLocation();

  if (loading) {
    return (
      <div className="flex items-center justify-center h-screen bg-gray-100">
        <Loader className="animate-spin text-blue-500 h-10 w-10" />
      </div>
    );
  }

  // If we are in a recovery flow, allow access to the reset page
  if (isRecoveryFlow && location.pathname === '/reset-password') {
    return children;
  }

  if (!session) {
    return <Navigate to="/auth" state={{ from: location }} replace />;
  }

  return children;
};

const MainLayout = () => {
  const { user, signOut } = useAuth();
  const location = useLocation();
  const navigate = useNavigate();

  const [activeTab, setActiveTab] = useState('dashboard');
  const [currentUser, setCurrentUser] = useState(null);
  const [isAppDisabled, setIsAppDisabled] = useState(false);
  const [disabledMessage, setDisabledMessage] = useState('');
  const [appSettings, setAppSettings] = useState({ orgName: '', logoUrl: '' });
  const [impersonatingUser, setImpersonatingUser] = useState(null);
  const [quickAction, setQuickAction] = useState(null);
  const { toast } = useToast();

  useEffect(() => {
    const params = new URLSearchParams(location.search);
    const tab = params.get('tab');
    if (tab) {
      setActiveTab(tab);
    }
  }, [location]);

  useEffect(() => {
    if (user) {
      const fetchUserDataAndSettings = async (retryCount = 0) => {
        const { data: userData, error: userError } = await supabase
          .from('tms_users')
          .select('*, tms_designations(name)')
          .eq('user_id', user.id)
          .maybeSingle();

        if (userError) {
          console.error('Error fetching user profile:', userError);
          toast({ title: "Error", description: "Could not fetch user profile.", variant: "destructive" });
          await signOut();
          return;
        }
        
        if (userData) {
          if (!userData.is_active) {
            toast({ title: "Account Inactive", description: "Your account is currently inactive. Please contact an administrator.", variant: "destructive" });
            await signOut();
            return;
          }
          setCurrentUser({
            id: userData.id,
            user_id: userData.user_id,
            name: userData.full_name,
            email: userData.email,
            role: userData.role,
            designation: userData.tms_designations?.name || 'N/A'
          });

          if(userData.role === 'Admin' || userData.role === 'Developer'){
            initializeScheduler();
          }

        } else {
          if (retryCount < 3) {
            setTimeout(() => fetchUserDataAndSettings(retryCount + 1), 1000);
          } else {
            console.warn("User authenticated but no profile found. Logging out.");
            toast({ title: "Profile Error", description: "User profile not found. Please contact support.", variant: "destructive" });
            await signOut();
          }
          return;
        }

        const { data: adminSettingsData, error: adminError } = await supabase.from('tms_admin_settings').select('*').limit(1).single();
        if (!adminError && adminSettingsData) {
          setAppSettings({ orgName: adminSettingsData.org_name, logoUrl: adminSettingsData.logo_url });
        }

        const { data: devSettingsData, error: devError } = await supabase.from('tms_developer_settings').select('*').limit(1).single();
        if (!devError && devSettingsData) {
          setIsAppDisabled(devSettingsData.is_app_disabled || false);
          setDisabledMessage(devSettingsData.disabled_message || 'This application is temporarily disabled by the developer.');
        }
      };
      fetchUserDataAndSettings();
    } else {
      setCurrentUser(null);
    }
  }, [user, signOut, toast]);

  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const action = params.get('action');
    const filterParam = params.get('filter');
    
    if (action) {
      let filter = {};
      try {
        if (filterParam) {
          filter = JSON.parse(decodeURIComponent(filterParam));
        }
      } catch (e) {
        console.error("Failed to parse filter from URL:", e);
      }
      
      setQuickAction({ type: action, filter: filter });
      
      const tabMap = {
        'new-task': 'tasks',
        'new-user': 'users',
        'filter-assignee': 'pending-tasks',
      };
      const targetTab = tabMap[action];

      if (targetTab && targetTab !== activeTab) {
        setActiveTab(targetTab);
      }
      
      navigate(location.pathname, { replace: true });
    }
  }, [location.search, navigate]);


  const handleImpersonation = (userToImpersonate) => {
    if (userToImpersonate) {
      setImpersonatingUser(currentUser);
      setCurrentUser(userToImpersonate);
    } else if (impersonatingUser) {
      setCurrentUser(impersonatingUser);
      setImpersonatingUser(null);
    }
  };

  const handleSetActiveTab = (tab) => {
    setActiveTab(tab);
    setQuickAction(null); // Clear quick action when changing tabs manually
  };
  
  if (!currentUser) {
    return (
      <div className="flex items-center justify-center h-screen bg-gray-100">
        <Loader className="animate-spin text-blue-500 h-10 w-10" />
        <p className="ml-4 text-gray-700">Loading user data...</p>
      </div>
    );
  }

  const renderContent = () => {
    if (isAppDisabled && currentUser.role !== 'Developer') {
      return (
        <div className="flex flex-col items-center justify-center h-full text-center">
          <AlertCircle className="w-16 h-16 text-red-500 mb-4" />
          <h1 className="text-2xl font-bold text-red-700">Application Disabled</h1>
          <p className="text-gray-600 mt-2">{disabledMessage}</p>
        </div>
      );
    }

    switch (activeTab) {
      case 'dashboard': return <Dashboard setActiveTab={handleSetActiveTab} currentUser={currentUser} navigate={navigate} />;
      case 'tasks': return <TaskMaster currentUser={currentUser} openForm={quickAction?.type === 'new-task'} onFormHandled={() => setQuickAction(null)} />;
      case 'pending-tasks': return <PendingTasks currentUser={currentUser} initialFilters={quickAction?.type === 'filter-assignee' ? quickAction.filter : null} />;
      case 'completed-tasks': return <CompletedTasks currentUser={currentUser} />;
      case 'kanban': return <KanbanView currentUser={currentUser} />;
      case 'users': return <UserMaster currentUser={currentUser} openForm={quickAction?.type === 'new-user'} onFormHandled={() => setQuickAction(null)} />;
      case 'designations': return <DesignationMaster currentUser={currentUser} />;
      case 'categories': return <CategoryMaster currentUser={currentUser} />;
      case 'sites': return <SiteMaster currentUser={currentUser} />;
      case 'vendors': return <VendorMaster currentUser={currentUser} />;
      case 'vendor-tasks': return <VendorTaskMaster currentUser={currentUser} />;
      case 'recurring': return <RecurringTasks currentUser={currentUser} />;
      case 'action-log': return <ActionLog currentUser={currentUser} />;
      case 'admin-settings': return <AdminSettings onUpdate={(settings) => setAppSettings({ orgName: settings.orgName, logoUrl: settings.logoUrl })} />;
      case 'developer-settings': return <DeveloperSettings onUpdate={(settings) => { setIsAppDisabled(settings.isAppDisabled); setDisabledMessage(settings.disabledMessage); }} onImpersonate={handleImpersonation} currentUser={currentUser} />;
      case 'schema': return <Schema />;
      default: return <Dashboard setActiveTab={handleSetActiveTab} currentUser={currentUser} navigate={navigate} />;
    }
  };

  return (
    <>
      {impersonatingUser && (
        <div className="fixed top-0 left-0 right-0 bg-yellow-400 text-black text-center p-2 z-[100]">
          You are viewing as <strong>{currentUser.name}</strong>. 
          <button onClick={() => handleImpersonation(null)} className="ml-4 font-bold underline">Return to Developer View</button>
        </div>
      )}
      <div className={`flex min-h-screen bg-gradient-to-br from-slate-50 via-blue-50 to-indigo-100 ${impersonatingUser ? 'pt-10' : ''}`}>
        <Sidebar activeTab={activeTab} setActiveTab={handleSetActiveTab} currentUser={currentUser} onSignOut={signOut} />
        <main className="flex-1 p-4 md:p-6 lg:ml-64">
          <header className="hidden lg:flex justify-end items-center mb-4 h-12">
            {appSettings.orgName && <span className="font-semibold text-gray-700 mr-4">{appSettings.orgName}</span>}
            {appSettings.logoUrl && <img src={appSettings.logoUrl} alt="Organization Logo" className="h-10 w-auto object-contain" />}
          </header>
          <motion.div key={activeTab} initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.3 }} className="max-w-full mx-auto">
            {renderContent()}
          </motion.div>
        </main>
      </div>
    </>
  );
};

function App() {
  const { session, loading, isRecoveryFlow } = useAuth();
  const location = useLocation();

  return (
    <>
      <Helmet>
        <title>TaskPro - Automate with BizSkill</title>
        <meta name="description" content="Comprehensive task management system with user tracking, vendor management, and automated recurring tasks" />
      </Helmet>
      <Routes>
        <Route path="/auth" element={!loading && session && !isRecoveryFlow ? <Navigate to="/" /> : <AuthPage />} />
        <Route path="/reset-password" element={<ResetPasswordPage />} />
        <Route path="/*" element={<ProtectedRoute><MainLayout /></ProtectedRoute>} />
      </Routes>
      <Toaster />
    </>
  );
}

export default App;