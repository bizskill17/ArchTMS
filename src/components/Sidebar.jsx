import React, { useState, useEffect, useCallback } from 'react';
import { motion } from 'framer-motion';
import { 
  LayoutDashboard, CheckSquare, Users, Tag, MapPin, Building2, UserCheck, RotateCcw, FileText,
  Settings, Code, Menu, X, Briefcase, Clock, Columns, LogOut, CheckCircle
} from 'lucide-react';
import { supabase } from '@/lib/customSupabaseClient';

const Sidebar = ({ activeTab, setActiveTab, currentUser, onSignOut }) => {
  const [isOpen, setIsOpen] = useState(false);
  const [devUrl, setDevUrl] = useState('');

  const loadDevSettings = useCallback(async () => {
    const { data, error } = await supabase.from('tms_developer_settings').select('developer_url').limit(1).single();
    if (!error && data) {
      setDevUrl(data.developer_url);
    }
  }, []);

  useEffect(() => {
    loadDevSettings();
  }, [loadDevSettings]);

  const menuItems = [
    { id: 'dashboard', label: 'Dashboard', icon: LayoutDashboard, roles: ['Developer', 'Admin', 'Employee'] },
    { id: 'tasks', label: 'All Tasks', icon: CheckSquare, roles: ['Developer', 'Admin', 'Employee'] },
    { id: 'pending-tasks', label: 'Pending Tasks', icon: Clock, roles: ['Developer', 'Admin', 'Employee'] },
    { id: 'completed-tasks', label: 'Completed Tasks', icon: CheckCircle, roles: ['Developer', 'Admin', 'Employee'] },
   // { id: 'kanban', label: 'Kanban View', icon: Columns, roles: ['Developer', 'Admin', 'Employee'] },
    { id: 'users', label: 'Users', icon: Users, roles: ['Developer', 'Admin'] },
    { id: 'designations', label: 'Designations', icon: Briefcase, roles: ['Developer', 'Admin'] },
    { id: 'categories', label: 'Categories', icon: Tag, roles: ['Developer', 'Admin'] },
    { id: 'sites', label: 'Projects', icon: MapPin, roles: ['Developer', 'Admin'] },
   // { id: 'vendors', label: 'Vendors', icon: Building2, roles: ['Developer', 'Admin'] },
   // { id: 'vendor-tasks', label: 'Vendor Tasks', icon: UserCheck, roles: ['Developer', 'Admin', 'Employee'] },
   // { id: 'recurring', label: 'Recurring Tasks', icon: RotateCcw, roles: ['Developer', 'Admin'] },
    { id: 'action-log', label: 'Action Log', icon: FileText, roles: ['Developer', 'Admin'] },
    { id: 'admin-settings', label: 'Admin Settings', icon: Settings, roles: ['Developer', 'Admin'] },
    { id: 'developer-settings', label: 'Dev Settings', icon: Settings, roles: ['Developer'] },
    { id: 'schema', label: 'Schema', icon: Code, roles: ['Developer'] },
  ];

  const accessibleMenuItems = menuItems.filter(item => item.roles.includes(currentUser.role));

  const sidebarContent = (
    <div className="p-4 h-full flex flex-col">
      <div className="px-2 mb-8">
        <h1 className="text-2xl font-bold gradient-text">TaskPro</h1>
        <p className="text-sm text-gray-500">Automate with BizSkill</p>
      </div>
      <nav className="flex-1 space-y-2 overflow-y-auto pr-2">
        {accessibleMenuItems.map((item) => {
          const Icon = item.icon;
          return (
            <motion.button
              key={item.id}
              onClick={() => { setActiveTab(item.id); if (isOpen) setIsOpen(false); }}
              className={`w-full flex items-center space-x-3 px-4 py-3 rounded-lg transition-all duration-200 ${activeTab === item.id ? 'bg-gradient-to-r from-blue-500 to-purple-600 text-white shadow-lg' : 'text-gray-700 hover:bg-white/50 hover:shadow-md'}`}
              whileHover={{ scale: 1.02 }} whileTap={{ scale: 0.98 }}
            >
              <Icon className="w-5 h-5 flex-shrink-0" />
              <span className="font-medium truncate">{item.label}</span>
            </motion.button>
          );
        })}
      </nav>
      <div className="mt-auto pt-4 border-t border-gray-200/50">
        <div className="px-2 mb-4">
          <p className="font-semibold text-gray-800 truncate">{currentUser.name}</p>
          <p className="text-sm text-gray-500 truncate">{currentUser.email}</p>
        </div>
        <motion.button
          onClick={onSignOut}
          className="w-full flex items-center space-x-3 px-4 py-3 rounded-lg text-red-600 bg-red-50 hover:bg-red-100 transition-colors"
          whileHover={{ scale: 1.02 }} whileTap={{ scale: 0.98 }}
        >
          <LogOut className="w-5 h-5" />
          <span className="font-medium">Sign Out</span>
        </motion.button>
        <div className="mt-4 text-center text-xs text-gray-500">
          <motion.div whileHover={{ scale: 1.05 }} className="group">
            {devUrl ? (
              <a href={devUrl} target="_blank" rel="noopener noreferrer" className="font-semibold text-transparent bg-clip-text bg-gradient-to-r from-gray-500 to-gray-700 group-hover:from-blue-500 group-hover:to-purple-600 transition-colors">
                Developed by BizSkill
              </a>
            ) : (
              <span className="font-semibold text-transparent bg-clip-text bg-gradient-to-r from-gray-500 to-gray-700 group-hover:from-blue-500 group-hover:to-purple-600 transition-colors">
                Developed by BizSkill
              </span>
            )}
          </motion.div>
        </div>
      </div>
    </div>
  );

  return (
    <>
      <button onClick={() => setIsOpen(!isOpen)} className="lg:hidden fixed top-4 right-4 z-[60] p-2 rounded-md bg-white/50 backdrop-blur-sm">
        {isOpen ? <X className="w-6 h-6" /> : <Menu className="w-6 h-6" />}
      </button>
      <motion.div initial={{ x: '-100%' }} animate={{ x: isOpen ? 0 : '-100%' }} transition={{ type: 'spring', stiffness: 300, damping: 30 }} className="fixed inset-0 h-full w-64 glass-effect border-r border-white/20 z-50 lg:hidden">
        {sidebarContent}
      </motion.div>
      <motion.div className="fixed left-0 top-0 h-full w-64 glass-effect border-r border-white/20 z-40 hidden lg:block">
        {sidebarContent}
      </motion.div>
    </>
  );
};

export default Sidebar;